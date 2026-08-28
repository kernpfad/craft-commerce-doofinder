<?php

declare(strict_types=1);

namespace kernpfad\commercedoofinder\tests\integration;

/**
 * Boots a real Craft + Commerce application and drives the actual
 * production event-listener pipeline (`CommerceDoofinder::init()`) with
 * real products and variants — nothing here calls the orchestrating
 * services directly except where explicitly noted.
 *
 * Rather than executing queued jobs against the real Doofinder API (no
 * credentials exist in this environment), these tests inspect Craft's own
 * queue via `Queue::getJobInfo()` to confirm the *right jobs with the
 * right descriptions* were queued in response to real Commerce events.
 * `DoofinderClientTest` (unit) separately covers the actual client
 * behavior (upsert-on-409-fallback, 404-tolerant delete) against a mocked
 * `ManagementClient`.
 *
 * Requires CRAFT_TEST_SITE_PATH to point at a working Craft + Commerce
 * install with this plugin linked in via a Composer path repository. Skips
 * itself if that's not configured.
 *
 * PHPUnit will flag the first test as "risky" (error/exception handlers
 * not restored) — that's Craft's own application bootstrap registering its
 * handlers inside the same process, not a bug here. It doesn't fail the
 * run (exit code stays 0).
 */

use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\models\ProductType;
use craft\commerce\models\ProductTypeSite;
use craft\commerce\Plugin as Commerce;
use kernpfad\commercedoofinder\CommerceDoofinder;
use kernpfad\commercedoofinder\services\CatalogSyncService;
use kernpfad\commercedoofinder\services\CategoryResolver;
use PHPUnit\Framework\TestCase;

class ProductSyncTest extends TestCase
{
    private static bool $booted = false;

    protected function setUp(): void
    {
        $sitePath = getenv('CRAFT_TEST_SITE_PATH');

        if (!$sitePath || !is_dir($sitePath)) {
            $this->markTestSkipped(
                'CRAFT_TEST_SITE_PATH is not set to a working Craft install; skipping integration tests.'
            );
        }

        if (!self::$booted) {
            define('CRAFT_BASE_PATH', $sitePath);
            define('CRAFT_VENDOR_PATH', CRAFT_BASE_PATH . '/vendor');
            require CRAFT_VENDOR_PATH . '/autoload.php';

            if (class_exists(\Dotenv\Dotenv::class)) {
                \Dotenv\Dotenv::createImmutable(CRAFT_BASE_PATH)->safeLoad();
            }

            require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
            self::$booted = true;
        }

        if (!class_exists(Commerce::class) || Commerce::getInstance() === null) {
            $this->markTestSkipped('Craft Commerce is not installed on the test install; skipping.');
        }

        $plugin = CommerceDoofinder::getInstance();
        self::assertNotNull($plugin, 'Commerce Doofinder plugin is not installed on the test install.');

        // Nothing in this suite ever executes the queue (no real Doofinder
        // credentials exist here), so jobs pushed by one test would
        // otherwise sit in the table and inflate every later test's count.
        \Craft::$app->getDb()->createCommand()->truncateTable('{{%queue}}')->execute();
    }

    public function testSavingAProductQueuesASyncJob(): void
    {
        $this->createProduct(19.0);

        $descriptions = $this->queuedDescriptions();

        self::assertTrue(
            $this->anyContains($descriptions, 'Doofinder index'),
            'Expected a sync job to be queued after saving a product. Queue contents: ' . implode(' | ', $descriptions)
        );
    }

    public function testDeletingAProductQueuesADeleteJob(): void
    {
        $product = $this->createProduct(19.0);

        self::assertTrue(\Craft::$app->getElements()->deleteElement($product, true));

        $descriptions = $this->queuedDescriptions();

        self::assertTrue(
            $this->anyContains($descriptions, 'Removing'),
            'Expected a delete job to be queued after deleting a product. Queue contents: ' . implode(' | ', $descriptions)
        );
    }

    public function testSavingADraftDoesNotQueueAnySyncJob(): void
    {
        // Regression test: Craft fires EVENT_AFTER_SAVE for drafts too, and a
        // draft is a separate element with its own ID. Without a guard, every
        // CP autosave indexed a junk item keyed to the draft's ID — an entry
        // in the merchant's live search results for something unbuyable.
        $product = $this->createProduct(19.0);
        \Craft::$app->getDb()->createCommand()->truncateTable('{{%queue}}')->execute();

        $draft = \Craft::$app->getDrafts()->createDraft($product, null, 'Doofinder test draft');
        $draft->title = 'Edited in a draft';
        self::assertTrue(\Craft::$app->getElements()->saveElement($draft));

        self::assertSame(
            [],
            $this->doofinderJobs(),
            'A draft save must not queue any Doofinder job.'
        );
    }

