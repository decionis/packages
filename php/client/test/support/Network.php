<?php

declare(strict_types=1);

namespace Decionis\Client\Test\Support;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/** A network failure as a PSR-18 client reports it. */
final class Network extends \RuntimeException implements NetworkExceptionInterface
{
    public function __construct(private readonly RequestInterface $request, string $message = 'connection refused to https://api.decionis.com?token=secret')
    {
        parent::__construct($message);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
