<?php

declare(strict_types=1);

namespace Arcjet\Reason;

final readonly class ShieldReason extends Reason
{
    public function __construct(public bool $shieldTriggered, public bool $suspicious)
    {
        parent::__construct('SHIELD');
    }
}
