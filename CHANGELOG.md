# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- `Client` class with universal `comm()` method for executing any RouterOS command
- `ClientInterface` contract (framework-agnostic, shared with ROS7)
- `WordEncoder` for Mikrotik binary length encoding (bit-shifting)
- `WordDecoder` for decoding length-prefixed words from socket stream
- `Authenticator` with dual-mode login support (MD5 Challenge pre-v6.43 + Plaintext post-v6.43)
- `ResponseParser` for converting raw socket words to structured PHP arrays
- `MikrotikException` with static factory methods for all error scenarios
- SSL connection support (port 8729)
- Auto-retry with configurable attempts and delay
- Debug mode for inspecting sent/received words
