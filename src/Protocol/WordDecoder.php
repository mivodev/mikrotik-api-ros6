<?php

declare(strict_types=1);

namespace Mivo\MikrotikRos6\Protocol;

use Mivo\MikrotikRos6\Exceptions\MikrotikException;

/**
 * Decode length-prefixed words from a Mikrotik API socket stream.
 *
 * FRAMEWORK-AGNOSTIC: Works in PHP Native, Laravel, CodeIgniter, Symfony, etc.
 *
 * The reverse operation of WordEncoder. Reads the variable-length
 * binary prefix from the socket to determine how many bytes to read
 * for the actual word content.
 *
 * Decoding Rules (from Mikrotik API Documentation):
 * ┌──────────────┬───────────────┬──────────────────────────────────┐
 * │ First byte   │ Total bytes   │ Decoding                         │
 * ├──────────────┼───────────────┼──────────────────────────────────┤
 * │ 0xxxxxxx     │ 1             │ Length = byte value               │
 * │ 10xxxxxx     │ 2             │ Strip top 2 bits, read 1 more    │
 * │ 110xxxxx     │ 3             │ Strip top 3 bits, read 2 more    │
 * │ 1110xxxx     │ 4             │ Strip top 4 bits, read 3 more    │
 * │ 11110xxx     │ 5             │ Strip top 5 bits, read 4 more    │
 * └──────────────┴───────────────┴──────────────────────────────────┘
 *
 * @see https://wiki.mikrotik.com/wiki/Manual:API#API_words                 Official protocol specification
 * @see routeros_api.class.php::read() (Mikhmon v3, lines 281-354)         Battle-tested reference
 * @see RouterOS\APILengthCoDec::decodeLength() (EvilFreelancer)           Modern OOP reference
 */
class WordDecoder
{
    /**
     * Read and decode the length prefix from a socket stream.
     *
     * @param  resource  $socket  An open socket resource (from stream_socket_client or fsockopen).
     * @return int The decoded length (number of bytes to read for the word content).
     *
     * @throws MikrotikException If the socket read fails.
     */
    public static function decodeLength($socket): int
    {
        $byte = self::readBytes($socket, 1);
        $firstByte = ord($byte);

        // 0xxxxxxx — 1-byte length (0–127)
        if (($firstByte & 0x80) === 0) {
            return $firstByte;
        }

        // 10xxxxxx — 2-byte length
        if (($firstByte & 0xC0) === 0x80) {
            $length = ($firstByte & 0x3F) << 8;
            $length += ord(self::readBytes($socket, 1));

            return $length;
        }

        // 110xxxxx — 3-byte length
        if (($firstByte & 0xE0) === 0xC0) {
            $length = ($firstByte & 0x1F) << 16;
            $length += ord(self::readBytes($socket, 1)) << 8;
            $length += ord(self::readBytes($socket, 1));

            return $length;
        }

        // 1110xxxx — 4-byte length
        if (($firstByte & 0xF0) === 0xE0) {
            $length = ($firstByte & 0x0F) << 24;
            $length += ord(self::readBytes($socket, 1)) << 16;
            $length += ord(self::readBytes($socket, 1)) << 8;
            $length += ord(self::readBytes($socket, 1));

            return $length;
        }

        // 11110xxx — 5-byte length
        if (($firstByte & 0xF8) === 0xF0) {
            // Skip the prefix byte, read 4 raw bytes as length
            $length = ord(self::readBytes($socket, 1)) << 24;
            $length += ord(self::readBytes($socket, 1)) << 16;
            $length += ord(self::readBytes($socket, 1)) << 8;
            $length += ord(self::readBytes($socket, 1));

            return $length;
        }

        // 11111xxx — Control byte (reserved, not implemented by Mikrotik)
        throw MikrotikException::fatal('Received unknown control byte: 0x'.dechex($firstByte));
    }

    /**
     * Read a complete word from the socket (length-prefix + content).
     *
     * @param  resource  $socket  An open socket resource.
     * @return string The decoded word content, or empty string if length is 0 (sentence terminator).
     *
     * @throws MikrotikException If the socket read fails.
     */
    public static function readWord($socket): string
    {
        $length = self::decodeLength($socket);

        if ($length === 0) {
            return '';
        }

        return self::readBytes($socket, $length);
    }

    /**
     * Read an exact number of bytes from the socket.
     *
     * Handles partial reads (when fread returns fewer bytes than requested)
     * by looping until all bytes are received.
     *
     * @param  resource  $socket  An open socket resource.
     * @param  int  $length  Number of bytes to read.
     * @return string The raw bytes read.
     *
     * @throws MikrotikException If the socket is closed or times out.
     *
     * @see routeros_api.class.php::read() (Mikhmon v3, lines 322-329)  Partial-read loop reference
     */
    private static function readBytes($socket, int $length): string
    {
        $data = '';
        $bytesRead = 0;

        while ($bytesRead < $length) {
            $chunk = @fread($socket, $length - $bytesRead);

            if ($chunk === false || $chunk === '') {
                $meta = @stream_get_meta_data($socket);
                if (is_array($meta) && ($meta['timed_out'] ?? false)) {
                    throw MikrotikException::timeout('unknown');
                }
                throw MikrotikException::fatal('Socket read failed. Connection may have been closed by the router.');
            }

            $data .= $chunk;
            $bytesRead = strlen($data);
        }

        return $data;
    }
}
