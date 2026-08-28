<?php

declare(strict_types=1);

namespace kernpfad\commercedoofinder\tests\unit;

use DateTimeImmutable;
use kernpfad\commercedoofinder\services\FieldValueNormalizer;
use PHPUnit\Framework\TestCase;

class FieldValueNormalizerTest extends TestCase
{
    private FieldValueNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new FieldValueNormalizer();
    }

    public function testReturnsScalarsAsIs(): void
    {
        self::assertSame('Acme', $this->normalizer->normalize('Acme'));
        self::assertSame(42, $this->normalizer->normalize(42));
        self::assertSame(19.99, $this->normalizer->normalize(19.99));
        self::assertTrue($this->normalizer->normalize(true));
    }

    public function testNullAndEmptyStringBecomeNull(): void
    {
        self::assertNull($this->normalizer->normalize(null));
        self::assertNull($this->normalizer->normalize(''));
    }

    public function testFormatsDateTimeValuesAsAtomStrings(): void
    {
        $date = new DateTimeImmutable('2026-01-15 12:00:00 UTC');

        self::assertSame('2026-01-15T12:00:00+00:00', $this->normalizer->normalize($date));
    }

    public function testJoinsIterableValuesIntoACommaSeparatedString(): void
    {
        self::assertSame('Cotton, Wool', $this->normalizer->normalize(['Cotton', 'Wool']));
    }

    public function testEmptyIterablesBecomeNull(): void
    {
        self::assertNull($this->normalizer->normalize([]));
    }

    public function testUsesStringCastForObjectsWithToString(): void
    {
        $value = new class() {
            public function __toString(): string
            {
                return 'custom-value';
            }
        };

        self::assertSame('custom-value', $this->normalizer->normalize($value));
    }

    public function testUnknownObjectsBecomeNull(): void
    {
        self::assertNull($this->normalizer->normalize(new \stdClass()));
    }
}
