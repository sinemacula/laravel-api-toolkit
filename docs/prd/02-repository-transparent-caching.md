# PRD: 02 Repository Transparent Caching

Transparent, opt-in caching for repository-backed lookup tables so consumers get cached reads without changing how they query.

---

## Governance

| Field     | Value                                                                                                                          |
|-----------|--------------------------------------------------------------------------------------------------------------------------------|
| Created   | 2026-03-06                                                                                                                     |
| Status    | draft                                                                                                                          |
| Owned by  | Ben                                                                                                                            |
| Traces to | [Prioritization](.sinemacula/blueprint/workflows/repository-transparent-caching/prioritization.md) — Ranks 1-5 (all P0 problems) |

---

## Overview

Applications built with the Laravel API Toolkit frequently include small lookup tables — roles, permissions, flags, providers, settings — that rarely change but are queried repeatedly within and across requests. Today, every query hits the database. Developers who want to optimise this must implement caching at the consumer level, scattering cache logic across dozens of files and creating inconsistent invalidation behaviour.

This PRD defines a transparent, repository-level caching capability for the v2 toolkit release. A repository author marks a repository as cacheable; from that point forward, reads are served from cache and writes automatically invalidate the cache. Consumers of the repository see no change to the query API. The feature uses only Laravel's built-in cache contracts, works with any cache driver, and introduces no new package dependencies.

This is a v2 feature where breaking changes to the repository layer internals are acceptable.

---

## Target Users

| Persona                 | Description                                                                                        | Key Need                                                                     |
|-------------------------|----------------------------------------------------------------------------------------------------|------------------------------------------------------------------------------|
| Application Developer   | Builds features using toolkit repositories; queries lookup tables from services and controllers     | Cached reads without adding Cache::remember() calls to every query site      |
| Repository Author       | Extends ApiRepository to define domain-specific repositories for their application                  | A simple, opt-in mechanism to declare a repository as cacheable              |
| Package Maintainer      | Maintains the toolkit itself; responsible for the repository base classes                           | A clean, testable, driver-agnostic caching layer that doesn't leak internals |

**Primary user:** Application Developer

---

## Goals

- Eliminate repeated database queries for small, rarely-changing lookup tables without requiring consumer code changes
- Centralise cache invalidation so that all consumers of a cached repository see consistent, fresh data after writes
- Provide an opt-in mechanism that repository authors can enable with minimal configuration
- Work with any Laravel cache driver (file, database, Redis, Memcached, array) without runtime exceptions

## Non-Goals

- Caching large, frequently-changing tables (this is for small lookup tables, not general-purpose query caching)
- Per-query result caching with parameterised cache keys (the scope is full-table caching, not arbitrary query memoisation)
- Catching writes that bypass the repository (direct Eloquent saves, raw DB queries, migrations)
- Providing a cache warming CLI command or boot-time pre-loading (may be added later)
- Supporting row-level cache invalidation (table-level flush on any write is sufficient)

---

## Problem

**User problem:** Developers query lookup tables through repositories multiple times per request. Each query hits the database even though the data hasn't changed. Developers who want caching must implement it themselves at every call site, which scatters cache logic across the codebase, creates inconsistent TTLs and invalidation, and leads to subtle stale-data bugs where some code paths reflect changes and others don't.

**Business problem:** The toolkit positions itself as a complete data-access layer. Without transparent caching, it forces consumers to break the encapsulation the repository pattern provides. This reduces the toolkit's value proposition and increases adoption friction for performance-conscious teams.

**Current state:** Developers use `Cache::remember()` at the service, controller, or middleware level. Some use model-level caching packages. Others accept repeated queries. There is no standard approach, and each implementation handles (or fails to handle) invalidation independently.

**Evidence:**

