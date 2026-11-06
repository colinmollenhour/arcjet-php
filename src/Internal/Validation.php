<?php

declare(strict_types=1);

namespace Arcjet\Internal;

use Arcjet\Rule\BotProtection;
use Arcjet\Rule\Shield;

/** @internal */
final class Validation
{
    /** @param array<array-key, mixed> $rules */
    public static function rules(array $rules): void
    {
        if (!array_is_list($rules)) {
            throw new \InvalidArgumentException('rules must be a list.');
        }
        foreach ($rules as $rule) {
            if (!$rule instanceof Shield && !$rule instanceof BotProtection) {
                throw new \InvalidArgumentException('Only Shield and BotProtection rules are supported.');
            }
        }
    }

    /** @param array<array-key, mixed> $values */
    public static function stringList(array $values, string $name): void
    {
        if (!array_is_list($values)) {
            throw new \InvalidArgumentException($name . ' must be a list.');
        }
        foreach ($values as $value) {
            if (!is_string($value) || $value === '' || preg_match('/[\x00-\x20\x7f]/', $value)) {
                throw new \InvalidArgumentException($name . ' must contain nonempty strings without whitespace/control characters.');
            }
        }
    }

    public static function httpsUrl(string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https'
            || !isset($parts['host']) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException('URL must be absolute HTTPS without credentials, query, fragment, whitespace, or backslashes.');
        }
    }
}
