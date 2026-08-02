<?php

namespace fipschen95\commercedoofinder\services;

use Craft;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;

/**
 * A thin, direct REST wrapper around Doofinder's Management API (verified
 * against https://docs.doofinder.com/api-reference/, current v2 API).
 *
 * Deliberately **not** built on the official `doofinder/doofinder` Composer
 * package — inspecting its actual installed source (not just its GitHub
 * README, which turned out to describe an unreleased rewrite that doesn't
 * match what's published) showed the real, currently-installable v5.x
 * package targets Doofinder's old, differently-shaped `/v1` API
 * (`Doofinder\Api\Management\Client`, raw `curl_init()`, no temporary-index
 * concept at all) — not the current, documented `/api/v2` surface this
 * plugin actually needs (temporary-index + atomic replace for zero-downtime
 * reindexing). Sending the current API's plain JSON directly is simpler and
 * correct, and matches the same choice already made for `commerce-klaviyo`.
 *
 * Two specifics are genuinely undocumented in Doofinder's own API reference
 * as of this writing, and are implemented here as the most reasonable,
 * REST-convention-consistent assumption rather than guessed silently —
 * flagged again in the README's "Testing status" section:
 * - How bulk item writes reach a temporary index once one exists. A
 *   temporary index "sets a lock preventing any changes on the search
 *   engine until the temporary index is deleted" — read as meaning the
 *   *same* item-write endpoints get transparently routed to the temporary
 *   copy while that lock is active, since no distinct temp-specific
 *   item-write path is documented anywhere.
 * - The temporary-index delete/cancel endpoint's exact path — inferred as
 *   `DELETE .../indices/{name}/temp/`, the direct REST counterpart of the
 *   documented `POST .../indices/{name}/temp/` create call.
 *
 * Every call here can throw (network failure, 4xx/5xx from Doofinder) — by
 * design. Call sites (the queue jobs, the reindex console command) are
 * responsible for catching and logging, never the code that queues them.
 */
class DoofinderClient
{
    /**
     * Set explicitly because `Craft::createGuzzleClient()` configures only a
     * User-Agent and an optional proxy (verified against Craft's own
     * source) — without these, the client inherits Guzzle's default of *no
     * timeout at all*, so an unresponsive Doofinder would hold a queue
     * worker open until PHP's execution limit rather than failing and
     * letting Craft retry the job.
     *
     * The bulk endpoint gets a longer allowance: it accepts up to 100 items
     * per request (Doofinder's documented limit) and is only ever called
     * from the reindex console command, where a slower response is expected
     * and there's no request worker waiting on it.
     */
    private const CONNECT_TIMEOUT_SECONDS = 5;
    private const TOTAL_TIMEOUT_SECONDS = 15;
    private const BULK_TIMEOUT_SECONDS = 60;

    public function __construct(
        private readonly string $apiHost,
        private readonly string $apiToken,
        private readonly string $searchEngineHashId,
        private readonly string $indexName,
        private ?ClientInterface $httpClient = null,
    ) {
    }

    /**
     * Creates the item, falling back to a PATCH update when Doofinder
     * rejects the create because an item with that ID already exists (a
     * 409 Conflict) — the same create-then-fall-back-to-update pattern
     * already used for Klaviyo's catalog upsert, since Doofinder's own
     * duplicate-id behavior isn't documented either way.
     *
     * @param array<string, mixed> $itemParams
     */
    public function upsertItem(array $itemParams): void
    {
        $itemId = (string)($itemParams['id'] ?? '');

        try {
            $this->request('POST', 'items/', $itemParams);
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() !== 409) {
                throw $e;
            }

            $this->request('PATCH', 'items/' . rawurlencode($itemId), $itemParams);
        }
    }

    /**
     * A 404 is treated as success: the item is already gone, which is
     * exactly what a delete is trying to achieve.
     */
    public function deleteItem(string $itemId): void
    {
        try {
            $this->getHttpClient()->request('DELETE', $this->indexUrl('items/' . rawurlencode($itemId)), [
                'headers' => $this->headers(),
            ]);
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() !== 404) {
                throw $e;
            }
        }
    }

    public function createTemporaryIndex(): void
    {
        $this->getHttpClient()->request('POST', $this->indexUrl('temp/'), [
            'headers' => $this->headers(),
        ]);
    }

    /**
     * A 404 (no temporary index exists) is treated as success — matches
     * this client's own 404-tolerant delete convention.
     */
    public function deleteTemporaryIndex(): void
    {
        try {
            $this->getHttpClient()->request('DELETE', $this->indexUrl('temp/'), [
                'headers' => $this->headers(),
            ]);
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() !== 404) {
                throw $e;
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $items up to 100 per Doofinder's documented bulk-request limit
     */
    public function bulkUpsertItems(array $items): void
    {
        $this->request('POST', 'items/_bulk', $items, self::BULK_TIMEOUT_SECONDS);
    }

    public function replaceIndexWithTemporary(): void
    {
        $this->getHttpClient()->request('POST', $this->indexUrl('_replace_by_temp/'), [
            'headers' => $this->headers(),
        ]);
    }

    /**
     * @param array<string, mixed>|array<int, array<string, mixed>> $payload
     * @throws GuzzleException
     */
    private function request(string $method, string $relativePath, array $payload, ?int $timeout = null): void
    {
        $this->getHttpClient()->request($method, $this->indexUrl($relativePath), [
            'json' => $payload,
            'headers' => $this->headers(),
            'timeout' => $timeout ?? self::TOTAL_TIMEOUT_SECONDS,
        ]);
    }

    private function indexUrl(string $relativePath): string
    {
        return sprintf(
            '%s/api/v2/search_engines/%s/indices/%s/%s',
            rtrim($this->apiHost, '/'),
            $this->searchEngineHashId,
            $this->indexName,
            ltrim($relativePath, '/'),
        );
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Authorization' => 'Token ' . $this->apiToken,
            'Accept' => 'application/json',
        ];
    }

    private function getHttpClient(): ClientInterface
    {
        return $this->httpClient ??= Craft::createGuzzleClient([
            'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
            'timeout' => self::TOTAL_TIMEOUT_SECONDS,
        ]);
    }
}
