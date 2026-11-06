<?php

declare(strict_types=1);

namespace Arcjet\Tests\Protocol;

use Arcjet\Internal\Proto\BotReason;
use Arcjet\Internal\Proto\BotType;
use Arcjet\Internal\Proto\BotV2Reason;
use Arcjet\Internal\Proto\BotV2Rule;
use Arcjet\Internal\Proto\Conclusion;
use Arcjet\Internal\Proto\DecideRequest;
use Arcjet\Internal\Proto\DecideResponse;
use Arcjet\Internal\Proto\Decision;
use Arcjet\Internal\Proto\IpDetails;
use Arcjet\Internal\Proto\Mode;
use Arcjet\Internal\Proto\Reason;
use Arcjet\Internal\Proto\RequestDetails;
use Arcjet\Internal\Proto\Rule;
use Arcjet\Internal\Proto\RuleResult;
use Arcjet\Internal\Proto\RuleState;
use Arcjet\Internal\Proto\SDKStack;
use Arcjet\Internal\Proto\ShieldReason;
use Arcjet\Internal\Proto\ShieldRule;
use Arcjet\Internal\Proto\ThreatIntelligence;
use Arcjet\Internal\Proto\Warning;

/** Independent PHP builders corresponding to the Python fixture examples. */
final class FixtureMessages
{
    public static function request(int $mode, bool $allow): DecideRequest
    {
        $bots = (new BotV2Rule())->setMode($mode);
        $identifiers = ['CATEGORY:SEARCH_ENGINE', 'CURL', 'SIGNED_BOT:https://bots.example.test/directory'];
        if ($allow) {
            $bots->setAllow($identifiers);
        } else {
            $bots->setDeny($identifiers);
        }

        return (new DecideRequest())
            ->setSdkStack(SDKStack::SDK_STACK_UNSPECIFIED)
            ->setSdkVersion('0.1.0-fixture')
            ->setDetails((new RequestDetails())
                ->setIp('203.0.113.42')
                ->setMethod('POST')
                ->setProtocol('https:')
                ->setHost('example.test')
                ->setPath('/search/☃')
                ->setRawPath('/search/%E2%98%83')
                ->setQuery('?q=arcjet&sort=asc')
                // Reverse map insertion order deliberately: order is not a wire contract.
                ->setHeaders(['user-agent' => 'curl/8.0.0', 'authorization' => '<redacted>', 'accept' => 'application/json'])
                ->setBody("\0snowman: ☃\xff")
                ->setExtra(['userId' => 'fixture-user', 'tenant' => 'example'])
                ->setCookies('session=fixture')
                ->setCorrelationId('fixture-correlation'))
            ->setRules([
                (new Rule())->setShield((new ShieldRule())->setMode($mode)->setCharacteristics(['ip.src', 'http.host'])),
                (new Rule())->setBotV2($bots),
            ])
            ->setCharacteristics(['ip.src', 'userId'])
            ->setMetadataJson(['feature' => '"fixture"', 'attempt' => '1'])
            ->setLocalWarnings([(new Warning())->setCode('fixture')->setMessage('Offline contract example')]);
    }

    public static function botResponse(): DecideResponse
    {
        $bot = (new Reason())->setBotV2((new BotV2Reason())
            ->setAllowed(['GOOGLE_CRAWLER'])->setDenied(['CURL'])->setVerified(true)->setSpoofed(true));
        $shield = (new Reason())->setShield((new ShieldReason())->setShieldTriggered(true)->setSuspicious(true));
        $legacy = (new Reason())->setBot((new BotReason())
            ->setBotType(BotType::BOT_TYPE_AUTOMATED)->setBotScore(99)->setUserAgentMatch(true)
            ->setIpHosting(true)->setIpVpn(true)->setIpProxy(true)->setIpTor(true)->setIpRelay(true));

        return (new DecideResponse())
            ->setDecision((new Decision())
                ->setId('lreq_fixture_bot')
                ->setConclusion(Conclusion::CONCLUSION_DENY)
                ->setReason($bot)
                ->setRuleResults([
                    (new RuleResult())->setRuleId('bot-rule')->setState(RuleState::RULE_STATE_RUN)
                        ->setConclusion(Conclusion::CONCLUSION_DENY)->setReason($bot)->setTtl(60)->setFingerprint('bot-fingerprint'),
                    (new RuleResult())->setRuleId('shield-rule')->setState(RuleState::RULE_STATE_DRY_RUN)
                        ->setConclusion(Conclusion::CONCLUSION_DENY)->setReason($shield)->setTtl(10),
                    (new RuleResult())->setRuleId('legacy-bot-rule')->setState(RuleState::RULE_STATE_CACHED)
                        ->setConclusion(Conclusion::CONCLUSION_DENY)->setReason($legacy),
                ])
                ->setTtl(60)
                ->setIpDetails((new IpDetails())
                    ->setLatitude(37.75)->setLongitude(-122.5)->setAccuracyRadius(20)
                    ->setTimezone('America/Los_Angeles')->setPostalCode('94107')->setCity('San Francisco')
                    ->setRegion('CA')->setCountry('US')->setCountryName('United States')
                    ->setContinent('NA')->setContinentName('North America')->setAsn('64500')
                    ->setAsnName('Example Network')->setAsnDomain('example.test')->setAsnType('hosting')->setAsnCountry('US')
                    ->setService('fixture-cloud')->setIsHosting(true)->setIsVpn(true)->setIsProxy(true)
                    ->setIsTor(true)->setIsRelay(true)->setIsAbuser(true)
                    ->setBots(['search' => 'verified', 'fixture' => 'automated'])
                    ->setThreat((new ThreatIntelligence())
                        ->setRiskLevel('high')->setConfidence('high')->setReputation('poor')->setIsSafe(false)
                        ->setNetworkTypes(['hosting', 'vpn'])->setActivities(['automation', 'scanning'])
                        ->setEntities(['fixture'])->setEntityName('Example Network')->setService('fixture-intelligence')->setBackgroundNoise(7))))
            ->setExtra(['trace' => 'offline', 'fixture' => 'python']);
    }

    /** @return array<string, DecideRequest|DecideResponse> */
    public static function all(): array
    {
        return [
            'request-dry_run-allow' => self::request(Mode::MODE_DRY_RUN, true),
            'request-dry_run-deny' => self::request(Mode::MODE_DRY_RUN, false),
            'request-live-allow' => self::request(Mode::MODE_LIVE, true),
            'request-live-deny' => self::request(Mode::MODE_LIVE, false),
            'response-bot-deny-ip' => self::botResponse(),
        ];
    }
}
