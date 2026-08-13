<?php

declare(strict_types=1);

namespace kernpfad\commercedoofinder\services;

use Craft;
use yii\base\Component;

/**
 * Persists the outcome of the most recent Doofinder sync or reindex run so
 * the control panel can surface failures without digging through logs.
 */
class SyncStatusService extends Component
{
    public const OPERATION_SYNC = 'sync';
    public const OPERATION_REINDEX = 'reindex';

    private const CACHE_KEY = 'commerce-doofinder:last-status';

    /** @var int seconds — long enough for merchants to notice, not forever */
    private const CACHE_TTL = 2_592_000;

    public function recordFailure(string $operation, string $message, ?string $detail = null): void
    {
        $this->persist([
            'success' => false,
            'operation' => $operation,
            'message' => $message,
            'detail' => $detail,
            'timestamp' => time(),
        ]);
    }

    public function recordSuccess(string $operation): void
    {
        $this->persist([
            'success' => true,
            'operation' => $operation,
            'message' => null,
            'detail' => null,
            'timestamp' => time(),
        ]);
    }

    /**
     * @return array{success: bool, operation: string, message: ?string, detail: ?string, timestamp: int}|null
     */
    public function getLastStatus(): ?array
    {
        $status = Craft::$app->getCache()->get(self::CACHE_KEY);

        return is_array($status) ? $status : null;
    }

    /**
     * @param array{success: bool, operation: string, message: ?string, detail: ?string, timestamp: int} $status
     */
    private function persist(array $status): void
    {
        Craft::$app->getCache()->set(self::CACHE_KEY, $status, self::CACHE_TTL);
    }
}
