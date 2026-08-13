<?php

declare(strict_types=1);

namespace kernpfad\commercedoofinder\services;

use craft\commerce\elements\Product;
use craft\elements\Category;
use craft\elements\db\CategoryQuery;
use craft\fields\Categories;

/**
 * Resolves Doofinder `categories` paths from a Commerce product — either
 * from a merchant-configured Categories field handle or by auto-discovering
 * the first Categories field on the product's field layout.
 */
class CategoryResolver
{
    public function __construct(
        private readonly ?string $fieldHandle = null,
        private readonly bool $autoDiscover = false,
        private readonly CategoryPathBuilder $pathBuilder = new CategoryPathBuilder(),
    ) {
    }

    /**
     * @return string[]|null paths to merge into the item payload, or null to omit
     */
    public function resolveForProduct(Product $product): ?array
    {
        $handle = $this->fieldHandle;

        if (($handle === null || $handle === '') && $this->autoDiscover) {
            $handle = $this->discoverCategoriesFieldHandle($product);
        }

        if ($handle === null || $handle === '') {
            return null;
        }

        $paths = $this->pathBuilder->buildPaths($this->loadCategories($product, $handle));

        return $paths === [] ? null : $paths;
    }

    private function discoverCategoriesFieldHandle(Product $product): ?string
    {
        $fieldLayout = $product->getFieldLayout();

        if ($fieldLayout === null) {
            return null;
        }

        foreach ($fieldLayout->getCustomFields() as $field) {
            if ($field instanceof Categories) {
                return $field->handle;
            }
        }

        return null;
    }

    /**
     * @return Category[]
     */
    private function loadCategories(Product $product, string $handle): array
    {
        $value = $product->getFieldValue($handle);

        if ($value instanceof CategoryQuery) {
            /** @var Category[] $categories */
            $categories = $value->all();

            return $categories;
        }

        if (!is_iterable($value)) {
            return [];
        }

        $categories = [];

        foreach ($value as $item) {
            if ($item instanceof Category) {
                $categories[] = $item;
            }
        }

        return $categories;
    }
}
