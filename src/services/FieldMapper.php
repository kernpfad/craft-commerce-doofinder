<?php

declare(strict_types=1);

namespace kernpfad\commercedoofinder\services;

/**
 * Pure mapping of a flat array of Craft product field values onto Doofinder
 * item custom fields, given a merchant-configured mapping of
 * `craftFieldHandle => doofinderFieldKey`. Doofinder items accept arbitrary
 * extra keys beyond their reserved ones (id/title/link/price/etc.), so
 * anything mapped here is just merged into the item payload as-is. Kept
 * standalone (rather than reaching into a real `craft\commerce\elements\Product`
 * directly) so it's unit-testable without booting Craft — same pattern as
 * commerce-klaviyo's `ProfileMapper`.
 */
class FieldMapper
{
    public function __construct(
        private readonly FieldValueNormalizer $valueNormalizer = new FieldValueNormalizer(),
    ) {
    }

    /**
     * @param array<string, string> $mapping craftFieldHandle => doofinderFieldKey
     * @param array<string, mixed> $fieldValues craftFieldHandle => value, as already extracted from the product
     * @return array<string, mixed>
     */
    public function mapFields(array $mapping, array $fieldValues): array
    {
        $fields = [];

        foreach ($mapping as $fieldHandle => $doofinderKey) {
            if ($doofinderKey === '' || !array_key_exists($fieldHandle, $fieldValues)) {
                continue;
            }

            $value = $this->valueNormalizer->normalize($fieldValues[$fieldHandle]);

            if ($value === null || $value === '') {
                continue;
            }

            $fields[$doofinderKey] = $value;
        }

        return $fields;
    }
}
