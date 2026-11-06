<?php

declare(strict_types=1);

namespace Arcjet\Reason;

final readonly class BotReason extends Reason
{
    /**
     * @param list<string> $allowed
     * @param list<string> $denied
     */
    public function __construct(
        public array $allowed,
        public array $denied,
        public bool $verified,
        public bool $spoofed,
    ) {
        parent::__construct('BOT');
    }
}
