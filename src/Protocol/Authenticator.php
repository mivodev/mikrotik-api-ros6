<?php

declare(strict_types=1);

namespace Mivo\MikrotikRos6\Protocol;

use Mivo\MikrotikRos6\Exceptions\MikrotikException;

/**
 * Handle Mikrotik RouterOS API authentication.
 *
 * FRAMEWORK-AGNOSTIC: Works in PHP Native, Laravel, CodeIgniter, Symfony, etc.
 *
 * Supports two login methods:
 * 1. Post-v6.43 (Plaintext): Username + password sent directly, router responds !done.
 * 2. Pre-v6.43 (MD5 Challenge): Router sends a token via =ret=, client computes
 *    MD5 hash of (0x00 + password + hex-decoded token) and sends it back.
 *
 * The authenticate() method auto-detects which method the router uses,
 * exactly like Mikhmon v3 does (lines 109-131).
 *
 * @see https://wiki.mikrotik.com/wiki/Manual:API#Initial_login             Official login documentation
 * @see routeros_api.class.php::connect() (Mikhmon v3, lines 95-144)       Dual-login reference
 */
class Authenticator
{
    /**
     * Authenticate against a RouterOS device.
     *
     * Auto-detects login method:
     * - If router responds with !done + =ret= token → MD5 challenge (pre-v6.43)
     * - If router responds with !done only → plaintext accepted (post-v6.43)
     *
     * @param  resource  $socket  An open, connected socket resource.
     * @param  string  $username  RouterOS username.
     * @param  string  $password  RouterOS password.
     * @return bool True if authentication succeeded.
     *
     * @throws MikrotikException If authentication fails.
     */
    public static function authenticate($socket, string $username, string $password): bool
    {
        // Step 1: Send initial /login with credentials (post-v6.43 style)
        self::writeWord($socket, '/login');
        self::writeWord($socket, '=name='.$username);
        self::writeWord($socket, '=password='.$password);
        self::writeSentenceEnd($socket);

        // Step 2: Read the response
        $response = self::readSentence($socket);

        if (isset($response[0]) && $response[0] === '!done') {
            if (! isset($response[1])) {
                // Post-v6.43: Router accepted plaintext login immediately
                return true;
            }

            // Pre-v6.43: Router sent back a challenge token in =ret=
            $matches = [];
            if (preg_match_all('/[^=]+/i', $response[1], $matches)) {
                if ($matches[0][0] === 'ret' && strlen($matches[0][1]) === 32) {
                    return self::respondToChallenge(
                        $socket,
                        $username,
                        $password,
                        $matches[0][1]
                    );
                }
            }
        }

        throw MikrotikException::authenticationFailed('unknown');
    }

    /**
     * Respond to the MD5 challenge from pre-v6.43 RouterOS.
     *
     * Calculates: MD5( 0x00 + password + hex2bin(challenge_token) )
     * Then sends: /login, =name=..., =response=00<hash>
     *
     * @param  resource  $socket  Open socket.
     * @param  string  $username  RouterOS username.
     * @param  string  $password  RouterOS password.
     * @param  string  $challenge  32-char hex string from =ret= response.
     * @return bool True if the challenge response was accepted.
     *
     * @throws MikrotikException If authentication fails.
     *
     * @see https://wiki.mikrotik.com/wiki/Manual:API#Initial_login  MD5 challenge documentation
     */
    private static function respondToChallenge(
        $socket,
        string $username,
        string $password,
        string $challenge,
    ): bool {
        // Compute MD5: null byte + password + binary challenge
        $hash = md5(chr(0).$password.pack('H*', $challenge));

        self::writeWord($socket, '/login');
        self::writeWord($socket, '=name='.$username);
        self::writeWord($socket, '=response=00'.$hash);
        self::writeSentenceEnd($socket);

        $response = self::readSentence($socket);

        if (isset($response[0]) && $response[0] === '!done') {
            return true;
        }

        throw MikrotikException::authenticationFailed('unknown');
    }

    /**
     * Write a single encoded word to the socket.
     *
     * @param  resource  $socket  Open socket.
     * @param  string  $word  Word to write.
     */
    private static function writeWord($socket, string $word): void
    {
        fwrite($socket, WordEncoder::encodeWord($word));
    }

    /**
     * Write the sentence terminator (empty word / 0x00) to the socket.
     *
     * @param  resource  $socket  Open socket.
     */
    private static function writeSentenceEnd($socket): void
    {
        fwrite($socket, chr(0));
    }

    /**
     * Read a complete sentence (sequence of words until empty word) from the socket.
     *
     * @param  resource  $socket  Open socket.
     * @return array<int,string> Array of words in the sentence.
     */
    private static function readSentence($socket): array
    {
        $sentence = [];

        while (true) {
            $word = WordDecoder::readWord($socket);

            if ($word === '') {
                break; // Empty word = end of sentence
            }

            $sentence[] = $word;
        }

        return $sentence;
    }
}
