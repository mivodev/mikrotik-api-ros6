<?php

declare(strict_types=1);

namespace Mivo\MikrotikRos6\Exceptions;

use RuntimeException;

/**
 * Exception thrown when a Mikrotik API operation fails.
 *
 * Covers connection errors, authentication failures, socket timeouts,
 * and RouterOS !trap / !fatal responses.
 */
class MikrotikException extends RuntimeException
{
    /**
     * Create exception for connection failure.
     */
    public static function connectionFailed(string $host, int $port, string $reason = ''): self
    {
        $message = "Failed to connect to {$host}:{$port}";
        if ($reason !== '') {
            $message .= " — {$reason}";
        }

        return new self($message);
    }

    /**
     * Create exception for authentication failure.
     */
    public static function authenticationFailed(string $host): self
    {
        return new self("Authentication failed on {$host}. Check username and password.");
    }

    /**
     * Create exception from a RouterOS !trap response.
     */
    public static function fromTrap(string $message, ?string $category = null): self
    {
        $prefix = $category !== null ? "[{$category}] " : '';

        return new self("RouterOS trap: {$prefix}{$message}");
    }

    /**
     * Create exception from a RouterOS !fatal response.
     */
    public static function fatal(string $message): self
    {
        return new self("RouterOS fatal: {$message}");
    }

    /**
     * Create exception for socket read/write timeout.
     */
    public static function timeout(string $host): self
    {
        return new self("Socket timeout while communicating with {$host}");
    }

    /**
     * Create exception when attempting to operate on a closed connection.
     */
    public static function notConnected(): self
    {
        return new self('Not connected to any router. Call connect() first.');
    }
}
