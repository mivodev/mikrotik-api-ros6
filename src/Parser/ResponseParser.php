<?php

declare(strict_types=1);

namespace Mivo\MikrotikRos6\Parser;

/**
 * Parse raw Mikrotik API responses into structured PHP arrays.
 *
 * FRAMEWORK-AGNOSTIC: Works in PHP Native, Laravel, CodeIgniter, Symfony, etc.
 *
 * RouterOS responses consist of sentences with reply words:
 * - !re     → Data row (repeating record)
 * - !done   → Command completed successfully
 * - !trap   → Error occurred
 * - !fatal  → Fatal error, connection will be closed
 *
 * Each data word uses the format: =key=value (e.g. =name=admin, =.id=*1)
 *
 * @see https://wiki.mikrotik.com/wiki/Manual:API#Command_word              Response word types
 * @see routeros_api.class.php::parseResponse() (Mikhmon v3, lines 170-202) Battle-tested reference
 */
class ResponseParser
{
    /**
     * Parse a raw response array into structured associative arrays.
     *
     * Input format (raw words from socket):
     *   ['!re', '=.id=*1', '=name=admin', '!re', '=.id=*2', '=name=guest', '!done']
     *
     * Output format (structured):
     *   [
     *     ['.id' => '*1', 'name' => 'admin'],
     *     ['.id' => '*2', 'name' => 'guest'],
     *   ]
     *
     * @param  array<int,string>  $response  Raw words from the socket response.
     * @return array<int|string,mixed> Parsed response. Numeric keys for !re rows,
     *                                 '!trap' key for error data, '!fatal' key for fatal data.
     */
    public static function parse(array $response): array
    {
        if (empty($response)) {
            return [];
        }

        $parsed = [];
        $current = null;
        $singleValue = null;

        foreach ($response as $word) {
            // Reply words — start a new record
            if (in_array($word, ['!re', '!trap', '!fatal'], true)) {
                if ($word === '!re') {
                    $parsed[] = [];
                    $current = &$parsed[array_key_last($parsed)];
                } else {
                    $parsed[$word] ??= [];
                    $parsed[$word][] = [];
                    $current = &$parsed[$word][array_key_last($parsed[$word])];
                }

                continue;
            }

            // Skip !done — it's just a completion marker
            if ($word === '!done') {
                continue;
            }

            // Parse =key=value pairs
            if ($current !== null && str_starts_with($word, '=')) {
                $withoutPrefix = substr($word, 1); // Remove leading "="
                $eqPos = strpos($withoutPrefix, '=');

                if ($eqPos !== false) {
                    $key = substr($withoutPrefix, 0, $eqPos);
                    $value = substr($withoutPrefix, $eqPos + 1);
                    $current[$key] = $value;
                } else {
                    // Handle =ret=value format (single return value)
                    $current[$withoutPrefix] = '';
                }

                // Track single return values (like =ret= from /login)
                if (isset($current['ret'])) {
                    $singleValue = $current['ret'];
                }
            }
        }

        // If no !re rows but we got a single value, return it
        if (empty($parsed) && $singleValue !== null) {
            return $singleValue;
        }

        return $parsed;
    }

    /**
     * Check if a parsed response contains a !trap error.
     *
     * @param  array<int|string,mixed>  $parsed  Output from parse().
     * @return bool True if the response contains error data.
     */
    public static function hasTrap(array $parsed): bool
    {
        return isset($parsed['!trap']);
    }

    /**
     * Check if a parsed response contains a !fatal error.
     *
     * @param  array<int|string,mixed>  $parsed  Output from parse().
     * @return bool True if the response contains fatal error data.
     */
    public static function hasFatal(array $parsed): bool
    {
        return isset($parsed['!fatal']);
    }

    /**
     * Extract the error message from a !trap response.
     *
     * @param  array<int|string,mixed>  $parsed  Output from parse().
     * @return string|null The error message, or null if no trap found.
     */
    public static function getTrapMessage(array $parsed): ?string
    {
        if (! self::hasTrap($parsed)) {
            return null;
        }

        return $parsed['!trap'][0]['message'] ?? 'Unknown error';
    }
}
