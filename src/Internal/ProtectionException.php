<?php

declare(strict_types=1);

namespace Arcjet\Internal;

/** @internal All messages are SDK-owned, never copied from transport exceptions. */
final class ProtectionException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
