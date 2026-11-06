<?php

declare(strict_types=1);

namespace Arcjet\Internal;

use Arcjet\Conclusion;
use Arcjet\Decision;
use Arcjet\Internal\Proto;
use Arcjet\IpDetails;
use Arcjet\Reason\BotReason;
use Arcjet\Reason\ErrorReason;
use Arcjet\Reason\GenericReason;
use Arcjet\Reason\Reason;
use Arcjet\Reason\ShieldReason;
use Arcjet\RuleResult;
use Arcjet\RuleState;
use Arcjet\ThreatIntelligence;

/** @internal Converts to immutable values; no mutable protobuf messages escape. */
final class ResponseDecoder
{
    public static function decode(string $bytes): Decision
    {
        $response = new Proto\DecideResponse();
        try {
            ProtobufGuard::validate($bytes, \Google\Protobuf\DescriptorPool::getGeneratedPool()->getDescriptorByClassName(Proto\DecideResponse::class));
            $response->mergeFromString($bytes);
        } catch (\Throwable) {
            throw new ProtectionException('malformed_response', 'Arcjet returned malformed protobuf.');
        }
        $decision = $response->getDecision();
        if ($decision === null) {
            throw new ProtectionException('missing_decision', 'Arcjet returned no decision.');
        }
        $conclusion = Conclusion::tryFrom($decision->getConclusion());
        if ($conclusion === null || $conclusion === Conclusion::Unspecified) {
            throw new ProtectionException('invalid_conclusion', 'Arcjet returned an unknown or unspecified decision conclusion.');
        }
        $results = [];
        foreach ($decision->getRuleResults() as $result) {
            $ruleConclusion = Conclusion::tryFrom($result->getConclusion());
            if ($ruleConclusion === null) {
                throw new ProtectionException('invalid_conclusion', 'Arcjet returned an unknown rule conclusion.');
            }
            $results[] = new RuleResult(
                $result->getRuleId(),
                RuleState::tryFrom($result->getState()) ?? RuleState::Unspecified,
                $ruleConclusion,
                self::reason($result->getReason()),
                $result->getTtl(),
                $result->getFingerprint() !== '' ? $result->getFingerprint() : null,
            );
        }
        return new Decision(
            $decision->getId(), $conclusion, self::reason($decision->getReason()), $results,
            self::ip($decision->getIpDetails()), $decision->getTtl(),
        );
    }

    private static function reason(?Proto\Reason $reason): Reason
    {
        if ($reason === null) {
            return new GenericReason();
        }
        $bot = $reason->getBotV2();
        if ($bot !== null) {
            return new BotReason(self::strings($bot->getAllowed()), self::strings($bot->getDenied()), $bot->getVerified(), $bot->getSpoofed());
        }
        $shield = $reason->getShield();
        if ($shield !== null) {
            return new ShieldReason($shield->getShieldTriggered(), $shield->getSuspicious());
        }
        $error = $reason->getError();
        if ($error !== null) {
            return new ErrorReason($error->getMessage());
        }
        return new GenericReason($reason->getReason() !== '' ? $reason->getReason() : 'UNKNOWN', $reason->serializeToString());
    }

    /**
     * @param iterable<mixed> $values
     * @return list<string>
     */
    private static function strings(iterable $values): array
    {
        $result = [];
        foreach ($values as $value) {
            if (is_string($value)) {
                $result[] = $value;
            }
        }
        return $result;
    }

    private static function text(string $value): ?string { return $value !== '' ? $value : null; }

    private static function ip(?Proto\IpDetails $ip): ?IpDetails
    {
        if ($ip === null) {
            return null;
        }
        $bots = [];
        foreach ($ip->getBots() as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $bots[$name] = $value;
            }
        }
        $sourceThreat = $ip->getThreat();
        $threat = $sourceThreat === null ? null : new ThreatIntelligence(
            self::text($sourceThreat->getRiskLevel()), self::text($sourceThreat->getConfidence()),
            self::text($sourceThreat->getReputation()), $sourceThreat->getIsSafe() ? true : null,
            self::strings($sourceThreat->getNetworkTypes()), self::strings($sourceThreat->getActivities()),
            self::strings($sourceThreat->getEntities()), self::text($sourceThreat->getEntityName()),
            self::text($sourceThreat->getService()), $sourceThreat->getBackgroundNoise() ?: null,
        );
        return new IpDetails(
            latitude: $ip->getLatitude() != 0.0 ? $ip->getLatitude() : null,
            longitude: $ip->getLongitude() != 0.0 ? $ip->getLongitude() : null,
            accuracyRadius: $ip->getAccuracyRadius() ?: null,
            timezone: self::text($ip->getTimezone()), postalCode: self::text($ip->getPostalCode()),
            city: self::text($ip->getCity()), region: self::text($ip->getRegion()),
            country: self::text($ip->getCountry()), countryName: self::text($ip->getCountryName()),
            continent: self::text($ip->getContinent()), continentName: self::text($ip->getContinentName()),
            asn: self::text($ip->getAsn()), asnName: self::text($ip->getAsnName()),
            asnDomain: self::text($ip->getAsnDomain()), asnType: self::text($ip->getAsnType()),
            asnCountry: self::text($ip->getAsnCountry()), service: self::text($ip->getService()),
            hosting: $ip->getIsHosting() ? true : null, vpn: $ip->getIsVpn() ? true : null,
            proxy: $ip->getIsProxy() ? true : null, tor: $ip->getIsTor() ? true : null,
            relay: $ip->getIsRelay() ? true : null, abuser: $ip->getIsAbuser() ? true : null,
            bots: $bots, threat: $threat,
        );
    }
}
