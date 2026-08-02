<?php

namespace fipschen95\commercedoofinder;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\events\ModelEvent;
use fipschen95\commercedoofinder\models\Settings;
use fipschen95\commercedoofinder\services\CatalogSyncService;
use fipschen95\commercedoofinder\services\DoofinderClient;
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
            $this->controllerNamespace = 'fipschen95\\commercedoofinder\\console\\controllers';
        }

        $this->set('catalogSync', function() {
            return new CatalogSyncService(
                fieldMapping: $this->getSettings()->getFieldMapping(),
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

        if (
            $settings->apiToken === null || $settings->apiToken === ''
            || $settings->searchEngineHashId === null || $settings->searchEngineHashId === ''
        ) {
            return null;
        }

        return new DoofinderClient(
            $settings->getApiHost(),
            $settings->apiToken,
            $settings->searchEngineHashId,
            $settings->indexName,
        );
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
