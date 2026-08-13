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

    private const REINDEX_SUCCESS_CACHE_KEY = 'commerce-doofinder:last-reindex-success';

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

        if ($operation === self::OPERATION_REINDEX) {
            Craft::$app->getCache()->set(
                self::REINDEX_SUCCESS_CACHE_KEY,
                time(),
                self::CACHE_TTL,
            );
        }
    }

    /**
     * Whether a full reindex is due — true when never successfully reindexed,
     * or when the last success is older than `$staleHours`. A `$staleHours`
     * of `0` always returns true (never skip).
     */
    public function isReindexStale(int $staleHours): bool
    {
        return self::isTimestampStale(
            $this->getLastSuccessfulReindexTimestamp(),
            $staleHours,
        );
    }

    /**
     * Pure staleness check — exposed for unit tests without booting Craft.
     */
    public static function isTimestampStale(?int $timestamp, int $staleHours, ?int $now = null): bool
    {
        if ($staleHours <= 0) {
            return true;
        }

        if ($timestamp === null) {
            return true;
        }

        $now ??= time();

        return ($now - $timestamp) >= ($staleHours * 3600);
    }

    public function getLastSuccessfulReindexTimestamp(): ?int
    {
        $timestamp = Craft::$app->getCache()->get(self::REINDEX_SUCCESS_CACHE_KEY);

        return is_int($timestamp) ? $timestamp : null;
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
