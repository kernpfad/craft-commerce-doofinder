<?php

namespace fipschen95\commercedoofinder\tests\unit;

use fipschen95\commercedoofinder\services\FieldMapper;
use PHPUnit\Framework\TestCase;

class FieldMapperTest extends TestCase
{
    private FieldMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new FieldMapper();
    }

    public function testMapsConfiguredFieldsToTheirDoofinderKeys(): void
    {
        $result = $this->mapper->mapFields(
            ['brand' => 'brand', 'material' => 'material'],
            ['brand' => 'Acme', 'material' => 'Cotton'],
        );

        self::assertSame(['brand' => 'Acme', 'material' => 'Cotton'], $result);
    }

    public function testFieldsNotInTheMappingAreNeverIncluded(): void
    {
        $result = $this->mapper->mapFields(
            ['brand' => 'brand'],
            ['brand' => 'Acme', 'internalNote' => 'secret'],
        );

        self::assertSame(['brand' => 'Acme'], $result);
    }

    public function testSkipsMappedFieldsThatAreEmptyOrNull(): void
    {
        $result = $this->mapper->mapFields(
            ['brand' => 'brand', 'material' => 'material'],
            ['brand' => null, 'material' => ''],
        );

        self::assertSame([], $result);
    }

    public function testSkipsAMappingEntryWhoseFieldValueWasNeverProvided(): void
    {
        $result = $this->mapper->mapFields(['brand' => 'brand'], []);

        self::assertSame([], $result);
    }

    public function testSkipsAMappingEntryWithAnEmptyDoofinderKey(): void
    {
        $result = $this->mapper->mapFields(['brand' => ''], ['brand' => 'Acme']);

        self::assertSame([], $result);
    }
}
