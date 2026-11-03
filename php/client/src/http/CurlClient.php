<?php

declare(strict_types=1);

namespace Decionis\Client\Http;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * A minimal PSR-18 client over ext-curl with finite connect and total timeouts, no redirects and
 * a response size cap. Any PSR-18 client works instead if it enforces the same limits.
 */
final class CurlClient implements ClientInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
        private readonly int $connectMs = 5000,
        private readonly int $totalMs = 15000,
        private readonly int $maxBytes = 2097152,
    ) {
        if ($connectMs < 1 || $totalMs < 1 || $connectMs > $totalMs) {
            throw new \InvalidArgumentException('CURL_TIMEOUTS_INVALID');
        }
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $url = (string) $request->getUri();
        $method = $request->getMethod();
        if ($url === '' || $method === '') {
            throw new \InvalidArgumentException('CURL_REQUEST_INVALID');
        }
        $handle = curl_init();
        $body = '';
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[] = $name . ': ' . implode(', ', $values);
        }
        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => (string) $request->getBody(),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_CONNECTTIMEOUT_MS => $this->connectMs,
            CURLOPT_TIMEOUT_MS => $this->totalMs,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > $this->maxBytes) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        if ($request->getMethod() === 'GET') {
            curl_setopt($handle, CURLOPT_HTTPGET, true);
        }
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($handle);
        curl_close($handle);
        if ($ok === false || $status === 0) {
            throw new NetworkError($request, $errno);
        }
        return $this->responses->createResponse($status)->withBody($this->streams->createStream($body));
    }
}
