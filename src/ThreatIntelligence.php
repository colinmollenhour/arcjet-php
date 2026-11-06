<?php

declare(strict_types=1);

namespace Arcjet;

/** Proto3 default-valued scalar fields have no presence; null never means safe. */
final readonly class ThreatIntelligence
{
    /**
     * @param list<string> $networkTypes
     * @param list<string> $activities
     * @param list<string> $entities
     */
    public function __construct(
        public ?string $riskLevel = null,
        public ?string $confidence = null,
        public ?string $reputation = null,
        public ?bool $isSafe = null,
        public array $networkTypes = [],
        public array $activities = [],
        public array $entities = [],
        public ?string $entityName = null,
        public ?string $service = null,
        public ?int $backgroundNoise = null,
    ) {}
}
