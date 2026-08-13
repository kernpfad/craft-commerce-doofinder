<?php

declare(strict_types=1);

namespace kernpfad\commercedoofinder\tests\unit;

use craft\elements\Category;
use craft\elements\db\CategoryQuery;
use kernpfad\commercedoofinder\services\CategoryPathBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CategoryPathBuilderTest extends TestCase
{
    private CategoryPathBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new CategoryPathBuilder();
    }

    public function testBuildsAncestorBreadcrumbPaths(): void
    {
        $parent = $this->categoryWithAncestors('Clothes', []);
        $child = $this->categoryWithAncestors('Hoodies', [$parent]);

        self::assertSame('Clothes > Hoodies', $this->builder->buildPath($child));
    }

    public function testBuildsPathsForMultipleCategories(): void
    {
        $first = $this->categoryWithAncestors('Men', []);
        $second = $this->categoryWithAncestors('Sale', []);

        self::assertSame(['Men', 'Sale'], $this->builder->buildPaths([$first, $second]));
    }

    public function testDeduplicatesIdenticalPaths(): void
    {
        $category = $this->categoryWithAncestors('Shirts', []);

        self::assertSame(['Shirts'], $this->builder->buildPaths([$category, $category]));
    }

    /**
     * @param Category[] $ancestors
     * @return Category&MockObject
     */
    private function categoryWithAncestors(string $title, array $ancestors): Category
    {
        $category = $this->createMock(Category::class);
        $category->title = $title;

        $ancestorQuery = $this->createMock(CategoryQuery::class);
        $ancestorQuery->method('all')->willReturn($ancestors);
        $category->method('getAncestors')->willReturn($ancestorQuery);

        return $category;
    }
}
