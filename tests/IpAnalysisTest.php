<?php

declare(strict_types=1);

namespace Arcjet\Tests;

use Arcjet\Internal\Proto;
use Arcjet\Tests\Support\FakeHttpClient;
use Arcjet\Tests\Support\SdkTestCase;
use Psr\Http\Message\ResponseInterface;

final class IpAnalysisTest extends SdkTestCase
{
    public function testIpAnalysisOnlySendsNoRulesAndPreservesEnrichment(): void
    {
        $threat = (new Proto\ThreatIntelligence())->setRiskLevel('high')->setConfidence('strong')->setReputation('bad')
            ->setIsSafe(true)->setNetworkTypes(['vpn'])->setActivities(['scanning'])->setEntities(['entity'])
            ->setEntityName('Entity')->setService('service')->setBackgroundNoise(4);
        $ip = (new Proto\IpDetails())->setLatitude(51.5)->setLongitude(-0.1)->setAccuracyRadius(20)->setTimezone('Europe/London')
            ->setPostalCode('SW1')->setCity('London')->setRegion('England')->setCountry('GB')->setCountryName('United Kingdom')
            ->setContinent('EU')->setContinentName('Europe')->setAsn('12345')->setAsnName('Example ASN')->setAsnDomain('example.test')
            ->setAsnType('hosting')->setAsnCountry('GB')->setService('VPN service')->setIsHosting(true)->setIsVpn(true)
            ->setIsProxy(true)->setIsTor(true)->setIsRelay(true)->setIsAbuser(true)->setBots(['EXAMPLE_BOT' => 'verified'])->setThreat($threat);
        $http = new FakeHttpClient(static fn (): ResponseInterface => self::response((new Proto\Decision())->setConclusion(1)->setIpDetails($ip)));
        $decision = $this->sdk($http)->protect($this->request());
        self::assertTrue($decision->isAllowed());
        self::assertCount(0, self::decodeRequest($http->requests[0])->getRules());
        self::assertTrue($decision->hasIpDetails());
        $details = $decision->ip;
        self::assertNotNull($details);
        self::assertTrue($details->hasLocation());
        self::assertTrue($details->hasAsn());
        self::assertSame(51.5, $details->latitude);
        self::assertSame(-0.1, $details->longitude);
        self::assertSame(20, $details->accuracyRadius);
        self::assertSame('Europe/London', $details->timezone);
        self::assertSame('SW1', $details->postalCode);
        self::assertSame('London', $details->city);
        self::assertSame('England', $details->region);
        self::assertSame('GB', $details->country);
        self::assertSame('United Kingdom', $details->countryName);
        self::assertSame('EU', $details->continent);
        self::assertSame('Europe', $details->continentName);
        self::assertSame('12345', $details->asn);
        self::assertSame('Example ASN', $details->asnName);
        self::assertSame('example.test', $details->asnDomain);
        self::assertSame('hosting', $details->asnType);
        self::assertSame('GB', $details->asnCountry);
        self::assertSame('VPN service', $details->service);
        self::assertTrue($details->isHosting());
        self::assertTrue($details->isVpn());
        self::assertTrue($details->isProxy());
        self::assertTrue($details->isTor());
        self::assertTrue($details->isRelay());
        self::assertTrue($details->isAbuser());
        self::assertSame(['EXAMPLE_BOT' => 'verified'], $details->bots);
        self::assertTrue($details->hasThreat());
        self::assertNotNull($details->threat);
        self::assertSame('high', $details->threat->riskLevel);
        self::assertSame('strong', $details->threat->confidence);
        self::assertSame('bad', $details->threat->reputation);
        self::assertTrue($details->threat->isSafe);
        self::assertSame(['vpn'], $details->threat->networkTypes);
        self::assertSame(['scanning'], $details->threat->activities);
        self::assertSame(['entity'], $details->threat->entities);
        self::assertSame('Entity', $details->threat->entityName);
        self::assertSame('service', $details->threat->service);
        self::assertSame(4, $details->threat->backgroundNoise);
    }

    public function testMissingIpDetailsAreNotPresentedAsSafe(): void
    {
        $decision = $this->sdk($this->allowingHttp())->protect($this->request());
        self::assertNull($decision->ip);
        self::assertFalse($decision->hasIpDetails());
    }

    public function testEmptyMessagesRetainPresenceButDefaultScalarsRemainUnknown(): void
    {
        $ip = (new Proto\IpDetails())->setThreat(new Proto\ThreatIntelligence());
        $http = new FakeHttpClient(static fn (): ResponseInterface => self::response((new Proto\Decision())->setConclusion(1)->setIpDetails($ip)));
        $decision = $this->sdk($http)->protect($this->request());
        self::assertNotNull($decision->ip);
        self::assertFalse($decision->ip->hasLocation());
        self::assertFalse($decision->ip->hasAsn());
        self::assertTrue($decision->ip->hasThreat());
        self::assertNull($decision->ip->latitude);
        self::assertNull($decision->ip->isVpn());
        self::assertNull($decision->ip->isProxy());
        self::assertNull($decision->ip->isTor());
        self::assertNull($decision->ip->isHosting());
        self::assertNull($decision->ip->isRelay());
        self::assertNull($decision->ip->isAbuser());
        self::assertNotNull($decision->ip->threat);
        self::assertNull($decision->ip->threat->isSafe);
        self::assertNull($decision->ip->threat->backgroundNoise);
    }

    public function testDecisionValuesAreImmutableAndDetachedFromMutableProtobuf(): void
    {
        $proto = (new Proto\Decision())->setConclusion(1)->setIpDetails((new Proto\IpDetails())->setCountry('GB'));
        $http = new FakeHttpClient(static fn (): ResponseInterface => self::response($proto));
        $decision = $this->sdk($http)->protect($this->request());
        $proto->setConclusion(2);
        self::assertTrue($decision->isAllowed());
        self::assertNotNull($decision->ip);
        $this->expectException(\Error::class);
        (new \ReflectionProperty($decision->ip, 'country'))->setValue($decision->ip, 'US');
    }
}
