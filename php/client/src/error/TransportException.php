<?php

declare(strict_types=1);

namespace Decionis\Client\Error;

/** The authority could not be reached within the budget: no decision. */
final class TransportException extends DecionisException
{
    public function __construct(string $code, ?\Throwable $previous = null)
    {
        parent::__construct($code, true, $previous);
    }
}
