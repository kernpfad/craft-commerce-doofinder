<?php

declare(strict_types=1);

namespace kernpfad\commercedoofinder\tests\unit;

use kernpfad\commercedoofinder\models\Settings;
use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase
{
    public function testSearchZoneDefaultsToEurope(): void
    {
        $settings = new Settings();

        self::assertSame(Settings::ZONE_EU, $settings->searchZone);
    }

    public function testIndexNameDefaultsToProduct(): void
    {
        $settings = new Settings();

        self::assertSame('product', $settings->indexName);
    }

    public function testQueueComponentIdDefaultsToTheCraftDefaultQueue(): void
    {
        $settings = new Settings();

        self::assertSame('queue', $settings->queueComponentId);
    }

    public function testApiHostIsDerivedFromTheSearchZone(): void
    {
        $settings = new Settings();
        $settings->searchZone = Settings::ZONE_US;

        self::assertSame('https://us1-api.doofinder.com', $settings->getApiHost());
    }

    public function testParsesOneFieldMappingPerLine(): void
    {
        $settings = new Settings();
        $settings->fieldMappingRaw = "brand=brand\nmaterial=material";

        self::assertSame(
            ['brand' => 'brand', 'material' => 'material'],
            $settings->getFieldMapping()
        );
    }

    public function testTrimsWhitespaceAroundFieldMappingHandlesAndKeys(): void
    {
        $settings = new Settings();
        $settings->fieldMappingRaw = '  brand  =  brand  ';

        self::assertSame(['brand' => 'brand'], $settings->getFieldMapping());
    }

    public function testIgnoresBlankFieldMappingLines(): void
    {
        $settings = new Settings();
        $settings->fieldMappingRaw = "brand=brand\n\n\nmaterial=material";

        self::assertCount(2, $settings->getFieldMapping());
    }

    public function testEmptyFieldMappingRawProducesAnEmptyMapping(): void
    {
        $settings = new Settings();

        self::assertSame([], $settings->getFieldMapping());
    }

    public function testParsedApiTokenIsNullWhenUnset(): void
    {
        $settings = new Settings();

        self::assertNull($settings->getParsedApiToken());
    }

    public function testParsedApiTokenReturnsThePlainValueWhenItIsNotAnEnvReference(): void
    {
        $settings = new Settings();
        $settings->apiToken = 'plain-token-value';

        self::assertSame('plain-token-value', $settings->getParsedApiToken());
    }

    public function testParsedApiTokenResolvesAnEnvironmentVariableReference(): void
    {
        putenv('DOOFINDER_TEST_API_TOKEN=resolved-token-value');
        $settings = new Settings();
        $settings->apiToken = '$DOOFINDER_TEST_API_TOKEN';

        self::assertSame('resolved-token-value', $settings->getParsedApiToken());

        putenv('DOOFINDER_TEST_API_TOKEN');
    }

    public function testParsedApiTokenIsNullWhenTheReferencedEnvironmentVariableIsNotSet(): void
    {
        $settings = new Settings();
        $settings->apiToken = '$DOOFINDER_TEST_TOKEN_THAT_DOES_NOT_EXIST';

        self::assertNull($settings->getParsedApiToken());
    }

    public function testParsedSearchEngineHashIdResolvesAnEnvironmentVariableReference(): void
    {
        putenv('DOOFINDER_TEST_HASH_ID=resolved-hash-id');
        $settings = new Settings();
        $settings->searchEngineHashId = '$DOOFINDER_TEST_HASH_ID';

        self::assertSame('resolved-hash-id', $settings->getParsedSearchEngineHashId());

        putenv('DOOFINDER_TEST_HASH_ID');
    }

    public function testSearchZoneHasAnInValidationRuleRestrictedToTheThreeKnownZones(): void
    {
        $settings = new Settings();
        $reflection = new \ReflectionMethod($settings, 'defineRules');
        $reflection->setAccessible(true);

        $rules = $reflection->invoke($settings);

        $zoneRule = null;

        foreach ($rules as $rule) {
            if (in_array('searchZone', (array)$rule[0], true) && $rule[1] === 'in') {
                $zoneRule = $rule;
                break;
            }
        }

        self::assertNotNull($zoneRule, 'Expected an "in" rule covering searchZone.');
        self::assertSame(['eu1', 'us1', 'ap1'], $zoneRule['range']);
    }
}
