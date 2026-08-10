<?php

declare(strict_types=1);

namespace kernpfad\commercedoofinder;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\events\ModelEvent;
use kernpfad\commercedoofinder\models\Settings;
use kernpfad\commercedoofinder\services\CatalogSyncService;
use kernpfad\commercedoofinder\services\DoofinderClient;
use yii\base\Event;
use yii\queue\Queue as YiiQueue;

/**
 * @property CatalogSyncService $catalogSync
 * @method Settings getSettings()
 */
class CommerceDoofinder extends Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = false;
    public bool $hasCpSettings = true;

    public function init(): void
    {
        parent::init();

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'kernpfad\\commercedoofinder\\console\\controllers';
        }

        $this->set('catalogSync', function() {
            return new CatalogSyncService(
                fieldMapping: $this->getSettings()->getFieldMapping(),
                imageFieldHandle: $this->getSettings()->imageFieldHandle,
                imageTransformHandle: $this->getSettings()->imageTransformHandle,
                queue: $this->getSyncQueue(),
            );
        });

        // Deliberately on Variant, not Product: verified against a real save
        // that a product's variants aren't persisted yet (no id, and a fresh
        // DB query finds nothing) at the moment Product::EVENT_AFTER_SAVE
        // fires — Commerce saves them as their own, later element saves. A
        // variant's *own* EVENT_AFTER_SAVE is the reliable point its id and
        // getProduct() are both actually available. See CatalogSyncService's
        // class docblock.
        Event::on(
            Product::class,
            Element::EVENT_AFTER_SAVE,
            function(ModelEvent $event) {
                /** @var Product $product */
                $product = $event->sender;
                $this->catalogSync->removeDisabledProductFromIndex($product);
            }
        );

        Event::on(
            Variant::class,
            Element::EVENT_AFTER_SAVE,
            function(ModelEvent $event) {
                /** @var Variant $variant */
                $variant = $event->sender;
                $this->catalogSync->syncVariant($variant);
            }
        );

        Event::on(
            Variant::class,
            Element::EVENT_AFTER_DELETE,
            function(Event $event) {
                /** @var Variant $variant */
                $variant = $event->sender;
                $this->catalogSync->deleteVariant($variant);
            }
        );

        Event::on(
            Product::class,
            Element::EVENT_AFTER_DELETE,
            function(Event $event) {
                /** @var Product $product */
                $product = $event->sender;
                $this->catalogSync->deleteProduct($product);
            }
        );
    }

    /**
     * Resolves the Yii application component this plugin's sync jobs are
     * pushed to, per {@see Settings::$queueComponentId}. Falls back to
     * Craft's own default `queue` component — logged, not thrown — if the
     * configured component ID doesn't exist or isn't actually a queue, so
     * a typo in a config file can never break a product save. Same pattern
     * as commerce-klaviyo's `getSyncQueue()`.
     */
    public function getSyncQueue(): YiiQueue
    {
        $componentId = $this->getSettings()->queueComponentId;
        $component = $componentId !== 'queue' ? Craft::$app->get($componentId, false) : null;

        if ($component instanceof YiiQueue) {
            return $component;
        }

        if ($componentId !== 'queue') {
            Craft::warning(
                "Commerce Doofinder: configured queue component \"{$componentId}\" is not available; falling back to the default queue.",
                __METHOD__
            );
        }

        return Craft::$app->getQueue();
    }

    /**
     * Null unless every required credential is set — a partially-configured
     * plugin skips sync work (logged) rather than sending broken requests
     * to Doofinder.
     */
    public function getDoofinderClient(): ?DoofinderClient
    {
        $settings = $this->getSettings();
        $apiToken = $settings->getParsedApiToken();
        $searchEngineHashId = $settings->getParsedSearchEngineHashId();

        if ($apiToken === null || $searchEngineHashId === null) {
            return null;
        }

        return new DoofinderClient(
            $settings->getApiHost(),
            $apiToken,
            $searchEngineHashId,
            $settings->indexName,
        );
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function testConnection(): array
    {
        $client = $this->getDoofinderClient();

        if ($client === null) {
            return [
                'success' => false,
                'message' => Craft::t('commerce-doofinder', 'Not fully configured (API token/search engine hash ID).'),
            ];
        }

        try {
            $index = $client->getIndex();
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        return [
            'success' => true,
            'message' => Craft::t('commerce-doofinder', 'Connected. Index "{name}" found.', [
                'name' => $index['name'] ?? $this->getSettings()->indexName,
            ]),
        ];
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('commerce-doofinder/settings.twig', [
            'settings' => $this->getSettings(),
        ]);
    }
}
