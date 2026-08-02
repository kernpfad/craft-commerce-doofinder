<?php

namespace fipschen95\commercedoofinder\console\controllers;

use craft\commerce\elements\Product;
use craft\console\Controller;
use fipschen95\commercedoofinder\CommerceDoofinder;
use Throwable;
use yii\console\ExitCode;

/**
 * `php craft doofinder/reindex` — a full-catalog resync using Doofinder's
 * documented temporary-index workflow (verified against
 * https://docs.doofinder.com/api-reference/indices/): build a complete,
 * fresh index in a locked staging area, then atomically swap it in for the
 * live one. The live index keeps serving search traffic, unaffected, for
 * the entire duration of the reindex — a merchant's search never goes
 * half-updated or empty partway through a run, unlike pushing updates
 * directly against the live index.
 */
class ReindexController extends Controller
{
    public function actionIndex(): int
    {
        $plugin = CommerceDoofinder::getInstance();

        if ($plugin === null) {
            $this->stdout("Commerce Doofinder is not installed.\n");

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $client = $plugin->getDoofinderClient();

        if ($client === null) {
            $this->stdout("Commerce Doofinder is not fully configured (API token/search engine hash ID) — aborting.\n");

            return ExitCode::CONFIG;
        }

        $this->stdout("Creating a temporary index...\n");
        $client->createTemporaryIndex();

        $itemCount = 0;

        try {
            $chunk = [];

            /** @var Product $product */
            foreach (Product::find()->each() as $product) {
                foreach ($plugin->catalogSync->buildVariantPayloads($product) as $payload) {
                    $chunk[] = $payload;
                    $itemCount++;

                    if (count($chunk) >= 100) {
                        $client->bulkUpsertItems($chunk);
                        $chunk = [];
                    }
                }
            }

            if ($chunk !== []) {
                $client->bulkUpsertItems($chunk);
            }

            $this->stdout("Replacing the live index with the freshly built one...\n");
            $client->replaceIndexWithTemporary();
            $this->stdout("Done. {$itemCount} items indexed.\n");

            return ExitCode::OK;
        } catch (Throwable $e) {
            $this->stdout("Reindex failed: {$e->getMessage()}\n");
            $client->deleteTemporaryIndex();

            return ExitCode::UNSPECIFIED_ERROR;
        }
    }
}
