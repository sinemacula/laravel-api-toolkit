# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/), and this project adheres
to [Semantic Versioning](https://semver.org/).

## [Unreleased]

Version 2.0 is in development on the `2.x` branch. See [UPGRADE.md](UPGRADE.md) for the migration guide. Highlights:

### Changed

- `ApiResource`, `ApiCriteria`, and `ApiRepository` decomposed into single-responsibility collaborators
- Service configuration is immutable, with cross-cutting behaviour composed through a concern pipeline
- `Service::run()` returns an immutable `ServiceResult` value object instead of `bool`
- HTTP enums are provided by `sinemacula/http-primitives-php`
- Request capabilities are exposed through the typed `RequestCapabilities` API; the request macros are deprecated

### Added

- Extensible filter operator registry
- Schema introspection service and boot-time schema validation
- Opt-in deferred repository writes with a write pool, and opt-in transparent repository caching
- Exception handler coverage for all HTTP-layer exceptions, preserving `abort()` status codes
- Configurable middleware registration and notification logging exclusions

### Fixed

- Lifecycle and error boundaries now preserve the full throwable (type, stack, cause) rather than
  only its message: the write-pool flush subscriber, the database log handler's fallback, and the
  write-pool chunk-failure logger log the throwable under an `exception` key, and the write-pool
  failure accumulator records the `exception_class` alongside the message. The Octane cache-flush
  listener now wraps the flush in an error boundary, so a flush failure is logged instead of
  propagating into Octane's dispatch and crashing the worker
