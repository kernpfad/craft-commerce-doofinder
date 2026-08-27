<?php

declare(strict_types=1);

namespace kernpfad\commercedoofinder\services;

use craft\base\Element;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\helpers\Queue;
use kernpfad\commercedoofinder\CommerceDoofinder;
use kernpfad\commercedoofinder\events\ModifyItemPayloadEvent;
use kernpfad\commercedoofinder\jobs\DeleteProductItemsJob;
use kernpfad\commercedoofinder\jobs\SyncProductItemsJob;
use yii\base\Component;
use yii\base\Event;
use yii\queue\Queue as YiiQueue;

/**
 * Builds Doofinder item payloads from real Product/Variant data and queues
 * the sync — one Doofinder item per Craft Commerce variant, grouped under
 * the parent product via `group_id`/`group_leader`. External IDs are the
 * Craft variant element IDs themselves (stable, already unique).
 *
 * Syncing happens on `Variant::EVENT_AFTER_SAVE`, not `Product::EVENT_AFTER_SAVE`
 * — verified against a real save (not assumed): at the moment a Product's
 * own `EVENT_AFTER_SAVE` fires, its variants haven't been persisted yet
 * (`$variant->id` is still null, and a fresh DB query for them returns
 * nothing), because Commerce saves a product's variants as their own,
 * separate element saves *after* the product's own save completes. Each
 * variant's *own* `EVENT_AFTER_SAVE` fires later, with its ID populated and
 * `getProduct()` available — the reliable point to build its item payload.
 * (Commerce Klaviyo's `CatalogSyncService` has this same
 * variants-not-saved-yet gap on `Product::EVENT_AFTER_SAVE` — a pre-existing
 * issue in that plugin, not fixed here.)
 *
 * `image_link` is resolved from `$imageFieldHandle` when configured — the
 * variant's own field value is checked first, falling back to the product's,
 * so a project can override the image per-variant or just set it once on the
 * product. Left null (omitted from the payload) when unconfigured, since
 * this plugin can't guess a project's Assets field handle.
 */
class CatalogSyncService extends Component
{
    private const ELEMENT_STATUS_LIVE = 'live';

    /**
     * @param array<string, string> $fieldMapping craftFieldHandle => doofinderFieldKey
     */
    public function __construct(
        private readonly ItemPayloadBuilder $payloadBuilder = new ItemPayloadBuilder(),
        private readonly FieldMapper $fieldMapper = new FieldMapper(),
        private readonly CategoryResolver $categoryResolver = new CategoryResolver(),
        private readonly array $fieldMapping = [],
        private readonly ?string $imageFieldHandle = null,
        private readonly ?string $imageTransformHandle = null,
        private readonly ?YiiQueue $queue = null,
        $config = [],
    ) {
        parent::__construct($config);
    }

    /**
     * Craft fires `EVENT_AFTER_SAVE`/`EVENT_AFTER_DELETE` for drafts and
     * revisions too, and those are separate elements with their own IDs —
     * verified against a real save, not assumed. Since this plugin uses the
     * Craft element ID as Doofinder's item `id`, indexing them would put
     * entries in the merchant's live search index that no customer can ever
     * buy: a single CP publish creates a revision, so an actively-edited
     * store would accumulate one junk indexed item per edit, forever
     * (nothing ever removes them — deletion only fires for real products),
     * each one showing up in real customer search results.
     *
     * Propagation saves are skipped as well: on a multi-site install the
     * canonical save already indexed that exact element ID, so re-indexing
     * per-site is a duplicate API call for identical data.
     */
    private function isSyncable(Element $element): bool
    {
        return $element->id !== null
            && !$element->getIsDraft()
            && !$element->getIsRevision()
            && !$element->propagating;
    }

    /**
     * Whether a product/variant pair should appear in the live Doofinder
     * index for its site. Disabled elements stay syncable (they still fire
     * save events) but must be removed from the index instead of upserted.
     *
     * Mirrors `Element::getStatus()`'s own definition of "enabled", which
     * checks both flags: `enabled` is the global toggle (what a single-site
     * store's product edit page actually writes), while `getEnabledForSite()`
     * is the separate per-site override. Checking only the latter misses
     * every single-site "disable this product" case entirely.
     */
    private function isEnabledForIndex(Product $product, Variant $variant): bool
    {
        $siteId = $product->siteId;

        return $product->enabled
            && $product->getEnabledForSite($siteId)
            && $variant->enabled
            && $variant->getEnabledForSite($siteId);
    }