    public function testCreatingARevisionDoesNotQueueAnySyncJob(): void
    {
        // Same guard, the higher-volume case: Craft creates a revision on
        // every CP publish, so without this an actively-edited store would
        // accumulate one junk indexed item per edit, forever.
        $product = $this->createProduct(19.0);
        \Craft::$app->getDb()->createCommand()->truncateTable('{{%queue}}')->execute();

        \Craft::$app->getRevisions()->createRevision($product);

        self::assertSame(
            [],
            $this->doofinderJobs(),
            'Creating a revision must not queue any Doofinder job.'
        );
    }

    public function testDisablingAProductQueuesADeleteJob(): void
    {
        $product = $this->createProduct(19.0);
        \Craft::$app->getDb()->createCommand()->truncateTable('{{%queue}}')->execute();

        $product->enabled = false;
        self::assertTrue(
            \Craft::$app->getElements()->saveElement($product),
            implode(', ', $product->getErrorSummary(true))
        );

        $descriptions = $this->doofinderJobs();

        self::assertTrue(
            $this->anyContains($descriptions, 'Removing'),
            'Disabling a product must queue a delete job. Queue contents: ' . implode(' | ', $descriptions)
        );
    }

    public function testBuildVariantPayloadsProducesTheCorrectGroupingAndPrice(): void
    {
        $product = $this->createProduct(42.0);
        $variant = $product->getVariants()->first();
        self::assertNotNull($variant);

        $payloads = (new CatalogSyncService())->buildVariantPayloads($product);

        self::assertCount(1, $payloads);
        self::assertSame((string)$variant->id, $payloads[0]['id']);
        self::assertSame((string)$product->id, $payloads[0]['group_id']);
        self::assertTrue($payloads[0]['group_leader']);
        self::assertSame(42.0, $payloads[0]['price']);
    }

    public function testBuildVariantPayloadsIncludesAvailability(): void
    {
        $product = $this->createProduct(42.0);

        $payloads = (new CatalogSyncService())->buildVariantPayloads($product);

        self::assertCount(1, $payloads);
        self::assertTrue($payloads[0]['availability'], 'An enabled, live product/variant should be available.');
    }

    public function testBuildVariantPayloadsOmitsStockQuantityForInventoryUntrackedVariants(): void
    {
        // Inventory tracking is opt-in per variant (Purchasable::$inventoryTracked
        // defaults to false) — an untracked variant reporting `stock_quantity: 0`
        // would misrepresent it as out of stock, so it must be omitted entirely.
        $product = $this->createProduct(42.0);
        $variant = $product->getVariants()->first();
        self::assertNotNull($variant);
        self::assertFalse($variant->inventoryTracked, 'Expected a freshly created variant to be inventory-untracked by default.');

        $payloads = (new CatalogSyncService())->buildVariantPayloads($product);

        self::assertArrayNotHasKey('stock_quantity', $payloads[0]);
    }

    public function testAMisconfiguredCategoriesFieldHandleIsOmittedRatherThanCrashing(): void
    {
        // Regression test: a merchant-configured categoriesFieldHandle
        // (unlike auto-discover, which only ever finds a handle already on
        // the layout) can point at a field this product type's layout
        // doesn't carry — a different product type might have it, this one
        // doesn't. getFieldValue() throws for a handle outside the
        // element's own layout, so building the payload used to blow up
        // entirely instead of just omitting categories.
        $product = $this->createProduct(19.0);

        $service = new CatalogSyncService(
            categoryResolver: new CategoryResolver(fieldHandle: 'notOnTheDoofinderTestsLayout'),
        );

        $payloads = $service->buildVariantPayloads($product);

        self::assertCount(1, $payloads);
        self::assertArrayNotHasKey('categories', $payloads[0]);
    }

