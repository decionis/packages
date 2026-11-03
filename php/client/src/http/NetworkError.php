<?php

declare(strict_types=1);

namespace Decionis\Client\Http;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/** A request that never got an HTTP answer; the message is the curl error number only. */
final class NetworkError extends \RuntimeException implements NetworkExceptionInterface
{
    public function __construct(private readonly RequestInterface $request, int $errno)
    {
        parent::__construct('CURL_ERROR_' . $errno);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
