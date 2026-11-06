<?php

declare(strict_types=1);

namespace Arcjet;

use Arcjet\Reason\Reason;

final readonly class RuleResult
{
    public function __construct(
        public string $ruleId,
        public RuleState $state,
        public Conclusion $conclusion,
        public Reason $reason,
        public int $ttl = 0,
        public ?string $fingerprint = null,
    ) {}

    public function isAllowed(): bool { return $this->conclusion === Conclusion::Allow; }
    public function isDenied(): bool { return $this->conclusion === Conclusion::Deny; }
    public function isChallenged(): bool { return $this->conclusion === Conclusion::Challenge; }
    public function isErrored(): bool { return $this->conclusion === Conclusion::Error; }
}