- [Spike: Caching Patterns and Invalidation Strategies](.sinemacula/blueprint/workflows/repository-transparent-caching/spikes/spike-caching-patterns-and-invalidation.md) — Findings 1-7
- [Problem Map](.sinemacula/blueprint/workflows/repository-transparent-caching/problem-map.md) — Clusters: Unnecessary Database Load, Scattered Cache Logic, Cache Infrastructure Pitfalls

---

## Proposed Solution

A repository author enables caching by opting in on their repository class. No other code changes are needed anywhere in the application.

**Developer experience — repository author:**

The repository author marks a repository as cacheable. This is the only change required. The repository now caches its entire underlying table on first read access. Subsequent reads — from any consumer, in any part of the application — are served from cache. When any write operation (create, update, delete) goes through the repository, the cache is automatically invalidated and will be repopulated on the next read.

**Developer experience — application developer (consumer):**

Nothing changes. The consumer queries the repository exactly as before. They do not know or care whether the result came from cache or database. They do not manage cache keys, TTLs, or invalidation. The repository API is identical.

**Invalidation behaviour:**

When a write occurs through the repository, the cached data is flushed. The next read repopulates the cache from the database. This ensures consumers always see fresh data after a write, with no explicit invalidation logic needed at the consumer level.

### Key Capabilities

- Repository author can declare a repository as cacheable with a single opt-in change
- All read operations on a cacheable repository are served from cache after the initial population
- All write operations through a cacheable repository automatically invalidate the cache
- Cache works with any Laravel cache driver without configuration or driver-specific code
- Consumers of the repository require zero code changes
- Cache TTL is configurable per repository, with a sensible default

---

## Requirements

### Must Have (P0)

- **Opt-in caching:** Repository author can declare a repository as cacheable without modifying consumers
  - **Acceptance criteria:** A repository that has opted into caching returns cached results on the second read within the same request; removing the opt-in reverts to database queries; no consumer code is modified

- **Transparent read caching:** All repository read operations return cached data when available
  - **Acceptance criteria:** After the first read, subsequent reads do not execute database queries; the returned data is identical to what the database would return

- **Automatic write invalidation:** Create, update, and delete operations through the repository invalidate the cache
  - **Acceptance criteria:** After a write through the repository, the next read returns data reflecting the write; no manual cache clearing is required

- **Driver-agnostic operation:** Caching works with file, database, Redis, Memcached, and array cache drivers
  - **Acceptance criteria:** The same cacheable repository passes its test suite against file, array, and Redis cache drivers without code changes or runtime exceptions

- **Cache bypass:** Consumer can bypass the cache for a specific query when fresh data is explicitly needed
  - **Acceptance criteria:** A consumer can request a fresh (uncached) read from a cacheable repository; the bypass does not invalidate the cache for other consumers

### Should Have (P1)

- **Configurable TTL:** Repository author can set a custom cache duration per repository
  - **Acceptance criteria:** A repository with a custom TTL expires its cache at the configured duration; the default TTL is applied when no custom value is set

- **Manual cache flush:** Repository author or application developer can programmatically flush a repository's cache
  - **Acceptance criteria:** Calling the flush method clears the cached data; the next read repopulates from the database

### Nice to Have (P2)

- **Cache status observability:** Developer can determine whether a repository's cache is populated, its age, and when it was last invalidated
- **Configurable cache store:** Repository author can specify which Laravel cache store to use per repository (e.g., a dedicated Redis connection for lookup caches)

---

## Success Criteria

| Metric                            | Baseline                                                  | Target                                | How Measured                                                                               |
|-----------------------------------|-----------------------------------------------------------|---------------------------------------|--------------------------------------------------------------------------------------------|
| Database queries for lookup reads | Every read executes a query (N queries per request)       | 1 query per table on first access; 0 thereafter | Unit test asserting query count before and after caching is enabled                        |
| Consumer code changes required    | N/A — new capability                                      | Zero consumer changes to enable caching | Code review: enabling caching on a repository requires no changes outside the repository class |
| Cache driver compatibility        | N/A — new capability                                      | Works on all 4 major Laravel drivers  | Test suite runs against file, array, Redis, and database drivers                           |
| Invalidation correctness          | N/A — new capability                                      | 100% of repository writes trigger invalidation | Unit test: write through repository, assert next read reflects the change                  |
| Test coverage                     | N/A — new capability                                      | 100% line coverage on caching code    | PHPUnit coverage report                                                                    |

