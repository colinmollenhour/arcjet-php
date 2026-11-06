<?php

declare(strict_types=1);

namespace Arcjet\Internal;

/** @internal Text normalization for protobuf string fields; never applied to body bytes. */
final class HttpMetadata
{
    /** Preserve valid UTF-8; interpret otherwise unrepresentable HTTP obs-text as Latin-1. */
    public static function headerValue(string $value): string
    {
        if (preg_match('//u', $value) === 1) {
            return $value;
        }
        $utf8 = '';
        for ($i = 0, $length = strlen($value); $i < $length; ++$i) {
            $byte = ord($value[$i]);
            $utf8 .= $byte < 128 ? $value[$i] : chr(0xc0 | ($byte >> 6)) . chr(0x80 | ($byte & 0x3f));
        }
        return $utf8;
    }

    /** Match percent-decoded path replacement semantics without a custom Unicode decoder. */
    public static function substituteInvalid(string $value): string
    {
        if (preg_match('//u', $value) === 1) {
            return $value;
        }
        $normalized = json_decode(json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        if (!is_string($normalized)) {
            throw new \LogicException('UTF-8 string normalization did not return a string.');
        }
        return $normalized;
    }

    /** Server raw metadata is optional evidence, never a reason to reject otherwise representable context. */
    public static function isOriginTarget(mixed $target): bool
    {
        return is_string($target) && str_starts_with($target, '/') && preg_match('/[^\x21-\x7e]|#/', $target) === 0;
    }
}