    /**
     * Whether a product/variant should be upserted into the live index right
     * now — enabled *and* live (not pending a future post date or past its
     * expiry date). Mirrors Craft's own {@see Element::getStatus()} rules.
     *
     * Only the *product's* status is checked against `live` — verified
     * against a real save that `Variant::getStatus()` returns `enabled`,
     * never `live`: unlike `Product`, `Variant` has no post/expiry date of
     * its own and never reports a `live`/`pending`/`expired` status, only
     * `enabled`/`disabled` (already covered by {@see isEnabledForIndex()}).
     * Checking a variant's status against the literal string `live` here
     * previously made every variant "not indexable" unconditionally — real-time
     * sync and `reindex` both silently upserted nothing, ever, for any
     * catalog, regardless of configuration.
     */
    private function isIndexable(Product $product, Variant $variant): bool
    {
        return $this->isEnabledForIndex($product, $variant)
            && $this->isLiveForIndex($product);
    }

    private function isLiveForIndex(Element $element): bool
    {
        return $element->getStatus() === self::ELEMENT_STATUS_LIVE;
    }

    public function syncVariant(Variant $variant): void
    {
        if (!$this->isSyncable($variant)) {
            return;
        }

        $product = $variant->getProduct();

        if ($product === null || !$this->isSyncable($product)) {
            return;
        }

        if (!$this->isIndexable($product, $variant)) {
            $this->queueVariantDeletion($variant, $product);

            return;
        }

        Queue::push(new SyncProductItemsJob([
            'productTitle' => $product->title ?? '',
            'variantPayloads' => [$this->buildVariantPayload($product, $variant)],
        ]), queue: $this->queue);
    }

    public function deleteVariant(Variant $variant): void
    {
        // Same guard, load-bearing here too: Craft prunes superseded
        // revisions in the background, firing this very event with revision
        // elements.
        if (!$this->isSyncable($variant)) {
            return;
        }

        $this->queueVariantDeletion($variant, $variant->getProduct());
    }

    public function deleteProduct(Product $product): void
    {
        if (!$this->isSyncable($product)) {
            return;
        }

        $this->queueVariantIdsDeletion($product->title ?? '', $this->collectVariantIds($product));
    }