---

## Dependencies

- `sinemacula/laravel-repositories` ^2.0 — the base `Repository` class that `ApiRepository` extends; caching must integrate with or extend this package's write methods
- Laravel's `Illuminate\Contracts\Cache\Repository` contract — the driver-agnostic cache interface

---

## Assumptions

- Target tables are small enough to cache entirely in memory (hundreds to low thousands of rows, not millions)
- Table-level cache invalidation (flush everything on any write) is acceptable granularity for lookup tables
- The base `Repository` class in `sinemacula/laravel-repositories` mediates all write operations, making it a reliable interception point for invalidation
- Consumers will not bypass the repository for writes to cached tables (bypass writes will result in stale cache until TTL expiry or manual flush)

---

## Risks

| Risk                                             | Impact                                                       | Likelihood | Mitigation                                                                                                  |
|--------------------------------------------------|--------------------------------------------------------------|------------|-------------------------------------------------------------------------------------------------------------|
| Bypass writes cause stale data                   | Consumers see outdated lookup values until cache expires      | Medium     | Document the limitation clearly; provide a manual flush mechanism; recommend short TTL for frequently-updated tables |
| Serialisation cost for large Eloquent collections | Memory and CPU overhead if cached tables are larger than expected | Low        | Document size guidelines; the design targets small tables by intent                                          |
| Cache stampede on invalidation                   | Multiple concurrent requests all miss cache and query DB simultaneously | Low        | Use atomic cache population (lock on miss) to ensure only one request repopulates                            |
| Long-lived processes serve stale cache            | Queue workers or Octane processes may hold stale cached data  | Medium     | Document the behaviour; recommend TTL-based expiry for long-lived processes rather than indefinite caching   |

---

## Out of Scope

- Caching for arbitrary queries with filters, sorting, or pagination (only full-table caching is in scope)
- Model event listeners to catch writes outside the repository
- Multi-tenancy-aware cache keys (can be added as a follow-up feature)
- Cache warming via artisan command or service provider boot
- Query-layer interception (a la spiritix/lada-cache) — caching is at the repository level, not the database connection level

---

## Release Criteria

- All P0 requirements pass acceptance criteria
- Test suite passes with 100% coverage on caching code
- `composer check` passes (PHPStan level 8, CS-Fixer, CodeSniffer)
- `composer test` passes with no failures
- No new package dependencies introduced (uses Laravel's built-in cache contracts only)

---

## Traceability

| Artifact             | Path                                                                                                              |
|----------------------|-------------------------------------------------------------------------------------------------------------------|
| Intake Brief         | [intake-brief.md](.sinemacula/blueprint/workflows/repository-transparent-caching/intake-brief.md)                 |
| Relevant Spikes      | [spike-caching-patterns-and-invalidation.md](.sinemacula/blueprint/workflows/repository-transparent-caching/spikes/spike-caching-patterns-and-invalidation.md) |
| Problem Map Entry    | Clusters: Unnecessary Database Load > Problem 1, Scattered Cache Logic > Problems 3 & 4, Cache Infrastructure Pitfalls > Problem 5 |
| Prioritization Entry | Ranks 1-5: Repeated identical queries (9), Cache awareness leaks (9), Inconsistent invalidation (9), No static/dynamic distinction (8), Cache tag driver lock-in (7) |

---

## References

- Traces to: [Prioritization](.sinemacula/blueprint/workflows/repository-transparent-caching/prioritization.md) — Ranks 1-5
- Intake Brief: [intake-brief.md](.sinemacula/blueprint/workflows/repository-transparent-caching/intake-brief.md)
