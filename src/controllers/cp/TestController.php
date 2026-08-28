<?php

declare(strict_types=1);

namespace kernpfad\commercedoofinder\controllers\cp;

use craft\web\Controller;
use kernpfad\commercedoofinder\CommerceDoofinder;
use yii\web\Response;

class TestController extends Controller
{
    public function actionRun(): Response
    {
        $this->requireAdmin(false);

        $result = CommerceDoofinder::getInstance()?->testConnection()
            ?? ['success' => false, 'message' => 'Plugin is not available.'];

        return $this->asJson($result);
    }
}
