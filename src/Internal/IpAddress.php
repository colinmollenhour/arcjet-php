<?php

declare(strict_types=1);

namespace Arcjet\Internal;

use Arcjet\FailureKind;

use Psr\Http\Message\ServerRequestInterface;

/** @internal */
final class IpAddress
{
    public static function isValid(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    public static function validateNetwork(string $network): void
    {
        $parts = explode('/', $network);
        $packed = @inet_pton($parts[0]);
        if ($packed === false || count($parts) > 2
            || (isset($parts[1]) && (!ctype_digit($parts[1]) || (int) $parts[1] > strlen($packed) * 8))) {
            throw new \InvalidArgumentException('trustedProxies must contain valid IP addresses or IPv4/IPv6 CIDRs.');
        }
    }

    public static function inNetwork(string $ip, string $network): bool
    {
        $parts = explode('/', $network, 2);
        $address = @inet_pton($ip);
        $base = @inet_pton($parts[0]);
        if ($address === false || $base === false || strlen($address) !== strlen($base)) {
            return false;
        }
        $bits = isset($parts[1]) ? (int) $parts[1] : strlen($base) * 8;
        $bytes = intdiv($bits, 8);
        if (substr($address, 0, $bytes) !== substr($base, 0, $bytes)) {
            return false;
        }
        $remainder = $bits % 8;
        return $remainder === 0 || ((ord($address[$bytes]) ^ ord($base[$bytes])) & (0xff << (8 - $remainder))) === 0;
    }

    /** @param list<string> $networks */
    private static function trusted(string $ip, array $networks): bool
    {
        foreach ($networks as $network) {
            if (self::inNetwork($ip, $network)) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $trustedProxies */
    public static function resolve(ServerRequestInterface $request, array $trustedProxies, ?string $override): string
    {
        if ($override !== null) {
            if (!self::isValid($override)) {
                throw new ProtectionException('invalid_ip', 'The application IP override is invalid.', FailureKind::Request);
            }
            return $override;
        }
        $peer = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        if (!is_string($peer) || !self::isValid($peer)) {
            throw new ProtectionException('invalid_ip', 'A valid REMOTE_ADDR or explicit application IP override is required.', FailureKind::Request);
        }
        if (!self::trusted($peer, $trustedProxies) || !$request->hasHeader('X-Forwarded-For')) {
            return $peer;
        }
        $header = $request->getHeaderLine('X-Forwarded-For');
        if (strlen($header) > 8192) {
            throw new ProtectionException('invalid_forwarded_ip', 'The trusted proxy forwarding chain is too large.', FailureKind::Request);
        }
        $chain = explode(',', $header);
        if (count($chain) > 64) {
            throw new ProtectionException('invalid_forwarded_ip', 'The trusted proxy forwarding chain is too large.', FailureKind::Request);
        }
        // The direct peer is already trusted by the precheck above. Stop at the
        // first untrusted candidate, before interpreting any left-hand entries.
        for ($i = count($chain) - 1; $i >= 0; --$i) {
            $candidate = trim($chain[$i], " \t");
            if (!self::isValid($candidate)) {
                throw new ProtectionException('invalid_forwarded_ip', 'The trusted proxy forwarding chain contains an invalid IP.', FailureKind::Request);
            }
            $peer = $candidate;
            if (!self::trusted($peer, $trustedProxies)) {
                return $peer;
            }
        }
        return $peer;
    }
}
