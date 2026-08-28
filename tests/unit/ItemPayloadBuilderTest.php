<?php

declare(strict_types=1);

namespace kernpfad\commercedoofinder\tests\unit;

use kernpfad\commercedoofinder\services\ItemPayloadBuilder;
use PHPUnit\Framework\TestCase;

class ItemPayloadBuilderTest extends TestCase
{
    private ItemPayloadBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ItemPayloadBuilder();
    }

    public function testBuildsTheMinimalRequiredShape(): void
    {
        $item = $this->builder->buildItem('123', 'Blue Shirt', 'https://example.com/blue-shirt', null, 29.0, null, '100', true);

        self::assertSame([
            'id' => '123',
            'title' => 'Blue Shirt',
            'link' => 'https://example.com/blue-shirt',
            'price' => 29.0,
            'group_id' => '100',
            'group_leader' => true,
        ], $item);
    }

    public function testIncludesImageLinkOnlyWhenProvided(): void
    {
        $withImage = $this->builder->buildItem('123', 'T', 'L', 'https://example.com/img.jpg', 10.0, null, '1', true);
        $withoutImage = $this->builder->buildItem('123', 'T', 'L', null, 10.0, null, '1', true);

        self::assertSame('https://example.com/img.jpg', $withImage['image_link']);
        self::assertArrayNotHasKey('image_link', $withoutImage);
    }

    public function testIncludesSalePriceOnlyWhenLowerThanPrice(): void
    {
        $onSale = $this->builder->buildItem('123', 'T', 'L', null, 20.0, 15.0, '1', true);
        $notOnSale = $this->builder->buildItem('123', 'T', 'L', null, 20.0, null, '1', true);
        $equalPrice = $this->builder->buildItem('123', 'T', 'L', null, 20.0, 20.0, '1', true);

        self::assertSame(15.0, $onSale['sale_price']);
        self::assertArrayNotHasKey('sale_price', $notOnSale);
        self::assertArrayNotHasKey('sale_price', $equalPrice);
    }

    public function testMergesCustomFieldsIntoTheItem(): void
    {
        $item = $this->builder->buildItem('123', 'T', 'L', null, 10.0, null, '1', true, customFields: ['brand' => 'Acme']);

        self::assertSame('Acme', $item['brand']);
    }

    public function testGroupLeaderReflectsWhichVariantIsPassed(): void
    {
        $leader = $this->builder->buildItem('1', 'T', 'L', null, 10.0, null, 'g', true);
        $notLeader = $this->builder->buildItem('2', 'T', 'L', null, 10.0, null, 'g', false);

        self::assertTrue($leader['group_leader']);
        self::assertFalse($notLeader['group_leader']);
    }

    public function testIncludesAvailabilityOnlyWhenProvided(): void
    {
        $available = $this->builder->buildItem('1', 'T', 'L', null, 10.0, null, '1', true, availability: true);
        $unavailable = $this->builder->buildItem('1', 'T', 'L', null, 10.0, null, '1', true, availability: false);
        $omitted = $this->builder->buildItem('1', 'T', 'L', null, 10.0, null, '1', true);

        self::assertTrue($available['availability']);
        self::assertFalse($unavailable['availability']);
        self::assertArrayNotHasKey('availability', $omitted);
    }

    public function testIncludesStockQuantityOnlyWhenProvided(): void
    {
        $tracked = $this->builder->buildItem('1', 'T', 'L', null, 10.0, null, '1', true, stockQuantity: 5);
        $untracked = $this->builder->buildItem('1', 'T', 'L', null, 10.0, null, '1', true, stockQuantity: null);

        self::assertSame(5, $tracked['stock_quantity']);
        self::assertArrayNotHasKey('stock_quantity', $untracked);
    }

    public function testZeroStockQuantityIsStillIncluded(): void
    {
        $outOfStock = $this->builder->buildItem('1', 'T', 'L', null, 10.0, null, '1', true, stockQuantity: 0);

        self::assertSame(0, $outOfStock['stock_quantity']);
    }

    public function testIncludesCategoriesOnlyWhenProvided(): void
    {
        $withCategories = $this->builder->buildItem(
            '1',
            'T',
            'L',
            null,
            10.0,
            null,
            '1',
            true,
            categories: ['Clothes > Hoodies', 'Sale'],
        );
        $withoutCategories = $this->builder->buildItem('1', 'T', 'L', null, 10.0, null, '1', true);

        self::assertSame(['Clothes > Hoodies', 'Sale'], $withCategories['categories']);
        self::assertArrayNotHasKey('categories', $withoutCategories);
    }
}
