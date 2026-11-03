<?php

declare(strict_types=1);

namespace Decionis\Client\Error;

/** The authority refused the request with an HTTP status, mapped to a stable code. */
final class HttpException extends DecionisException
{
    public function __construct(public readonly int $status)
    {
        parent::__construct(self::code($status), $status === 429 || $status >= 500);
    }

    private static function code(int $status): string
    {
        return match (true) {
            $status === 401 => 'AUTHENTICATION_FAILED',
            $status === 403 => 'AUTHORIZATION_FAILED',
            $status === 404 => 'NOT_FOUND',
            $status === 409 => 'CONFLICT',
            $status === 429 => 'RATE_LIMITED',
            $status >= 500 => 'UPSTREAM_UNAVAILABLE',
            default => 'REQUEST_REJECTED',
        };
    }
}
