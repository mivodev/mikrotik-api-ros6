# Mikrotik API ROS6

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-8892BF.svg)](https://php.net)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

Complete, universal PHP client for **Mikrotik RouterOS v6 API** via Socket Protocol (Port 8728). Framework-agnostic with zero external dependencies.

## Features

- **Universal API Client** — Execute ANY RouterOS command, not just a pre-defined subset
- **Dual-Mode Login** — Auto-detects MD5 Challenge (pre-v6.43) and Plaintext (post-v6.43)
- **Zero Dependencies** — Only built-in PHP functions (`stream_socket_client`, `md5`, `chr`, `ord`)
- **Framework-Agnostic** — Works with PHP Native, Laravel, CodeIgniter, Symfony, or any PHP framework
- **SSL Support** — Connect via port 8729 with SSL encryption
- **Auto-Retry** — Configurable connection retry attempts with delay
- **Auto-Parsing** — Raw Mikrotik responses converted to structured PHP arrays

## Requirements

- PHP >= 8.2
- Network access to Mikrotik router on port 8728 (or 8729 for SSL)

## Installation

### Via Composer (Recommended)

```bash
composer require mivodev/mikrotik-api-ros6
```

### For Laravel Projects

Use the Laravel wrapper instead, which provides ServiceProvider, Facade, and `.env` configuration:

```bash
composer require mivodev/laravel-mikrotik-api-ros6
```

## Quick Start

```php
<?php

use Mivo\MikrotikRos6\Client;

$api = new Client();
$api->connect('192.168.1.1', 'admin', 'password');

// Get router identity
$identity = $api->comm('/system/identity/print');
print_r($identity);

$api->disconnect();
```

## Usage

### Connecting to a Router

```php
use Mivo\MikrotikRos6\Client;

$api = new Client();

// Basic connection
$api->connect('192.168.1.1', 'admin', 'password');

// Custom port
$api->connect('192.168.1.1', 'admin', 'password', 8728);

// SSL connection (port 8729)
$api->ssl = true;
$api->connect('192.168.1.1', 'admin', 'password', 8729);
```

### Configuration Options

```php
$api = new Client();

$api->timeout  = 3;     // Connection timeout in seconds (default: 3)
$api->attempts = 5;     // Number of retry attempts (default: 5)
$api->delay    = 3;     // Delay between retries in seconds (default: 3)
$api->ssl      = false; // Use SSL connection (default: false)
$api->debug    = false; // Enable debug output (default: false)
```

### Executing Commands

The `comm()` method is the universal entry-point. It can execute **any** RouterOS command:

```php
// ─── READ (print) ───────────────────────────────────
$users    = $api->comm('/ip/hotspot/user/print');
$queues   = $api->comm('/queue/simple/print');
$addrs    = $api->comm('/ip/address/print');
$secrets  = $api->comm('/ppp/secret/print');
$ifaces   = $api->comm('/interface/print');
$firewall = $api->comm('/ip/firewall/filter/print');
$dns      = $api->comm('/ip/dns/print');
$routes   = $api->comm('/ip/route/print');
$resource = $api->comm('/system/resource/print');

// ─── CREATE (add) ───────────────────────────────────
$api->comm('/ip/hotspot/user/add', [
    'name'     => 'pelanggan-baru',
    'password' => 'rahasia123',
    'profile'  => 'paket-50mbps',
]);

$api->comm('/ppp/secret/add', [
    'name'     => 'pppoe-user1',
    'password' => 'secret',
    'service'  => 'pppoe',
    'profile'  => 'default',
]);

$api->comm('/queue/simple/add', [
    'name'       => 'queue-pelanggan',
    'target'     => '192.168.1.100/32',
    'max-limit'  => '10M/10M',
]);

// ─── UPDATE (set) ───────────────────────────────────
$api->comm('/ip/hotspot/user/set', [
    '.id'      => '*1',
    'password' => 'password-baru',
]);

// ─── DELETE (remove) ────────────────────────────────
$api->comm('/ip/hotspot/user/remove', [
    '.id' => '*1',
]);

// ─── SYSTEM OPERATIONS ─────────────────────────────
$api->comm('/system/reboot');
$api->comm('/system/identity/set', [
    'name' => 'Router-Mivo',
]);
```

### Filtering & Queries

Use the `?` prefix on keys to filter results:

```php
// Filter by exact value
$activeUsers = $api->comm('/ip/hotspot/active/print', [
    '?user' => 'pelanggan-baru',
]);

// Filter PPPoE secrets by service type
$pppoeOnly = $api->comm('/ppp/secret/print', [
    '?service' => 'pppoe',
]);

// Filter by profile
$premium = $api->comm('/ip/hotspot/user/print', [
    '?profile' => 'paket-premium',
]);
```

### Regex Filtering

Use the `~` prefix for regex-based filtering:

```php
// Find users whose name matches a pattern
$matched = $api->comm('/ip/hotspot/user/print', [
    '~name' => 'pelanggan-.*',
]);
```

### Response Format

All responses are returned as structured PHP arrays:

```php
$users = $api->comm('/ip/hotspot/user/print');

// Returns:
// [
//     ['.id' => '*1', 'name' => 'admin', 'profile' => 'default'],
//     ['.id' => '*2', 'name' => 'pelanggan1', 'profile' => 'paket-50mbps'],
// ]

foreach ($users as $user) {
    echo $user['name'] . ' — ' . $user['profile'] . "\n";
}
```

### Error Handling

```php
use Mivo\MikrotikRos6\Client;
use Mivo\MikrotikRos6\Exceptions\MikrotikException;

$api = new Client();

try {
    $api->connect('192.168.1.1', 'admin', 'wrong-password');
} catch (MikrotikException $e) {
    echo "Connection failed: " . $e->getMessage();
}

try {
    $result = $api->comm('/nonexistent/command');
} catch (MikrotikException $e) {
    echo "Command failed: " . $e->getMessage();
}
```

### Debug Mode

Enable debug mode to see all sent and received words:

```php
$api = new Client();
$api->debug = true;
$api->connect('192.168.1.1', 'admin', 'password');

// Output:
// Connection attempt #1 to 192.168.1.1:8728...
// <<< [/login]
// <<< [=name=admin]
// <<< [=password=password]
// >>> [!done]
// Connected and authenticated.
```

## Architecture

```
src/
├── Client.php                   # Main client — connect, disconnect, comm
├── Contracts/
│   └── ClientInterface.php      # Universal interface (shared with ROS7)
├── Protocol/
│   ├── WordEncoder.php          # Encode word length → binary prefix
│   ├── WordDecoder.php          # Decode binary prefix → word from socket
│   └── Authenticator.php        # Dual-mode login (MD5 + plaintext)
├── Parser/
│   └── ResponseParser.php       # Raw socket words → PHP arrays
└── Exceptions/
    └── MikrotikException.php    # Structured error handling
```

## References

This package was built using the following references:

- [Mikrotik API Documentation (ROS7)](https://help.mikrotik.com/docs/display/ROS/API)
- [Mikrotik API Documentation (ROS6 Legacy)](https://wiki.mikrotik.com/wiki/Manual:API)
- [Mikhmon v3 — routeros_api.class.php](https://github.com/laksa19/mikhmonv3) by Denis Basta & Laksamadi Guko
- [EvilFreelancer/routeros-api-php](https://github.com/EvilFreelancer/routeros-api-php)

## License

MIT License. See [LICENSE](LICENSE) for details.