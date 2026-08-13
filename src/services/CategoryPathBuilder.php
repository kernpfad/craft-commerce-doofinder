<?php

declare(strict_types=1);

namespace kernpfad\commercedoofinder\services;

use craft\elements\Category;

/**
 * Builds Doofinder `categories` breadcrumb paths from Craft category
 * elements — each path is `Parent > Child > Leaf`, as required by
 * Doofinder's product index preset
 * (https://docs.doofinder.com/api-reference/items/create).
 */
class CategoryPathBuilder
{
    /**
     * @param iterable<Category> $categories
     * @return string[]
     */
    public function buildPaths(iterable $categories): array
    {
        $paths = [];

        foreach ($categories as $category) {
            $path = $this->buildPath($category);

            if ($path !== null && $path !== '') {
                $paths[] = $path;
            }
        }

        return array_values(array_unique($paths));
    }

    public function buildPath(Category $category): ?string
    {
        $parts = [];

        foreach ($category->getAncestors()->all() as $ancestor) {
            $title = $ancestor->title;

            if ($title !== null && $title !== '') {
                $parts[] = $title;
            }
        }

        $title = $category->title;

        if ($title !== null && $title !== '') {
            $parts[] = $title;
        }

        if ($parts === []) {
            return null;
        }

        return implode(' > ', $parts);
    }
}
