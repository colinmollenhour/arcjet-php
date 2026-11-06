<?php

declare(strict_types=1);

namespace Arcjet\Internal;

use Google\Protobuf\Descriptor;
use Google\Protobuf\Internal\GPBType;

/**
 * @internal Structural preflight only; generated google/protobuf owns decoding.
 * Its pure-PHP parser may silently ignore an unterminated trailing tag. Validate
 * framing (including known nested messages) before accepting any conclusion.
 */
final class ProtobufGuard
{
    public static function validate(string $bytes, Descriptor $descriptor): void
    {
        self::message($bytes, 0, strlen($bytes), $descriptor, 0);
    }

    private static function message(string $bytes, int $start, int $end, Descriptor $descriptor, int $depth): void
    {
        if ($depth > 64) {
            self::invalid();
        }
        $messages = [];
        for ($i = 0; $i < $descriptor->getFieldCount(); ++$i) {
            $field = $descriptor->getField($i);
            if ($field->getType() === GPBType::MESSAGE) {
                $messages[$field->getNumber()] = $field->getMessageType();
            }
        }
        $position = $start;
        while ($position < $end) {
            $tag = self::smallVarint($bytes, $position, $end);
            $number = $tag >> 3;
            if ($number === 0) {
                self::invalid();
            }
            switch ($tag & 7) {
                case 0:
                    self::skipVarint($bytes, $position, $end);
                    break;
                case 1:
                    $position += 8;
                    break;
                case 2:
                    $length = self::smallVarint($bytes, $position, $end);
                    if ($length > $end - $position) {
                        self::invalid();
                    }
                    if (isset($messages[$number])) {
                        self::message($bytes, $position, $position + $length, $messages[$number], $depth + 1);
                    }
                    $position += $length;
                    break;
                case 5:
                    $position += 4;
                    break;
                default:
                    // The pinned proto3 schema has no groups. Unknown ordinary
                    // fields remain supported and are preserved by protobuf.
                    self::invalid();
            }
            if ($position > $end) {
                self::invalid();
            }
        }
    }

    private static function smallVarint(string $bytes, int &$position, int $end): int
    {
        $value = 0;
        for ($i = 0; $i < 5 && $position < $end; ++$i) {
            $byte = ord($bytes[$position++]);
            if ($i === 4 && $byte > 15) {
                self::invalid();
            }
            $value |= ($byte & 127) << ($i * 7);
            if ($byte < 128) {
                return $value;
            }
        }
        self::invalid();
    }

    private static function skipVarint(string $bytes, int &$position, int $end): void
    {
        for ($i = 0; $i < 10 && $position < $end; ++$i) {
            $byte = ord($bytes[$position++]);
            if ($i === 9 && $byte > 1) {
                self::invalid();
            }
            if ($byte < 128) {
                return;
            }
        }
        self::invalid();
    }

    private static function invalid(): never
    {
        throw new ProtectionException('malformed_response', 'Arcjet returned malformed protobuf.');
    }
}
