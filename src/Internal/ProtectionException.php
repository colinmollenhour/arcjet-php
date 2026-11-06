<?php

declare(strict_types=1);

namespace Arcjet\Internal;

use Arcjet\FailureKind;

/** @internal All messages are SDK-owned, never copied from transport exceptions. */
final class ProtectionException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly FailureKind $failureKind)
    {
        parent::__construct($message);
    }
}
