<?php

declare(strict_types=1);

namespace Arcjet\Internal;

use Arcjet\FailureKind;
use Psr\Http\Message\StreamInterface;

/** @internal */
final class BoundedBody
{
    public static function read(StreamInterface $stream, int $limit, string $errorCode, FailureKind $failureKind): string
    {
        if (!$stream->isReadable() || ($stream->getSize() !== null && $stream->getSize() > $limit)) {
            throw new ProtectionException($errorCode, 'The HTTP body is unreadable or exceeds its configured size limit.', $failureKind);
        }
        $body = '';
        while (true) {
            $chunk = $stream->read(min(8192, $limit + 1 - strlen($body)));
            if ($chunk === '') {
                if ($stream->eof()) {
                    return $body;
                }
                throw new ProtectionException($errorCode, 'The HTTP body stream made no progress.', $failureKind);
            }
            $body .= $chunk;
            if (strlen($body) > $limit) {
                throw new ProtectionException($errorCode, 'The HTTP body exceeds its configured size limit.', $failureKind);
            }
        }
    }

    public static function request(StreamInterface $stream, int $limit): string
    {
        if (!$stream->isSeekable() || !$stream->isReadable()) {
            throw new ProtectionException('request_body', 'Opt-in body inspection requires a readable seekable stream.', FailureKind::Request);
        }
        $position = $stream->tell();
        try {
            $stream->rewind();
            return self::read($stream, $limit, 'request_body', FailureKind::Request);
        } finally {
            $stream->seek($position);
        }
    }
}
