<?php

declare(strict_types=1);

namespace Arcjet\Reason;

/** Unsupported/future reasons retain their protobuf bytes without influencing the conclusion. */
final readonly class GenericReason extends Reason
{
    public function __construct(string $type = 'UNKNOWN', public string $protobuf = '')
    {
        parent::__construct($type);
    }
}
