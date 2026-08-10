<?php

declare(strict_types=1);

namespace kernpfad\commercedoofinder\console\controllers;

use craft\console\Controller;
use kernpfad\commercedoofinder\CommerceDoofinder;
use yii\console\ExitCode;

/**
 * `php craft commerce-doofinder/test` — verifies the configured API token,
 * search engine hash ID and index name all work together by fetching the
 * index's own metadata (a single lightweight, read-only call), without any
 * side effects on the live index.
 */
class TestController extends Controller
{
    public function actionIndex(): int
    {
        $plugin = CommerceDoofinder::getInstance();

        if ($plugin === null) {
            $this->stderr("Commerce Doofinder is not installed.\n");

            return ExitCode::UNAVAILABLE;
        }

        $result = $plugin->testConnection();

        if ($result['success']) {
            $this->stdout($result['message'] . "\n");

            return ExitCode::OK;
        }

        $this->stderr($result['message'] . "\n");

        return ExitCode::UNAVAILABLE;
    }
}
