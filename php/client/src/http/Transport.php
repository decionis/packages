<?php

declare(strict_types=1);

namespace Decionis\Client\Http;

use Decionis\Client\Error\HttpException;
use Decionis\Client\Error\TransportException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Sends one logical request: the same idempotency key on every attempt, bounded retries on
 * network errors, 429 and 5xx only, and nothing sent after the deadline.
 */
final class Transport
{
    private const MAX_BYTES = 2097152;

    /** @var \Closure(int): void */
    private readonly \Closure $sleep;

    /** @param (\Closure(int): void)|null $sleep milliseconds; injectable for tests */
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly string $userAgent,
        ?\Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    /**
     * @param array<string, string> $headers
     * @return string the response body of a 2xx answer
     */
    public function send(string $method, string $url, array $headers, ?string $body, Retry $retry): string
    {
        $started = hrtime(true);
        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $this->client->sendRequest($this->request($method, $url, $headers, $body));
                $status = $response->getStatusCode();
                if ($status >= 200 && $status < 300) {
                    $text = (string) $response->getBody();
                    if (strlen($text) > self::MAX_BYTES) {
                        throw new TransportException('RESPONSE_TOO_LARGE');
                    }
                    return $text;
                }
                $failure = new HttpException($status);
            } catch (ClientExceptionInterface $error) {
                $failure = new TransportException('UPSTREAM_UNREACHABLE', $error);
            }
            if (!$failure->retryable() || $attempt >= $retry->attempts) {
                throw $failure;
            }
            $delay = $retry->delayMs($attempt);
            $elapsed = intdiv(hrtime(true) - $started, 1000000);
            if ($elapsed + $delay >= $retry->deadlineMs) {
                throw $failure;
            }
            ($this->sleep)($delay);
        }
    }

    /** @param array<string, string> $headers */
    private function request(string $method, string $url, array $headers, ?string $body): \Psr\Http\Message\RequestInterface
    {
        $request = $this->requests->createRequest($method, $url)
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', $this->userAgent);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== null) {
            $request = $request->withHeader('Content-Type', 'application/json')
                ->withBody($this->streams->createStream($body));
        }
        return $request;
    }
}
