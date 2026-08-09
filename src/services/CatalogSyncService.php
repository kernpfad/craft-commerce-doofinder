<?php

declare(strict_types=1);

namespace kernpfad\commercedoofinder\services;

use craft\base\Element;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\helpers\Queue;
use kernpfad\commercedoofinder\jobs\DeleteProductItemsJob;
use kernpfad\commercedoofinder\jobs\SyncProductItemsJob;
use yii\base\Component;
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
    /**
     * @param array<string, string> $fieldMapping craftFieldHandle => doofinderFieldKey
     */
    public function __construct(
        private readonly ItemPayloadBuilder $payloadBuilder = new ItemPayloadBuilder(),
        private readonly FieldMapper $fieldMapper = new FieldMapper(),
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
     */
    private function isEnabledForIndex(Product $product, Variant $variant): bool
    {
        $siteId = $product->siteId;

        return $product->getEnabledForSite($siteId) && $variant->getEnabledForSite($siteId);
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

        if (!$this->isEnabledForIndex($product, $variant)) {
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
     * When a whole product is disabled, Commerce saves the product element
     * but does not necessarily fire a separate Variant::EVENT_AFTER_SAVE for
     * every variant — so each variant's item must be removed here.
     */
    public function removeDisabledProductFromIndex(Product $product): void
    {
        if (!$this->isSyncable($product) || $product->getEnabledForSite($product->siteId)) {
            return;
        }

        $this->queueVariantIdsDeletion($product->title ?? '', $this->collectVariantIds($product));
    }

    /**
     * One Doofinder item payload per variant of `$product`, read directly
     * (not queued) — used by the full-reindex console command, which reads
     * already-saved products fresh from the database (not reacting to an
     * in-flight save), so every variant's ID is reliably already there.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildVariantPayloads(Product $product): array
    {
        if ($product->id === null || !$product->getEnabledForSite($product->siteId)) {
            return [];
        }

        $variantPayloads = [];

        foreach ($product->getVariants() as $variant) {
            if ($variant->id === null || !$this->isEnabledForIndex($product, $variant)) {
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
        return $this->payloadBuilder->buildItem(
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
            customFields: $this->resolveCustomFields($product),
        );
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
     */
    private function firstAssetFromField(Element $element): ?Asset
    {
        $value = $element->getFieldValue((string)$this->imageFieldHandle);

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
     * @return array<string, mixed>
     */
    private function resolveCustomFields(Product $product): array
    {
        $fieldValues = [];

        foreach (array_keys($this->fieldMapping) as $fieldHandle) {
            $fieldValues[$fieldHandle] = $product->getFieldValue($fieldHandle);
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
