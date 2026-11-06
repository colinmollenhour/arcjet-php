<?php

declare(strict_types=1);

namespace Arcjet;

use Arcjet\Reason\ErrorReason;
use Arcjet\Reason\Reason;

final readonly class Decision
{
    /** @param list<RuleResult> $results */
    public function __construct(
        public string $id,
        public Conclusion $conclusion,
        public Reason $reason,
        public array $results = [],
        public ?IpDetails $ip = null,
        public int $ttl = 0,
        public ?FailureKind $failureKind = null,
    ) {}

    /** Strictly ALLOW; errors and challenges need an explicit application policy. */
    public function isAllowed(): bool { return $this->conclusion === Conclusion::Allow; }
    public function isDenied(): bool { return $this->conclusion === Conclusion::Deny; }
    public function isChallenged(): bool { return $this->conclusion === Conclusion::Challenge; }
    public function isErrored(): bool { return $this->conclusion === Conclusion::Error; }
    public function hasIpDetails(): bool { return $this->ip !== null; }

    /** Only operational errors are eligible; this never relabels ERROR as ALLOW. */
    public function isFailOpenEligible(): bool
    {
        return $this->isErrored() && ($this->failureKind === FailureKind::Transport || $this->failureKind === FailureKind::Service);
    }

    /** @internal */
    public static function error(string $code, string $message, FailureKind $failureKind): self
    {
        return new self('', Conclusion::Error, new ErrorReason($message, $code), failureKind: $failureKind);
    }
}
