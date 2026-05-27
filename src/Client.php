<?php

declare(strict_types=1);

namespace Mivo\MikrotikRos6;

use Mivo\MikrotikRos6\Contracts\ClientInterface;
use Mivo\MikrotikRos6\Exceptions\MikrotikException;
use Mivo\MikrotikRos6\Parser\ResponseParser;
use Mivo\MikrotikRos6\Protocol\Authenticator;
use Mivo\MikrotikRos6\Protocol\WordDecoder;
use Mivo\MikrotikRos6\Protocol\WordEncoder;

/**
 * Complete, universal client for Mikrotik RouterOS v6 API (Socket Port 8728).
 *
 * FRAMEWORK-AGNOSTIC: Works in PHP Native, Laravel, CodeIgniter, Symfony, etc.
 * ZERO-DEPENDENCY: Only uses built-in PHP functions (streams, md5, chr, ord).
 *
 * This client can execute ANY RouterOS command — it is not limited to
 * specific features. Use comm() to send any command the router supports.
 *
 * Usage:
 *   $api = new Client();
 *   $api->connect('192.168.1.1', 'admin', 'password');
 *
 *   $users = $api->comm('/ip/hotspot/user/print');
 *   $api->comm('/ip/hotspot/user/add', ['name' => 'test', 'password' => '123']);
 *   $queues = $api->comm('/queue/simple/print');
 *   $identity = $api->comm('/system/identity/print');
 *
 *   $api->disconnect();
 *
 * @see https://help.mikrotik.com/docs/display/ROS/API                     RouterOS API (ROS7 docs)
 * @see https://wiki.mikrotik.com/wiki/Manual:API                          RouterOS API (ROS6 legacy docs)
 * @see routeros_api.class.php (Mikhmon v3)                                Battle-tested PHP reference
 * @see RouterOS\Client (EvilFreelancer/routeros-api-php)                  Modern OOP reference
 */
class Client implements ClientInterface
{
    /**
     * Whether we are currently connected and authenticated.
     */
    protected bool $connected = false;

    /**
     * The socket resource for communication with the router.
     *
     * @var resource|null
     */
    protected $socket = null;

    /**
     * The host we are connected to (for error messages).
     */
    protected string $host = '';

    /**
     * Enable/disable debug output.
     */
    public bool $debug = false;

    /**
     * Connection timeout in seconds.
     */
    public int $timeout = 3;

    /**
     * Number of connection retry attempts.
     */
    public int $attempts = 5;

    /**
     * Delay between retry attempts in seconds.
     */
    public int $delay = 3;

    /**
     * Use SSL connection (port 8729).
     */
    public bool $ssl = false;

    /**
     * {@inheritdoc}
     */
    public function connect(string $host, string $username = 'admin', string $password = '', int $port = 8728): bool
    {
        $this->host = $host;

        for ($attempt = 1; $attempt <= $this->attempts; $attempt++) {
            $this->connected = false;

            $protocol = $this->ssl ? 'ssl://' : '';
            $address = $protocol.$host.':'.$port;

            $this->debug("Connection attempt #{$attempt} to {$address}...");

            $context = stream_context_create([
                'ssl' => [
                    'ciphers' => 'ADH:ALL',
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);

            $socket = @stream_socket_client(
                $address,
                $errorNo,
                $errorStr,
                $this->timeout,
                STREAM_CLIENT_CONNECT,
                $context,
            );

            if ($socket !== false) {
                stream_set_timeout($socket, $this->timeout);
                $this->socket = $socket;

                try {
                    if (Authenticator::authenticate($this->socket, $username, $password)) {
                        $this->connected = true;
                        $this->debug('Connected and authenticated.');

                        return true;
                    }
                } catch (MikrotikException) {
                    @fclose($this->socket);
                    $this->socket = null;
                }
            }

            if ($attempt < $this->attempts) {
                $this->debug("Retrying in {$this->delay} seconds...");
                sleep($this->delay);
            }
        }

        throw MikrotikException::connectionFailed($host, $port, $errorStr ?? 'Max attempts reached');
    }

    /**
     * {@inheritdoc}
     */
    public function disconnect(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }

        $this->socket = null;
        $this->connected = false;
        $this->debug('Disconnected.');
    }

    /**
     * {@inheritdoc}
     */
    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * Execute any RouterOS command and return parsed results.
     *
     * Parameter key prefixes:
     * - No prefix  → attribute:  ['name' => 'test']       → =name=test
     * - "?"        → query:      ['?user' => 'test']      → ?user=test
     * - "~"        → regex:      ['~name' => 'pattern']   → ~name~pattern
     *
     * @see https://wiki.mikrotik.com/wiki/Manual:API#Command_word  Command word format
     * @see https://wiki.mikrotik.com/wiki/Manual:API#Queries       Query syntax
     * @see routeros_api.class.php::comm() (Mikhmon v3, lines 400-425)  Reference implementation
     *
     * {@inheritdoc}
     */
    public function comm(string $command, array $params = []): array
    {
        if (! $this->connected || $this->socket === null) {
            throw MikrotikException::notConnected();
        }

        $count = count($params);

        // Write the command word
        $this->write($command, $count === 0);

        // Write parameter words
        $i = 0;
        foreach ($params as $key => $value) {
            $key = (string) $key;

            // Determine word format based on key prefix
            $word = match (true) {
                str_starts_with($key, '?') => "{$key}={$value}",
                str_starts_with($key, '~') => "{$key}~{$value}",
                default => "={$key}={$value}",
            };

            $last = (++$i === $count);
            $this->write($word, $last);
        }

        return $this->read();
    }

    /**
     * Write a word to the socket, optionally ending the sentence.
     *
     * @param  string  $command  The word to send.
     * @param  bool  $endSentence  If true, append a null byte to end the sentence.
     *
     * @see routeros_api.class.php::write() (Mikhmon v3, lines 368-389)  Reference implementation
     */
    protected function write(string $command, bool $endSentence = true): void
    {
        $data = explode("\n", $command);

        foreach ($data as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            fwrite($this->socket, WordEncoder::encodeWord($line));
            $this->debug("<<< [{$line}]");
        }

        if ($endSentence) {
            fwrite($this->socket, chr(0));
        }
    }

    /**
     * Read a complete response from the socket (all sentences until !done).
     *
     * @param  bool  $parse  Whether to parse the response. Default: true.
     * @return array Parsed response (if $parse=true) or raw word array.
     *
     * @see routeros_api.class.php::read() (Mikhmon v3, lines 281-354)  Reference implementation
     */
    protected function read(bool $parse = true): array
    {
        $response = [];
        $receivedDone = false;

        while (true) {
            $word = WordDecoder::readWord($this->socket);

            if ($word !== '') {
                $response[] = $word;
                $this->debug(">>> [{$word}]");
            }

            if ($word === '!done') {
                $receivedDone = true;
            }

            // Check if we should stop reading
            $status = @stream_get_meta_data($this->socket);
            $unreadBytes = $status['unread_bytes'] ?? 0;

            if (
                (! $this->connected && $unreadBytes === 0)
                || ($this->connected && $unreadBytes === 0 && $receivedDone)
            ) {
                break;
            }
        }

        if ($parse) {
            return ResponseParser::parse($response);
        }

        return $response;
    }

    /**
     * Print debug message if debugging is enabled.
     */
    protected function debug(string $message): void
    {
        if ($this->debug) {
            echo $message."\n";
        }
    }

    /**
     * Automatically disconnect when the object is destroyed.
     */
    public function __destruct()
    {
        $this->disconnect();
    }
}
