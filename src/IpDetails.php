<?php

declare(strict_types=1);

namespace Arcjet;

/** Missing enrichment/default proto3 scalar values are unknown, not evidence of safety. */
final readonly class IpDetails
{
    /** @param array<string, string> $bots */
    public function __construct(
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?int $accuracyRadius = null,
        public ?string $timezone = null,
        public ?string $postalCode = null,
        public ?string $city = null,
        public ?string $region = null,
        public ?string $country = null,
        public ?string $countryName = null,
        public ?string $continent = null,
        public ?string $continentName = null,
        public ?string $asn = null,
        public ?string $asnName = null,
        public ?string $asnDomain = null,
        public ?string $asnType = null,
        public ?string $asnCountry = null,
        public ?string $service = null,
        public ?bool $hosting = null,
        public ?bool $vpn = null,
        public ?bool $proxy = null,
        public ?bool $tor = null,
        public ?bool $relay = null,
        public ?bool $abuser = null,
        public array $bots = [],
        public ?ThreatIntelligence $threat = null,
    ) {}

    public function hasLocation(): bool { return $this->country !== null || $this->city !== null || ($this->latitude !== null && $this->longitude !== null); }
    public function hasAsn(): bool { return $this->asn !== null; }
    public function hasThreat(): bool { return $this->threat !== null; }
    public function isHosting(): ?bool { return $this->hosting; }
    public function isVpn(): ?bool { return $this->vpn; }
    public function isProxy(): ?bool { return $this->proxy; }
    public function isTor(): ?bool { return $this->tor; }
    public function isRelay(): ?bool { return $this->relay; }
    public function isAbuser(): ?bool { return $this->abuser; }
}
