# PRD: 06 Cache Lifecycle Management

Provide opt-in cache lifecycle management so that the toolkit's cached metadata is correctly invalidated at request and job boundaries in long-running PHP environments.

---

## Governance

| Field     | Value                                                                                                                                                                          |
|-----------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Created   | 2026-03-10                                                                                                                                                                     |
| Status    | approved                                                                                                                                                                       |
| Owned by  | Ben                                                                                                                                                                            |
| Traces to | [Prioritization](.sinemacula/blueprint/workflows/cache-memo-invalidation/prioritization.md) — Rank 1: No Centralized Cache Flush, Rank 2: No Automatic Lifecycle Event Integration, Rank 6: Static Property Caches Lack Public Flush APIs |

---

## Overview

The Laravel API Toolkit caches critical metadata — relation detection results, model casts, schema columns, resource mappings, and compiled schemas — across seven distinct cache sites (four `Cache::memo()->rememberForever()` stores, two static property arrays, and one singleton). In standard PHP-FPM deployments, this caching is correct and desirable because each request runs in a fresh process. However, in long-running environments (Laravel Octane, queue workers, Laravel Reverb), these caches persist beyond their intended scope, causing stale metadata to leak across request and job boundaries. The result is silent data corruption: incorrect query filtering, wrong attribute types, missing columns, and mismatched resource serialization — all without any error or warning.

No lifecycle flush infrastructure exists in the toolkit today. There is no centralized method to clear all caches, no event listeners for Octane or queue worker lifecycle events, and two of the seven cache sites lack public flush APIs entirely (requiring reflection to clear, even in tests). Every consumer deploying on a long-running runtime must independently discover and solve this problem.

This PRD addresses the infrastructure gap: a centralized flush mechanism, automatic opt-in lifecycle event integration for Octane and queue workers, and public flush APIs for all cache sites. Resolving these three structural problems also eliminates all five identified data corruption symptoms as a direct consequence.

---

## Target Users

| Persona                | Description                                                                                                          | Key Need                                                                                        |
|------------------------|----------------------------------------------------------------------------------------------------------------------|-------------------------------------------------------------------------------------------------|
| Octane Operator        | Backend developer or DevOps engineer deploying a toolkit-powered API on Laravel Octane (Swoole/RoadRunner)           | Confidence that cached metadata resets between requests without manual intervention              |
| Queue Worker Operator  | Developer running persistent queue workers that process jobs involving toolkit repositories or resources              | Assurance that schema and cast changes take effect between jobs without restarting the worker    |
| Toolkit Contributor    | Developer contributing to or extending the API toolkit package itself                                                | Public, documented APIs for clearing all caches — no reflection hacks required in tests or extensions |

**Primary user:** Octane Operator

---

## Goals

- Eliminate stale cached metadata across request boundaries in Octane environments
- Eliminate stale cached metadata across job boundaries in queue worker environments
- Provide a single entry point that flushes all toolkit caches without requiring knowledge of individual cache sites
- Ensure zero performance impact on standard PHP-FPM deployments where process-lifetime caching is correct

## Non-Goals

