<?php

declare(strict_types=1);

namespace kernpfad\commercedoofinder\services;

/**
 * Pure construction of Doofinder item payloads (verified against
 * https://docs.doofinder.com/api-reference/items/create.md). One Doofinder
 * item per Commerce variant — `group_id` is the parent product's ID and
 * `group_leader` marks which variant Doofinder shows in results, the same
 * grouping convention Doofinder's own docs describe for product variants.
 * Framework-free so it's unit-testable without a Doofinder client or Craft
 * boot.
 */
class ItemPayloadBuilder
{
    /**
     * @param bool|null $availability whether the variant can currently be
     *   purchased (already accounts for enabled/draft/out-of-stock status —
     *   see {@see \craft\commerce\base\Purchasable::getIsAvailable()}). Not
     *   one of Doofinder's reserved fields, sent as a plain custom field;
     *   null omits it.
     * @param int|null $stockQuantity available stock across all inventory
     *   locations for the current store. Pass null (not `0`) for
     *   inventory-untracked variants — those aren't meaningfully "0 in
     *   stock", and a literal 0 would misrepresent them as out of stock.
     *   Not one of Doofinder's reserved fields, sent as a plain custom
     *   field; null omits it.
     * @param array<string, mixed> $customFields already resolved from the
     *   merchant's field mapping — merged in as-is, since Doofinder items
     *   accept arbitrary extra keys beyond the reserved ones below.
     * @return array<string, mixed>
     */
    public function buildItem(
        string $id,
        string $title,
        string $link,
        ?string $imageLink,
        float $price,
        ?float $salePrice,
        string $groupId,
        bool $groupLeader,
        ?bool $availability = null,
        ?int $stockQuantity = null,
        array $customFields = [],
    ): array {
        $item = [
            'id' => $id,
            'title' => $title,
            'link' => $link,
            'price' => $price,
            'group_id' => $groupId,
            'group_leader' => $groupLeader,
        ];

        if ($imageLink !== null && $imageLink !== '') {
            $item['image_link'] = $imageLink;
        }

        if ($salePrice !== null && $salePrice < $price) {
            $item['sale_price'] = $salePrice;
        }

        if ($availability !== null) {
            $item['availability'] = $availability;
        }

        if ($stockQuantity !== null) {
            $item['stock_quantity'] = $stockQuantity;
        }

        return array_merge($item, $customFields);
    }
}
