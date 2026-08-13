<?php

declare(strict_types=1);

namespace kernpfad\commercedoofinder\events;

use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use yii\base\Event;

/**
 * Fired before a variant item payload is queued or bulk-indexed, so project
 * code can add, change or remove keys without subclassing the plugin.
 */
class ModifyItemPayloadEvent extends Event
{
    /**
     * @var array<string, mixed> the Doofinder item payload about to be sent
     */
    public array $payload = [];

    public Product $product;

    public Variant $variant;
}
