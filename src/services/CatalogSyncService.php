<?php

namespace fipschen95\commercedoofinder\services;

use craft\base\Element;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\helpers\Queue;
use fipschen95\commercedoofinder\jobs\DeleteProductItemsJob;
use fipschen95\commercedoofinder\jobs\SyncProductItemsJob;
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
 * `image_link` is deliberately never set automatically — like
 * commerce-klaviyo's `image_full_url`, product images are always a
 * project-specific custom field in Commerce's schema, not something this
 * plugin can read without guessing at a project's field setup. Map an
 * asset field's URL through `$fieldMapping` if you need it populated.
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

    public function syncVariant(Variant $variant): void
    {
        if (!$this->isSyncable($variant)) {
            return;
        }

        $product = $variant->getProduct();

        if ($product === null || !$this->isSyncable($product)) {
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

        Queue::push(new DeleteProductItemsJob([
            'productTitle' => $variant->getProduct()?->title ?? '',
            'variantIds' => [(string)$variant->id],
        ]), queue: $this->queue);
    }

    public function deleteProduct(Product $product): void
    {
        if (!$this->isSyncable($product)) {
            return;
        }

        $variantIds = [];

        foreach ($product->getVariants() as $variant) {
            if ($variant->id !== null) {
                $variantIds[] = (string)$variant->id;
            }
        }

        if ($variantIds === []) {
            return;
        }

        Queue::push(new DeleteProductItemsJob([
            'productTitle' => $product->title ?? '',
            'variantIds' => $variantIds,
        ]), queue: $this->queue);
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
        if ($product->id === null) {
            return [];
        }

        $variantPayloads = [];

        foreach ($product->getVariants() as $variant) {
            if ($variant->id === null) {
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
            (string)$variant->id,
            $variant->title ?: ($product->title ?? ''),
            $product->getUrl() ?? '',
            null,
            (float)($variant->getPrice() ?? 0.0),
            $variant->getPromotionalPrice(),
            (string)$product->id,
            $variant->id === $product->getDefaultVariant()?->id,
            $this->resolveCustomFields($product),
        );
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
}
