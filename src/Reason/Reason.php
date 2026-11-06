<?php

declare(strict_types=1);

namespace Arcjet\Reason;

abstract readonly class Reason
{
    public function __construct(public string $type) {}

    public function isBot(): bool { return $this instanceof BotReason; }
    public function isShield(): bool { return $this instanceof ShieldReason; }
    public function isError(): bool { return $this instanceof ErrorReason; }
}
