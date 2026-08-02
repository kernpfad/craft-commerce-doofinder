<?php

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

        return array_merge($item, $customFields);
    }
}