    public function testAMisconfiguredFieldMappingHandleIsOmittedRatherThanCrashing(): void
    {
        // Same bug, the fieldMappingRows path: mapping a craftFieldHandle
        // that isn't on this product's layout used to throw
        // InvalidFieldException instead of just leaving that mapped key out
        // of the payload.
        $product = $this->createProduct(19.0);

        $service = new CatalogSyncService(
            fieldMapping: ['notOnTheDoofinderTestsLayout' => 'doofinderKey'],
        );

        $payloads = $service->buildVariantPayloads($product);

        self::assertCount(1, $payloads);
        self::assertArrayNotHasKey('doofinderKey', $payloads[0]);
    }

    public function testGetSyncQueueReturnsCraftsDefaultQueueByDefault(): void
    {
        $plugin = CommerceDoofinder::getInstance();
        self::assertNotNull($plugin);
        $plugin->getSettings()->queueComponentId = 'queue';

        self::assertSame(\Craft::$app->getQueue(), $plugin->getSyncQueue());
    }

    public function testGetSyncQueueFallsBackToTheDefaultQueueForAnUnknownComponentId(): void
    {
        $plugin = CommerceDoofinder::getInstance();
        self::assertNotNull($plugin);
        $plugin->getSettings()->queueComponentId = 'thisComponentDoesNotExistDoofinderTest';

        self::assertSame(\Craft::$app->getQueue(), $plugin->getSyncQueue());

        // Reset for any later test relying on the default.
        $plugin->getSettings()->queueComponentId = 'queue';
    }

    public function testGetDoofinderClientIsNullWhenNotFullyConfigured(): void
    {
        $plugin = CommerceDoofinder::getInstance();
        self::assertNotNull($plugin);
        $plugin->getSettings()->apiToken = null;
        $plugin->getSettings()->searchEngineHashId = null;

        self::assertNull($plugin->getDoofinderClient());
    }

    public function testGetDoofinderClientIsNotNullWhenFullyConfigured(): void
    {
        $plugin = CommerceDoofinder::getInstance();
        self::assertNotNull($plugin);
        $plugin->getSettings()->apiToken = 'test-token-not-a-real-secret';
        $plugin->getSettings()->searchEngineHashId = 'test-hash-id';

        self::assertNotNull($plugin->getDoofinderClient());
    }

    private function createProduct(float $price): Product
    {
        $commerce = Commerce::getInstance();
        $site = \Craft::$app->getSites()->getPrimarySite();
        $suffix = bin2hex(random_bytes(4));

        $productType = $commerce->getProductTypes()->getProductTypeByHandle('doofinderTests');

        if ($productType === null) {
            $productType = new ProductType();
            $productType->name = 'Doofinder Tests';
            $productType->handle = 'doofinderTests';
            $productType->setSiteSettings([
                $site->id => new ProductTypeSite(['siteId' => $site->id, 'hasUrls' => false]),
            ]);
            self::assertTrue($commerce->getProductTypes()->saveProductType($productType));
            \Craft::$app->getProjectConfig()->saveModifiedConfigData();
            $productType = $commerce->getProductTypes()->getProductTypeByHandle('doofinderTests');
        }

        $product = new Product();
        $product->typeId = $productType->id;
        $product->title = "Doofinder Test Product {$suffix}";
        $product->siteId = $site->id;

        $variant = new Variant();
        $variant->sku = "doofinder-test-{$suffix}";
        $variant->basePrice = $price;
        $variant->isDefault = true;
        $product->setVariants([$variant]);
        $product->setDirtyAttributes(['variants']);

        self::assertTrue(
            \Craft::$app->getElements()->saveElement($product),
            implode(', ', $product->getErrorSummary(true))
        );

        return $product;
    }

    /**
     * Only this plugin's own jobs — Craft and Commerce queue unrelated work
     * (catalog pricing, revision pruning) during these operations.
     *
     * @return string[]
     */
    private function doofinderJobs(): array
    {
        return array_values(array_filter(
            $this->queuedDescriptions(),
            fn(string $d): bool => str_contains($d, 'Doofinder')
        ));
    }

    /**
     * @return string[]
     */
    private function queuedDescriptions(): array
    {
        $info = \Craft::$app->getQueue()->getJobInfo(1000);

        return array_map(fn(array $job): string => (string)($job['description'] ?? ''), $info);
    }

    /**
     * @param string[] $descriptions
     */
    private function anyContains(array $descriptions, string $needle): bool
    {
        foreach ($descriptions as $description) {
            if (str_contains($description, $needle)) {
                return true;
            }
        }

        return false;
    }
}
