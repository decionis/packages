<?php

declare(strict_types=1);

namespace Decionis\Client\Error;

/**
 * Base for every client error. The message is a stable code; no error carries a credential,
 * request body, response body or URL with query parameters.
 */
abstract class DecionisException extends \RuntimeException
{
    public function __construct(string $code, private readonly bool $retryable = false, ?\Throwable $previous = null)
    {
        parent::__construct($code, 0, $previous === null ? null : new \RuntimeException(get_class($previous)));
    }

    /** True when the same request may be sent again with the same idempotency key. */
    public function retryable(): bool
    {
        return $this->retryable;
    }
}
