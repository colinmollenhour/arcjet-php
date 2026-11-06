<?php

declare(strict_types=1);

namespace Arcjet\Reason;

final readonly class ErrorReason extends Reason
{
    public function __construct(public string $message, public string $code = 'remote_error')
    {
        parent::__construct('ERROR');
    }
}
