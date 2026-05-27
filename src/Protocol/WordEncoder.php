<?php

declare(strict_types=1);

namespace Mivo\MikrotikRos6\Protocol;

/**
 * Encode the length of a word into the Mikrotik API binary format.
 *
 * FRAMEWORK-AGNOSTIC: Works in PHP Native, Laravel, CodeIgniter, Symfony, etc.
 *
 * The Mikrotik API protocol requires that every word sent over the socket
 * is prefixed by its length, encoded in a variable-length binary format.
 * This class implements that encoding.
 *
 * Length Encoding Rules (from Mikrotik API Documentation):
 * ┌─────────────────────────┬───────┬──────────────────────────────────┐
 * │ Range                   │ Bytes │ Encoding                         │
 * ├─────────────────────────┼───────┼──────────────────────────────────┤
 * │ L < 0x80                │   1   │ L as single byte                 │
 * │ L < 0x4000              │   2   │ L | 0x8000 as 2 bytes            │
 * │ L < 0x200000            │   3   │ L | 0xC00000 as 3 bytes          │
 * │ L < 0x10000000          │   4   │ L | 0xE0000000 as 4 bytes        │
 * │ L >= 0x10000000         │   5   │ 0xF0 prefix + L as 4 bytes       │
 * └─────────────────────────┴───────┴──────────────────────────────────┘
 *
 * @see https://wiki.mikrotik.com/wiki/Manual:API#API_words                 Official protocol specification
 * @see routeros_api.class.php::encodeLength() (Mikhmon v3, lines 65-83)   Battle-tested reference
 * @see RouterOS\APILengthCoDec::encodeLength() (EvilFreelancer)           Modern OOP reference
 */
class WordEncoder
{
    /**
     * Encode the length of a word into Mikrotik API binary format.
     *
     * @param  int  $length  The length of the word (number of bytes).
     *
     * @return string  Binary-encoded length prefix.
     *
     * @throws \InvalidArgumentException  If length is negative.
     */
    public static function encodeLength(int $length): string
    {
        if ($length < 0) {
            throw new \InvalidArgumentException("Word length cannot be negative ({$length}).");
        }

        if ($length < 0x80) {
            // 1 byte: length fits in 7 bits (0xxxxxxx)
            return chr($length);
        }

        if ($length < 0x4000) {
            // 2 bytes: set bit 7 of first byte (10xxxxxx xxxxxxxx)
            $length |= 0x8000;

            return chr(($length >> 8) & 0xFF)
                 . chr($length & 0xFF);
        }

        if ($length < 0x200000) {
            // 3 bytes: set bits 7-6 of first byte (110xxxxx xxxxxxxx xxxxxxxx)
            $length |= 0xC00000;

            return chr(($length >> 16) & 0xFF)
                 . chr(($length >> 8) & 0xFF)
                 . chr($length & 0xFF);
        }

        if ($length < 0x10000000) {
            // 4 bytes: set bits 7-5 of first byte (1110xxxx xxxxxxxx xxxxxxxx xxxxxxxx)
            $length |= 0xE0000000;

            return chr(($length >> 24) & 0xFF)
                 . chr(($length >> 16) & 0xFF)
                 . chr(($length >> 8) & 0xFF)
                 . chr($length & 0xFF);
        }

        // 5 bytes: 0xF0 prefix + 4 bytes of raw length
        return chr(0xF0)
             . chr(($length >> 24) & 0xFF)
             . chr(($length >> 16) & 0xFF)
             . chr(($length >> 8) & 0xFF)
             . chr($length & 0xFF);
    }

    /**
     * Encode a complete word: length prefix + word content.
     *
     * @param  string  $word  The word to encode (e.g. "/login", "=name=admin").
     *
     * @return string  Binary-encoded length prefix followed by the word itself.
     */
    public static function encodeWord(string $word): string
    {
        return self::encodeLength(strlen($word)) . $word;
    }
}
