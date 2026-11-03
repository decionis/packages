<?php

declare(strict_types=1);

namespace Decionis\Client\Canonical;

/**
 * A value has no exact canonical form: a non-finite or unsafe number, invalid UTF-8, or a type
 * JSON cannot hold. The message is a stable code and never contains the value.
 */
final class CanonicalException extends \InvalidArgumentException
{
}
