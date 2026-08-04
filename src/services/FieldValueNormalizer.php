<?php

declare(strict_types=1);

namespace kernpfad\commercedoofinder\services;

use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\fields\data\ColorData;
use craft\fields\data\LinkData;
use craft\fields\data\MultiOptionsFieldData;
use craft\fields\data\SingleOptionFieldData;
use DateTimeInterface;
use Traversable;

/**
 * Reduces Craft product field values to JSON-safe scalars for Doofinder
 * item custom fields. Asset URLs, option labels, category titles and
 * similar structured values are flattened here so {@see FieldMapper} stays
 * a pure handle-to-key mapper.
 */
class FieldValueNormalizer
{
    /**
     * @return string|int|float|bool|null a scalar Doofinder can index, or
     *   null when the value should be omitted from the payload
     */
    public function normalize(mixed $value): string|int|float|bool|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof LinkData) {
            return $value->url !== '' ? $value->url : null;
        }

        if ($value instanceof ColorData) {
            return (string)$value;
        }

        if ($value instanceof SingleOptionFieldData) {
            return $value->value !== null && $value->value !== ''
                ? (string)$value->value
                : null;
        }

        if ($value instanceof MultiOptionsFieldData) {
            return $this->normalizeIterable($value);
        }

        if ($value instanceof Asset) {
            return $value->getUrl() ?: null;
        }

        if ($value instanceof ElementInterface) {
            $title = $value->title;

            return ($title !== null && $title !== '') ? $title : (string)$value->id;
        }

        if (is_array($value) || ($value instanceof Traversable && !$value instanceof ElementInterface)) {
            return $this->normalizeIterable($value);
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            $string = (string)$value;

            return $string !== '' ? $string : null;
        }

        return null;
    }

    /**
     * @param iterable<mixed> $items
     */
    private function normalizeIterable(iterable $items): ?string
    {
        $parts = [];

        foreach ($items as $item) {
            $normalized = $this->normalize($item);

            if ($normalized === null || $normalized === '') {
                continue;
            }

            $parts[] = (string)$normalized;
        }

        if ($parts === []) {
            return null;
        }

        return implode(', ', $parts);
    }
}
