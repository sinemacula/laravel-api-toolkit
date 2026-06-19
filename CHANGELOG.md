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
- The transparent repository cache now negatively caches null/miss reads under a separate, shorter
  `negative_ttl` (default 10s), so a repeated lookup for a missing key is served from cache instead of
  re-querying every time. The short TTL bounds stale-null visibility and probe-fill memory, and a write
  still invalidates the negative entry like any other
- Resource serialization memoises its hot-path lookups: `ValueResolver` caches the per-`(class, field)`
  cast-accessor reflection result and the per-definition child field set, and `EagerLoadPlanner` caches the
  eager-load and count maps per request. The memos are static but request-scoped - `CacheManager::flush()` clears
  them at request and worker boundaries (Octane, queues) - so repeated reads no longer re-reflect or rebuild the
  same map once per record

### Added

- Extensible filter operator registry
- Schema introspection service and boot-time schema validation
- Opt-in deferred repository writes with a write pool, and opt-in transparent repository caching
- Exception handler coverage for all HTTP-layer exceptions, preserving `abort()` status codes
- Configurable middleware registration and notification logging exclusions

### Fixed

- Duplicate relation counts no longer collide. `EagerLoadPlanner::buildCountMap()` now aliases each
  count as `{relation} as {presentKey}_count` and `ValueResolver` reads it back by presentation key,
  so two counts on the same relation (e.g. a total and a constrained subset) resolve to distinct
  values instead of both reporting the same one
- `AttributeSetter` relation sync now plucks the related model's primary key (`getKeyName()`) instead
  of a hardcoded `id`, so syncing a model or collection into a `BelongsToMany` whose related model
  uses a non-`id` primary key attaches the correct keys rather than null
- The SSE `Emitter::emit()` now encodes array payloads with `JSON_THROW_ON_ERROR`, so an
  unencodable payload raises a `JsonException` (which the event stream's error handler can act on)
  instead of silently writing a single blank `data:` frame
- `SchemaIntrospector::getColumns()` now caches an empty column listing instead of re-querying the
  schema on every request, by gating the cache hit on the cache key's presence rather than on a
  non-empty cached value
- Lifecycle and error boundaries now preserve the full throwable (type, stack, cause) rather than
  only its message: the write-pool flush subscriber, the database log handler's fallback, and the
  write-pool chunk-failure logger log the throwable under an `exception` key, and the write-pool
  failure accumulator records the `exception_class` alongside the message. The Octane cache-flush
  listener now wraps the flush in an error boundary, so a flush failure is logged instead of
  propagating into Octane's dispatch and crashing the worker
- Column narrowing now retains the parent foreign key for every eager-loaded relation, not only
  scoped ones. Plain and `extras` relations are stored as list entries in the eager-load map, so
  deriving relation names via `array_keys()` yielded integer indices that resolved to no relation
  and dropped the parent key - a narrowed query then silently returned `null` for the relation.
  Dotted relation paths are reduced to their base-model segment for the same lookup
- The transparent repository cache now folds the read verb, its arguments, and the registered
  eager loads into the per-query cache key, so reads that share a base builder but differ only at
  execution time - `find(1)` vs `find(2)`, `value()` vs `get()`, column projections, and
  `with(...)`-eager-loaded vs plain reads - no longer collide on a single cache entry
- `ApiException::getCustomDetail()` now returns an empty detail instead of leaking the raw
  translation key when no `detail` translation is registered, matching the existing
  `getCustomTitle()` guard
- The `Cacheable` and `Deferrable` repository concerns can now be used on the same repository.
  Both previously declared `boot()`, so combining them raised a fatal trait-method collision; each
  now boots through a dedicated `bootCacheable()` / `bootDeferrable()` hook invoked by the base
  repository. (Deferred writes still bypass the per-query cache - flush it manually or rely on its
  TTL; this is documented on the `Deferrable` trait.)
- The whole-table reference cache now applies the configured size guard before caching the table
  snapshot, so reference mode on an unexpectedly large table returns the rows but falls back to
  querying rather than serialising a huge snapshot into the cache
- Per-query cache invalidation on non-taggable stores (`database`/`file`/`array`) no longer maintains
  a tracked key set rewritten in full on every cache write (O(K) per write, plus a read-modify-write
  race that dropped and leaked keys `flushTable` could then never clear, causing stale reads). Each
  per-query key now embeds a generational table version, and invalidation bumps it with a single
  atomic increment - O(1) and race-free; orphaned old-version entries expire by TTL. The taggable
  (cache-tag) path is unchanged.

### Security

- The API exception handler no longer logs the raw request body. Configured sensitive keys
  (`password`, `*token*`, `*secret*`, and `authorization` by default, matched case-insensitively
  and recursively) are redacted from the request data written to the `api-exceptions` log context,
  preventing credentials from leaking to logs and CloudWatch (CWE-532)
- The request throttle key now includes the client IP for unauthenticated requests, so anonymous
  callers no longer share a single rate-limit bucket per endpoint - one caller could previously
  exhaust it and 429-lock every other anonymous caller
- API query `limit` values are now clamped to a configurable hard ceiling (`parser.max_limit`,
  default 100) instead of being honoured unbounded, so a request such as `?limit=100000000` can no
  longer force an unbounded page size that exhausts memory
