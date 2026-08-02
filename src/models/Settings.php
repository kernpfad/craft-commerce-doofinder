<?php

namespace kernpfad\commercedoofinder\models;

use craft\base\Model;

/**
 * @property-read string $apiHost derived from $searchZone
 * @property-read array<string, string> $fieldMapping craftFieldHandle => doofinderFieldKey, decoded from fieldMappingRaw
 */
class Settings extends Model
{
    public const ZONE_EU = 'eu1';
    public const ZONE_US = 'us1';
    public const ZONE_AP = 'ap1';

    /**
     * Which of Doofinder's regional API endpoints this account lives in
     * (shown in the Doofinder Admin Panel). Determines $apiHost.
     */
    public string $searchZone = self::ZONE_EU;

    /**
     * Doofinder API token (Account → API Keys in the Doofinder Admin Panel).
     */
    public ?string $apiToken = null;

    /**
     * The target search engine's hash ID (32 hex characters).
     */
    public ?string $searchEngineHashId = null;

    /**
     * The Doofinder index name products/variants are synced into.
     */
    public string $indexName = 'product';

    /**
     * The ID of the Yii application component this plugin's sync jobs are
     * pushed to — Craft's own `queue` component by default. Same
     * configurable-queue-component pattern as commerce-klaviyo.
     */
    public string $queueComponentId = 'queue';

    /**
     * Raw storage for the custom field mapping, as `handle1=property1`
     * lines (one per line) — Doofinder items accept arbitrary extra keys
     * beyond its reserved fields (id/title/link/image_link/price/etc.).
     */
    public string $fieldMappingRaw = '';

    public function getApiHost(): string
    {
        return "https://{$this->searchZone}-api.doofinder.com";
    }

    /**
     * @return array<string, string>
     */
    public function getFieldMapping(): array
    {
        $mapping = [];

        foreach (preg_split('/\r\n|\r|\n/', $this->fieldMappingRaw) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || !str_contains($line, '=')) {
                continue;
            }

            [$fieldHandle, $doofinderKey] = explode('=', $line, 2);
            $fieldHandle = trim($fieldHandle);
            $doofinderKey = trim($doofinderKey);

            if ($fieldHandle === '' || $doofinderKey === '') {
                continue;
            }

            $mapping[$fieldHandle] = $doofinderKey;
        }

        return $mapping;
    }

    /**
     * @return array<int, array<array-key, mixed>>
     */
    protected function defineRules(): array
    {
        return [
            [['searchZone'], 'required'],
            [['searchZone'], 'in', 'range' => [self::ZONE_EU, self::ZONE_US, self::ZONE_AP]],
            [['apiToken', 'searchEngineHashId'], 'string'],
            [['indexName'], 'required'],
            [['indexName'], 'string'],
            [['queueComponentId'], 'required'],
            [['queueComponentId'], 'string'],
            [['fieldMappingRaw'], 'string'],
        ];
    }
}
