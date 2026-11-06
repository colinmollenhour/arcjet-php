<?php

declare(strict_types=1);

namespace Arcjet\Internal;

use Psr\Http\Message\StreamInterface;

/** @internal */
final class BoundedBody
{
    public static function read(StreamInterface $stream, int $limit, string $errorCode): string
    {
        if (!$stream->isReadable() || ($stream->getSize() !== null && $stream->getSize() > $limit)) {
            throw new ProtectionException($errorCode, 'The HTTP body is unreadable or exceeds its configured size limit.');
        }
        $body = '';
        while (true) {
            $chunk = $stream->read(min(8192, $limit + 1 - strlen($body)));
            if ($chunk === '') {
                if ($stream->eof()) {
                    return $body;
                }
                throw new ProtectionException($errorCode, 'The HTTP body stream made no progress.');
            }
            $body .= $chunk;
            if (strlen($body) > $limit) {
                throw new ProtectionException($errorCode, 'The HTTP body exceeds its configured size limit.');
            }
        }
    }

    public static function request(StreamInterface $stream, int $limit): string
    {
        if (!$stream->isSeekable() || !$stream->isReadable()) {
            throw new ProtectionException('request_body', 'Opt-in body inspection requires a readable seekable stream.');
        }
        $position = $stream->tell();
        try {
            $stream->rewind();
            return self::read($stream, $limit, 'request_body');
        } finally {
            $stream->seek($position);
        }
    }
}
