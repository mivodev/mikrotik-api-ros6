<?php

declare(strict_types=1);

namespace Mivo\MikrotikRos6\Contracts;

/**
 * Contract for Mikrotik RouterOS API clients.
 *
 * This interface is FRAMEWORK-AGNOSTIC. It can be used in:
 * - PHP Native (vanilla PHP projects)
 * - Laravel (via mivodev/laravel-mikrotik-api-ros6 wrapper)
 * - CodeIgniter 3, CodeIgniter 4
 * - Symfony
 * - Any other PHP framework
 *
 * Any transport implementation (Socket for ROS6, REST for ROS7) must
 * satisfy this interface so that consuming code can swap transports
 * without changing business logic.
 *
 * @see https://help.mikrotik.com/docs/display/ROS/API                     RouterOS API Documentation (ROS7)
 * @see https://wiki.mikrotik.com/wiki/Manual:API                          RouterOS API Documentation (ROS6 Legacy)
 * @see https://wiki.mikrotik.com/wiki/Manual:API#API_words                Word & Sentence Protocol
 * @see https://wiki.mikrotik.com/wiki/Manual:API#Initial_login            Login Protocol (MD5 Challenge)
 */
interface ClientInterface
{
    /**
     * Open a connection to the router and authenticate.
     *
     * @param  string  $host      IP address or hostname of the router.
     * @param  string  $username  RouterOS username (default: "admin").
     * @param  string  $password  RouterOS password (default: "").
     * @param  int     $port      API port (default: 8728, or 8729 for SSL).
     *
     * @return bool  True if connection and authentication succeeded.
     *
     * @throws \Mivo\MikrotikRos6\Exceptions\MikrotikException
     */
    public function connect(string $host, string $username = 'admin', string $password = '', int $port = 8728): bool;

    /**
     * Close the connection to the router.
     */
    public function disconnect(): void;

    /**
     * Whether we currently hold an active, authenticated connection.
     */
    public function isConnected(): bool;

    /**
     * Execute any RouterOS command and return parsed results.
     *
     * This is the universal entry-point — it can run ANY command
     * the router supports, not just a pre-defined subset.
     *
     * Examples:
     *   $client->comm('/ip/address/print');
     *   $client->comm('/ip/hotspot/user/add', ['name' => 'test', 'password' => '123']);
     *   $client->comm('/ip/hotspot/active/print', ['?user' => 'test']);
     *   $client->comm('/queue/simple/print');
     *   $client->comm('/system/identity/print');
     *
     * @param  string               $command  RouterOS command path.
     * @param  array<string,string>  $params   Key-value pairs for command attributes and queries.
     *                                         Prefix key with "?" for queries: ['?user' => 'test']
     *                                         Prefix key with "~" for regex:   ['~name' => 'pattern']
     *                                         No prefix for attributes:        ['name' => 'value']
     *
     * @return array<int,array<string,string>>  Parsed response rows.
     *
     * @throws \Mivo\MikrotikRos6\Exceptions\MikrotikException
     *
     * @see https://wiki.mikrotik.com/wiki/Manual:API#Command_word  Command word format
     * @see https://wiki.mikrotik.com/wiki/Manual:API#Queries       Query syntax
     */
    public function comm(string $command, array $params = []): array;
}