    /**
     * Removes every variant when the product itself is disabled, pending or
     * expired. Commerce saves the product element without necessarily firing
     * a separate Variant::EVENT_AFTER_SAVE per variant — the same gap as for
     * disabled products. Live variants are synced by their own save events.
     */
    public function syncProductPublishState(Product $product): void
    {
        if (!$this->isSyncable($product)) {
            return;
        }

        $siteId = $product->siteId;
        $productEnabled = $product->enabled && $product->getEnabledForSite($siteId);

        if (!$productEnabled || !$this->isLiveForIndex($product)) {
            $this->queueVariantIdsDeletion($product->title ?? '', $this->collectVariantIds($product));
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildVariantPayloads(Product $product): array
    {
        if (
            $product->id === null
            || !$product->enabled
            || !$product->getEnabledForSite($product->siteId)
            || !$this->isLiveForIndex($product)
        ) {
            return [];
        }

        $variantPayloads = [];

        foreach ($product->getVariants() as $variant) {
            if ($variant->id === null || !$this->isIndexable($product, $variant)) {
                continue;
            }

            $variantPayloads[] = $this->buildVariantPayload($product, $variant);
        }

        return $variantPayloads;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildVariantPayload(Product $product, Variant $variant): array
    {
        $payload = $this->payloadBuilder->buildItem(
            id: (string)$variant->id,
            title: $variant->title ?: ($product->title ?? ''),
            link: $product->getUrl() ?? '',
            imageLink: $this->resolveImageLink($product, $variant),
            price: (float)($variant->getPrice() ?? 0.0),
            salePrice: $variant->getPromotionalPrice(),
            groupId: (string)$product->id,
            groupLeader: $variant->id === $product->getDefaultVariant()?->id,
            availability: $variant->getIsAvailable(),
            stockQuantity: $variant->inventoryTracked ? $variant->getStock() : null,
            categories: $this->categoryResolver->resolveForProduct($product),
            customFields: $this->resolveCustomFields($product),
        );

        return $this->modifyPayload($payload, $product, $variant);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function modifyPayload(array $payload, Product $product, Variant $variant): array
    {
        if (!Event::hasHandlers(CommerceDoofinder::class, CommerceDoofinder::EVENT_MODIFY_ITEM_PAYLOAD)) {
            return $payload;
        }

        $event = new ModifyItemPayloadEvent([
            'payload' => $payload,
            'product' => $product,
            'variant' => $variant,
        ]);

        Event::trigger(CommerceDoofinder::class, CommerceDoofinder::EVENT_MODIFY_ITEM_PAYLOAD, $event);

        return $event->payload;
    }

    /**
     * Resolves `image_link` from {@see $imageFieldHandle} — the variant's
     * own field value first, falling back to the product's, so a project can
     * override the image per-variant or just set it once on the product.
     * Applies {@see $imageTransformHandle} when configured.
     */
    private function resolveImageLink(Product $product, Variant $variant): ?string
    {
        if ($this->imageFieldHandle === null || $this->imageFieldHandle === '') {
            return null;
        }

        $asset = $this->firstAssetFromField($variant) ?? $this->firstAssetFromField($product);

        if ($asset === null) {
            return null;
        }

        if ($this->imageTransformHandle !== null && $this->imageTransformHandle !== '') {
            $url = $asset->getUrl($this->imageTransformHandle);

            if ($url !== null && $url !== '') {
                return $url;
            }
        }

        return $asset->getUrl() ?: null;
    }

    /**
     * `getFieldValue()` normally returns an `AssetQuery` for an Assets
     * field, but eager-loading (`Element::getEagerLoadedElements()`) can
     * hand back an already-resolved iterable of elements instead — handled
     * here too so a future eager-loading optimization elsewhere doesn't
     * silently turn every `image_link` into a no-op.
     *
     * Checks the field layout first rather than calling `getFieldValue()`
     * unconditionally: `Element::getFieldValue()` throws when the handle
     * isn't part of *that* element's own field layout, and this method is
     * deliberately called against the variant before falling back to the
     * product — verified against a real save that a product-only image field
     * (the plugin's own documented "just set it once on the product" setup)
     * previously crashed every sync for that variant instead of falling
     * through to the product as intended.
     */
    private function firstAssetFromField(Element $element): ?Asset
    {
        $handle = (string)$this->imageFieldHandle;

        if ($element->getFieldLayout()?->getFieldByHandle($handle) === null) {
            return null;
        }

        $value = $element->getFieldValue($handle);

        if ($value instanceof AssetQuery) {
            $value = $value->one();
        } elseif (is_iterable($value)) {
            foreach ($value as $item) {
                $value = $item;
                break;
            }
        }

        return $value instanceof Asset ? $value : null;
    }

    /**
     * Same guard as {@see firstAssetFromField()}: `getFieldValue()` throws
     * for a handle outside *this* product's own field layout, and a merchant
     * can map a field handle here that only some of their product types
     * actually carry. A missing field maps to `null`, which
     * `FieldMapper::mapFields()` already treats the same as a present-but-empty
     * one — simply omitted from the payload.
     *
     * @return array<string, mixed>
     */
    private function resolveCustomFields(Product $product): array
    {
        $layout = $product->getFieldLayout();
        $fieldValues = [];

        foreach (array_keys($this->fieldMapping) as $fieldHandle) {
            $fieldValues[$fieldHandle] = $layout?->getFieldByHandle($fieldHandle) === null
                ? null
                : $product->getFieldValue($fieldHandle);
        }

        return $this->fieldMapper->mapFields($this->fieldMapping, $fieldValues);
    }

    private function queueVariantDeletion(Variant $variant, ?Product $product): void
    {
        if ($variant->id === null) {
            return;
        }

        $productTitle = $product === null ? '' : ($product->title ?? '');

        $this->queueVariantIdsDeletion($productTitle, [(string)$variant->id]);
    }

    /**
     * @param string[] $variantIds
     */
    private function queueVariantIdsDeletion(string $productTitle, array $variantIds): void
    {
        if ($variantIds === []) {
            return;
        }

        Queue::push(new DeleteProductItemsJob([
            'productTitle' => $productTitle,
            'variantIds' => $variantIds,
        ]), queue: $this->queue);
    }

    /**
     * @return string[]
     */
    private function collectVariantIds(Product $product): array
    {
        $variantIds = [];

        foreach ($product->getVariants() as $variant) {
            if ($variant->id !== null) {
                $variantIds[] = (string)$variant->id;
            }
        }

        return $variantIds;
    }
}