- Replacing `Cache::memo()->rememberForever()` with a different caching strategy (the caching pattern itself is sound; the gap is lifecycle management)
- Providing a configurable TTL alternative to `rememberForever()` (this may be explored in a future iteration but is not required to solve the lifecycle problem)
- Addressing the `ApiQueryParser` singleton state issue (ISSUE-11) — this is a related but distinct concern involving scoped container bindings rather than cache flushing
- Supporting Reverb (WebSocket server) lifecycle events (insufficient research on Reverb's event model; may be addressed in a future iteration once patterns are established)
- Providing cache warming or pre-loading capabilities

---

## Problem

**User problem:** When a developer deploys a toolkit-powered API on Octane or runs persistent queue workers, cached metadata from earlier requests or jobs silently contaminates later ones. A migration adding a column is invisible to the running worker. A cast definition change produces wrong attribute types until the process is restarted. A relation rename causes filters to silently apply to the wrong target. The developer has no way to flush these caches programmatically — the only recourse is process restart, which disrupts service availability.

**Business problem:** Long-running PHP runtimes (Octane, queue workers) are increasingly the standard for production Laravel deployments. A toolkit that produces silently incorrect API responses in these environments cannot be trusted for production use. Every consumer must independently discover the staleness issue and build custom flush logic, creating duplicated effort and a fragmented ecosystem.

**Current state:** Developers restart Octane servers and queue workers after any schema, cast, or relation change. There is no programmatic way to flush the toolkit's caches. `RepositoryResolver::flush()` clears one of seven sites. `ApiResource::$schemaCache` can only be cleared via reflection. No event listeners exist for `OperationTerminated`, `JobProcessed`, or `JobFailed`.

**Evidence:**
- Spike "Cache & Lifecycle Inventory", Finding 1: four `Cache::memo()->rememberForever()` write sites identified
- Spike "Cache & Lifecycle Inventory", Finding 3: two static property caches without public flush methods
- Spike "Cache & Lifecycle Inventory", Finding 5: zero lifecycle flush infrastructure exists
- Spike "Octane Invalidation Patterns", Finding 2: static property caches are not managed by any auto-flush mechanism
- Spike "Octane Invalidation Patterns", Finding 3: `OperationTerminated` is the established Octane hook for package flush listeners
- Spike "Octane Invalidation Patterns", Finding 4: spatie's opt-in config flag pattern is the proven community approach
- Problem Map, Cluster "No Cache Lifecycle Management Tools": Problems 1, 2, and 3
- Problem Map, Cluster "Silent Data Corruption in Long-Running Environments": Problems 1-5 (resolved as consequence)

---

## Proposed Solution

When a developer enables cache lifecycle management in the toolkit configuration, stale metadata is automatically cleared at the appropriate lifecycle boundaries:

- **In Octane:** After each HTTP request completes, all toolkit caches are flushed before the next request is handled. The developer deploys their Octane application and the toolkit handles its own state management transparently. Schema changes, cast updates, and relation modifications take effect on the next request without process restart.

- **In queue workers:** After each job completes (or fails), all toolkit caches are flushed before the next job is processed. A migration that adds a column is reflected in the next job automatically.

- **Manual flush:** At any time, a developer can programmatically flush all toolkit caches with a single call. This is useful for custom lifecycle management, testing teardown, or artisan commands that modify schema or configuration at runtime.

- **Standard PHP-FPM:** The feature is opt-in. Developers who do not enable it see no change in behavior and no performance overhead. Process-lifetime caching continues to work as expected.

### Key Capabilities

- Developer can enable automatic cache lifecycle management via a configuration flag
- Developer can flush all toolkit caches with a single programmatic call
- Developer can flush all toolkit caches via an artisan command
- All seven identified cache sites (memo caches, static properties, singleton state) are covered by a single flush operation

---

## Requirements

### Must Have (P0)

- **Public flush API for all cache sites:** Every cache site in the toolkit exposes a public method for clearing its cached data. No cache site requires reflection or internal knowledge to flush.
  - **Acceptance criteria:** Each of the seven identified cache sites can be cleared by calling a documented public method. A test that populates each cache site and then calls its flush method confirms the site returns fresh data on the next access.

- **Centralized flush entry point:** A single call clears all toolkit cache sites atomically. The caller does not need to know which individual caches exist or how many there are.
  - **Acceptance criteria:** After calling the centralized flush, all seven cache sites return fresh (re-computed) data on the next access. Adding a new cache site in a future version does not require callers to change their flush calls.

- **Opt-in Octane lifecycle integration:** When enabled via configuration, the toolkit automatically flushes all caches after each Octane request completes. The feature is disabled by default.
  - **Acceptance criteria:** With the configuration flag enabled, two consecutive Octane requests that would read different metadata (e.g., a schema change between them) each receive correct, isolated metadata. With the flag disabled, no listener is registered and no flush occurs.

- **Opt-in queue worker lifecycle integration:** When enabled via configuration, the toolkit automatically flushes all caches after each queue job completes or fails. The feature is disabled by default.
  - **Acceptance criteria:** With the configuration flag enabled, two consecutive jobs in the same worker process that would read different metadata each receive correct data. Failed jobs also trigger a flush. With the flag disabled, no listener is registered.

- **Zero FPM overhead:** When lifecycle management is not enabled, the toolkit does not register any event listeners, perform any additional method calls, or introduce any measurable overhead compared to the current behavior.
  - **Acceptance criteria:** With the configuration flags disabled, no event listeners for lifecycle events are registered in the service container. A benchmark of a representative API request shows no measurable latency difference compared to the current codebase.

### Should Have (P1)

- **Artisan command for manual flush:** A developer can run an artisan command to flush all toolkit caches. This is useful during deployment scripts, after running migrations, or for debugging.
  - **Acceptance criteria:** Running the artisan command clears all seven cache sites. The command outputs a confirmation message listing the caches that were flushed.

- **Flush event notification:** After a centralized flush completes, the toolkit dispatches an event that consumers can listen for (e.g., to perform their own cleanup or logging).
  - **Acceptance criteria:** A registered listener receives the event after a centralized flush. The event carries no payload beyond indicating that a flush occurred.

### Nice to Have (P2)

- **Selective flush by cache category:** A developer can flush a specific subset of caches (e.g., only schema-related caches or only relation caches) rather than all caches at once.

- **Flush logging:** When enabled, each flush operation logs which cache sites were cleared, for observability in production environments.

- **Remove unused CacheKeys enum cases:** The `MODEL_EAGER_LOADS` and `MODEL_RELATION_INSTANCES` cases are defined in the `CacheKeys` enum but not used by any production code. They should be removed (or documented as reserved) to reduce confusion when auditing the cache surface area.

---

## Success Criteria

| Metric                                  | Baseline                                                              | Target                                                     | How Measured                                                                                            |
|-----------------------------------------|-----------------------------------------------------------------------|------------------------------------------------------------|---------------------------------------------------------------------------------------------------------|
| Cache site flush coverage               | 1 of 7 sites has a public flush method (`RepositoryResolver::flush()`) | All 7 sites have public flush methods                      | Code review: verify each cache site class exposes a public clear/flush method                           |
| Centralized flush completeness          | No centralized flush exists                                           | Single call clears all 7 sites                             | Integration test: populate all caches, call centralized flush, verify all return fresh data              |
| Octane request isolation                | Stale metadata persists across Octane requests                        | Each request receives correct metadata with opt-in enabled | Integration test: simulate two Octane requests with changed metadata between them; assert isolation     |
| Queue job isolation                     | Stale metadata persists across queue jobs                             | Each job receives correct metadata with opt-in enabled     | Integration test: simulate two jobs with changed metadata between them; assert isolation                 |
| FPM performance regression              | Current request latency (no lifecycle overhead)                       | No measurable latency increase with flags disabled         | Benchmark: compare request latency with and without the package changes, flags disabled                  |
| Static analysis                         | Passes PHPStan level 8                                                | Continues to pass PHPStan level 8                          | `composer check` passes                                                                                 |
| Test coverage                           | No test coverage for cache lifecycle management                       | Full coverage of flush methods, listeners, and config flags | `composer test-coverage` shows 100% coverage of new code                                                |

---

## Dependencies

- Laravel 12.9+ (`illuminate/support: ^12.9`) — required for `Cache::memo()` support. Already a constraint in the toolkit's `composer.json`.
- Laravel Octane's `OperationTerminated` event contract — the listener must implement against this interface. Octane is an optional dependency (the listener is only registered when the config flag is enabled and Octane is installed).
- Laravel's queue event system (`JobProcessed`, `JobFailed`) — stable public API available in all supported Laravel versions.

---

## Assumptions

- The seven cache sites identified in the spike research (4 memo, 2 static, 1 singleton) are the complete set. If additional cache sites are discovered during implementation, they should be added to the centralized flush.
- `Cache::memo()` auto-flush between requests (Laravel 12.9+) handles the in-memory memoization layer. The lifecycle management addresses the underlying `rememberForever()` persistence and static property caches that are NOT auto-flushed.
- Octane's `OperationTerminated` event fires reliably after every request, task, and tick. This is a framework guarantee documented in the Octane configuration.
- Queue worker events (`JobProcessed`, `JobFailed`) fire reliably after every job. This is a framework guarantee documented in the Laravel queue system.
- The performance cost of flushing all caches between requests/jobs is negligible compared to the cost of re-computing the cached values (relation detection, column introspection, cast resolution).

---

## Risks

| Risk                                                                 | Impact                                                                     | Likelihood | Mitigation                                                                                                                     |
|----------------------------------------------------------------------|----------------------------------------------------------------------------|------------|--------------------------------------------------------------------------------------------------------------------------------|
| Flushing all caches on every request degrades Octane performance     | Re-computing metadata on every request negates Octane's performance benefit | Medium     | Benchmark the flush cost during implementation. If significant, consider flushing only the memo/static caches (not the underlying store) or provide granular flush options (P2 requirement) |
| Octane is not installed but config flag is enabled                   | Listener registration fails or throws at boot time                         | Low        | Guard listener registration with a class-existence check for the Octane event interface                                        |
| New cache sites added in future versions are not covered by flush    | Future cache sites leak state in long-running environments                 | Medium     | Document the convention for registering new cache sites with the centralized flush. Add a developer guide section              |
| `rememberForever()` values in persistent stores (Redis) survive flush | Flushing the memo layer and static caches does not clear Redis entries     | Medium     | The centralized flush must also call `Cache::forget()` for all `CacheKeys` entries, not just clear in-memory state            |

---

## Out of Scope

- `ApiQueryParser` singleton state management (ISSUE-11) — related but requires scoped container binding, not cache flushing. The lifecycle subscriber may provide a hook point for this in the future, but the parser reset is not part of this PRD.
- Reverb (WebSocket server) lifecycle support — insufficient research on Reverb's event model. The architecture should not preclude future Reverb support, but this PRD does not require it.
- Configurable TTL as an alternative to `rememberForever()` — a future enhancement that may complement lifecycle flushing but is not required to solve the current problem.
- Removal of unused `CacheKeys` enum cases (`MODEL_EAGER_LOADS`, `MODEL_RELATION_INSTANCES`) — this is a separate code hygiene concern (P1 in prioritization).
- Test isolation improvements beyond providing public flush APIs — the public APIs satisfy the test isolation need; no additional test-specific utilities are required.

---

## Release Criteria

- All existing tests pass without modification (unless a test asserts the absence of a flush method that now exists)
- New tests cover: individual cache site flush methods, centralized flush completeness, Octane lifecycle listener with opt-in flag, queue worker lifecycle listener with opt-in flag, disabled-flag-no-overhead verification
- `composer check` passes (PHPStan level 8, PHP-CS-Fixer, CodeSniffer)
- `composer test` passes with 100% coverage of new code
- Configuration defaults are safe: all lifecycle flags are disabled by default
- No breaking changes to existing public APIs

---

## Traceability

| Artifact             | Path                                                                                                   |
|----------------------|--------------------------------------------------------------------------------------------------------|
| Intake Brief         | `.sinemacula/blueprint/workflows/cache-memo-invalidation/intake-brief.md`                              |
| Relevant Spikes      | `.sinemacula/blueprint/workflows/cache-memo-invalidation/spikes/spike-cache-lifecycle-inventory.md`, `.sinemacula/blueprint/workflows/cache-memo-invalidation/spikes/spike-octane-invalidation-patterns.md` |
| Problem Map Entry    | No Cache Lifecycle Management Tools > Problem 1: No Centralized Cache Flush, Problem 2: No Automatic Lifecycle Event Integration, Problem 3: Static Property Caches Lack Public Flush APIs |
| Prioritization Entry | Rank 1: No Centralized Cache Flush (P0, score 8), Rank 2: No Automatic Lifecycle Event Integration (P0, score 8), Rank 6: Static Property Caches Lack Public Flush APIs (P0, score 7) |

---

## References

- Traces to: [Prioritization](.sinemacula/blueprint/workflows/cache-memo-invalidation/prioritization.md) — Ranks 1, 2, 6
- Intake Brief: `.sinemacula/blueprint/workflows/cache-memo-invalidation/intake-brief.md`
- Related issue: ISSUE-01 (Cache Memo Entries Lack Automatic Invalidation for Long-Running Processes) — original issue description
- Related issue: ISSUE-11 (ApiQueryParser Singleton Without Request Isolation Guarantees) — adjacent concern, out of scope
