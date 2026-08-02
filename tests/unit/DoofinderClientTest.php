<?php

namespace kernpfad\commercedoofinder\tests\unit;

use kernpfad\commercedoofinder\services\DoofinderClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Exercises DoofinderClient's HTTP behavior against a Guzzle MockHandler —
 * genuinely unit-testable (no Craft boot, no real network call) because the
 * Guzzle client is injectable via the constructor, the same pattern used
 * for KlaviyoClient.
 */
class DoofinderClientTest extends TestCase
{
    public function testUpsertItemSendsAPostWithTheCorrectAuthHeaderAndUrl(): void
    {
        $requests = [];
        $client = $this->makeClient([new Response(201)], $requests);

        $client->upsertItem(['id' => '123', 'title' => 'T']);

        self::assertCount(1, $requests);
        $request = $requests[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame(
            'https://eu1-api.doofinder.com/api/v2/search_engines/hash123/indices/product/items/',
            (string)$request->getUri()
        );
        self::assertSame('Token test-token', $request->getHeaderLine('Authorization'));
    }

    public function testUpsertItemFallsBackToPatchOnA409Conflict(): void
    {
        $requests = [];
        $client = $this->makeClient([
            new Response(409),
            new Response(200),
        ], $requests);

        $client->upsertItem(['id' => '123', 'title' => 'T']);

        self::assertCount(2, $requests);
        self::assertSame('POST', $requests[0]['request']->getMethod());
        self::assertSame('PATCH', $requests[1]['request']->getMethod());
        self::assertSame(
            'https://eu1-api.doofinder.com/api/v2/search_engines/hash123/indices/product/items/123',
            (string)$requests[1]['request']->getUri()
        );
    }

    public function testUpsertItemDoesNotFallBackOnANonConflictError(): void
    {
        $requests = [];
        $client = $this->makeClient([new Response(500)], $requests);

        $this->expectException(\GuzzleHttp\Exception\ServerException::class);
        $client->upsertItem(['id' => '123']);
    }

    public function testDeleteItemTreatsA404AsSuccessRatherThanAnError(): void
    {
        $requests = [];
        $client = $this->makeClient([new Response(404)], $requests);

        $client->deleteItem('already-gone');

        self::assertCount(1, $requests);
    }

    public function testDeleteItemPropagatesOtherErrors(): void
    {
        $requests = [];
        $client = $this->makeClient([new Response(500)], $requests);

        $this->expectException(\GuzzleHttp\Exception\ServerException::class);
        $client->deleteItem('123');
    }

    public function testDeleteTemporaryIndexTreatsA404AsSuccess(): void
    {
        $requests = [];
        $client = $this->makeClient([new Response(404)], $requests);

        $client->deleteTemporaryIndex();

        self::assertCount(1, $requests);
        self::assertSame('DELETE', $requests[0]['request']->getMethod());
    }

    public function testBulkUpsertItemsSendsAPostToTheBulkEndpointWithARawArrayBody(): void
    {
        $requests = [];
        $client = $this->makeClient([new Response(200)], $requests);

        $client->bulkUpsertItems([['id' => '1'], ['id' => '2']]);

        self::assertCount(1, $requests);
        $request = $requests[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame(
            'https://eu1-api.doofinder.com/api/v2/search_engines/hash123/indices/product/items/_bulk',
            (string)$request->getUri()
        );
        self::assertSame([['id' => '1'], ['id' => '2']], json_decode((string)$request->getBody(), true));
    }

    public function testReplaceIndexWithTemporarySendsAPostToTheReplaceEndpoint(): void
    {
        $requests = [];
        $client = $this->makeClient([new Response(200)], $requests);

        $client->replaceIndexWithTemporary();

        self::assertCount(1, $requests);
        self::assertSame(
            'https://eu1-api.doofinder.com/api/v2/search_engines/hash123/indices/product/_replace_by_temp/',
            (string)$requests[0]['request']->getUri()
        );
    }

    /**
     * @param Response[] $responses
     * @param array<int, array{request: \Psr\Http\Message\RequestInterface}> $requests
     */
    private function makeClient(array $responses, array &$requests): DoofinderClient
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(\GuzzleHttp\Middleware::history($requests));
        $guzzle = new Client(['handler' => $stack]);

        return new DoofinderClient('https://eu1-api.doofinder.com', 'test-token', 'hash123', 'product', $guzzle);
    }
}
