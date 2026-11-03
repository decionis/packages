<?php

declare(strict_types=1);

namespace Decionis\Client\Test\Http;

use Decionis\Client\Error\HttpException;
use Decionis\Client\Error\TransportException;
use Decionis\Client\Http\Retry;
use Decionis\Client\Http\Transport;
use Decionis\Client\Test\Support\FakeClient;
use Decionis\Client\Test\Support\Network;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\TestCase;

final class TransportTest extends TestCase
{
    /** @var list<int> */
    private array $slept = [];

    private function transport(FakeClient $client): Transport
    {
        $factory = new Psr17Factory();
        return new Transport($client, $factory, $factory, 'test-agent', function (int $ms): void {
            $this->slept[] = $ms;
        });
    }

    public function testRetriesUnavailableWithTheSameIdempotencyKey(): void
    {
        $client = new FakeClient([FakeClient::json(503, ['error' => 'busy']), FakeClient::json(200, ['ok' => true])]);
        $body = $this->transport($client)->send('POST', 'https://api.decionis.com/v1/x', ['Idempotency-Key' => 'k-1'], '{}', new Retry(3, 10, 50, 5000));
        self::assertSame('{"ok":true}', $body);
        self::assertCount(2, $client->requests);
        self::assertSame(['k-1', 'k-1'], array_map(static fn ($r) => $r->getHeaderLine('Idempotency-Key'), $client->requests));
        self::assertCount(1, $this->slept);
        self::assertLessThanOrEqual(10, $this->slept[0]);
    }

    public function testClientErrorsAreNotRetried(): void
    {
        $client = new FakeClient([FakeClient::json(400, ['error' => 'bad', 'message' => 'Authorization: Bearer dcy_secret'])]);
        try {
            $this->transport($client)->send('POST', 'https://api.decionis.com/v1/x', [], '{}', new Retry());
            self::fail('expected an HttpException');
        } catch (HttpException $error) {
            self::assertSame(400, $error->status);
            self::assertSame('REQUEST_REJECTED', $error->getMessage());
            self::assertFalse($error->retryable());
        }
        self::assertCount(1, $client->requests);
    }

    public function testRetriesAreBounded(): void
    {
        $client = new FakeClient(array_fill(0, 3, FakeClient::json(502, [])));
        $this->expectExceptionObject(new HttpException(502));
        try {
            $this->transport($client)->send('GET', 'https://api.decionis.com/v1/x', [], null, new Retry(3, 1, 2, 5000));
        } finally {
            self::assertCount(3, $client->requests);
        }
    }

    public function testNoRetryIsSentPastTheDeadline(): void
    {
        $client = new FakeClient([FakeClient::json(503, []), FakeClient::json(200, [])]);
        $this->expectException(HttpException::class);
        try {
            $this->transport($client)->send('GET', 'https://api.decionis.com/v1/x', [], null, new Retry(3, 1000, 1000, 1));
        } finally {
            self::assertCount(1, $client->requests);
            self::assertSame([], $this->slept);
        }
    }

    public function testNetworkFailuresCarryNoUrlOrSecret(): void
    {
        $client = new FakeClient([new Network(new Request('GET', 'https://api.decionis.com'))]);
        try {
            $this->transport($client)->send('GET', 'https://api.decionis.com/v1/x?org_id=1', ['Authorization' => 'Bearer dcy_secret'], null, Retry::once(120));
            self::fail('expected a TransportException');
        } catch (TransportException $error) {
            self::assertSame('UPSTREAM_UNREACHABLE', $error->getMessage());
            self::assertTrue($error->retryable());
            $trail = $error->getMessage() . ($error->getPrevious()?->getMessage() ?? '');
            self::assertStringNotContainsString('secret', $trail);
            self::assertStringNotContainsString('decionis.com', $trail);
        }
    }

    public function testOversizedResponsesAreRefused(): void
    {
        $client = new FakeClient([FakeClient::json(200, str_repeat('a', 2097153))]);
        $this->expectExceptionObject(new TransportException('RESPONSE_TOO_LARGE'));
        $this->transport($client)->send('GET', 'https://api.decionis.com/v1/x', [], null, Retry::once(1000));
    }

    public function testRetryPolicyIsBounded(): void
    {
        $this->expectExceptionMessage('RETRY_POLICY_INVALID');
        new Retry(10);
    }
}
