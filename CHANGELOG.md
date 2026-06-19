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

- Column narrowing now retains the parent foreign key for every eager-loaded relation, not only
  scoped ones. Plain and `extras` relations are stored as list entries in the eager-load map, so
  deriving relation names via `array_keys()` yielded integer indices that resolved to no relation
  and dropped the parent key - a narrowed query then silently returned `null` for the relation.
  Dotted relation paths are reduced to their base-model segment for the same lookup
