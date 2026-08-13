<?php

declare(strict_types=1);

namespace kernpfad\commercedoofinder\models;

use craft\base\Model;
use craft\helpers\App;

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
     * May be an environment variable reference (`$DOOFINDER_API_TOKEN`) —
     * resolve through {@see getParsedApiToken()} rather than reading this
     * raw, so a project's real token never has to be committed to project
     * config.
     */
    public ?string $apiToken = null;

    /**
     * The target search engine's hash ID (32 hex characters). Same
     * environment-variable-alias support as {@see $apiToken} — resolve
     * through {@see getParsedSearchEngineHashId()}.
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

    /**
     * Handle of the Craft Assets field (on the product or the variant) whose
     * first asset's URL becomes each item's `image_link`. Checked on the
     * variant first, falling back to the product. Leave empty to omit
     * `image_link` entirely.
     */
    public ?string $imageFieldHandle = null;

    /**
     * Optional named image transform applied to the asset resolved via
     * {@see $imageFieldHandle} before its URL is used. Ignored when
     * {@see $imageFieldHandle} isn't set.
     */
    public ?string $imageTransformHandle = null;

    /**
     * Handle of the Craft Categories field on the product whose related
     * categories become each item's Doofinder `categories` paths. Leave empty
     * and enable {@see $categoriesAutoDiscover} to use the first Categories
     * field on the product's field layout instead.
     */
    public ?string $categoriesFieldHandle = null;

    /**
     * When true and {@see $categoriesFieldHandle} is empty, the first
     * Categories field on the product's field layout is used automatically.
     */
    public bool $categoriesAutoDiscover = false;

    /**
     * When {@see ReindexController::$ifStale} is used, skip the run if the
     * last successful full reindex is newer than this many hours. `0` means
     * never skip (always treat the index as stale).
     */
    public int $reindexStaleHours = 24;

    /**
     * Editable-table storage for custom field mappings. Kept in sync with
     * {@see $fieldMappingRaw} for backward compatibility with existing
     * project-config values.
     *
     * @var array<int, array{craftFieldHandle?: string, doofinderFieldKey?: string}>
     */
    public array $fieldMapping = [];

    public function getApiHost(): string
    {
        return "https://{$this->searchZone}-api.doofinder.com";
    }

    /**
     * {@see $apiToken}, resolved through {@see App::parseEnv()} so a
     * `$DOOFINDER_API_TOKEN`-style alias is turned into the real value.
     * Null when unset or when the referenced environment variable isn't
     * defined.
     */
    public function getParsedApiToken(): ?string
    {
        return self::parsedEnvString($this->apiToken);
    }

    /**
     * {@see $searchEngineHashId}, resolved the same way as {@see getParsedApiToken()}.
     */
    public function getParsedSearchEngineHashId(): ?string
    {
        return self::parsedEnvString($this->searchEngineHashId);
    }

    private static function parsedEnvString(?string $value): ?string
    {
        $parsed = App::parseEnv($value);

        return is_string($parsed) && $parsed !== '' ? $parsed : null;
    }

    /**
     * @return array<string, string>
     */
    public function getFieldMapping(): array
    {
        if ($this->fieldMapping !== []) {
            return self::mappingFromRows($this->fieldMapping);
        }

        return self::mappingFromRaw($this->fieldMappingRaw);
    }

    /**
     * @return array<int, array{craftFieldHandle: string, doofinderFieldKey: string}>
     */
    public function getFieldMappingRows(): array
    {
        $rows = [];

        foreach ($this->getFieldMapping() as $craftFieldHandle => $doofinderFieldKey) {
            $rows[] = [
                'craftFieldHandle' => $craftFieldHandle,
                'doofinderFieldKey' => $doofinderFieldKey,
            ];
        }

        return $rows;
    }

    /**
     * @param array<int, array{craftFieldHandle?: string, doofinderFieldKey?: string}>|string|null $value
     */
    public function setFieldMapping(array|string|null $value): void
    {
        if ($value === null || $value === '') {
            $this->fieldMapping = [];
            $this->fieldMappingRaw = '';

            return;
        }

        if (is_string($value)) {
            $this->fieldMappingRaw = $value;
            $this->fieldMapping = [];

            return;
        }

        $rows = is_array($value) ? $value : [];
        $this->fieldMapping = $rows;
        $this->fieldMappingRaw = self::rawFromRows($rows);
    }

    /**
     * @param array<int, array{craftFieldHandle?: string, doofinderFieldKey?: string}> $rows
     * @return array<string, string>
     */
    private static function mappingFromRows(array $rows): array
    {
        $mapping = [];

        foreach ($rows as $row) {
            $fieldHandle = trim((string)($row['craftFieldHandle'] ?? ''));
            $doofinderKey = trim((string)($row['doofinderFieldKey'] ?? ''));

            if ($fieldHandle === '' || $doofinderKey === '') {
                continue;
            }

            $mapping[$fieldHandle] = $doofinderKey;
        }

        return $mapping;
    }

    /**
     * @return array<string, string>
     */
    private static function mappingFromRaw(string $raw): array
    {
        $mapping = [];

        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
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
     * @param array<int, array{craftFieldHandle?: string, doofinderFieldKey?: string}> $rows
     */
    private static function rawFromRows(array $rows): string
    {
        $lines = [];

        foreach (self::mappingFromRows($rows) as $fieldHandle => $doofinderKey) {
            $lines[] = "{$fieldHandle}={$doofinderKey}";
        }

        return implode("\n", $lines);
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
            [['fieldMapping'], 'safe'],
            [['reindexStaleHours'], 'integer', 'min' => 0],
            [['imageFieldHandle', 'imageTransformHandle', 'categoriesFieldHandle'], 'string'],
            [['categoriesAutoDiscover'], 'boolean'],
        ];
    }
}
