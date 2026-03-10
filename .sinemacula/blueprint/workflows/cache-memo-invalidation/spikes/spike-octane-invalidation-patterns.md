# Spike: Octane & Queue Worker Invalidation Patterns

How the Laravel ecosystem handles memo cache, static property, and singleton state invalidation in long-running process environments.

---

## Governance

| Field     | Value                                            |
|-----------|--------------------------------------------------|
| Created   | 2026-03-10                                       |
| Status    | approved                                         |
| Owned by  | Researcher                                       |
| Traces to | [Intake Brief](../intake-brief.md)               |

---

## Research Question

How do Laravel and its package ecosystem handle memo cache, static property, and singleton state invalidation in Octane and queue worker environments, and what established patterns exist for third-party packages to participate in lifecycle flushing?

---

## Methodology

Web search and documentation review covering:

- Laravel 12.x official documentation for `Cache::memo()` and Octane
- Laravel Octane source configuration (`config/octane.php`) and listener architecture
- Community best practices repository (michael-rubel/laravel-octane-best-practices)
- Third-party package examples (spatie/laravel-permission Octane support)
- Laravel `once()` helper and its Octane integration (FlushOnce listener)
- Queue worker event documentation (`JobProcessed`, `JobFailed`)
- Laravel News coverage of the memoized cache driver (Laravel 12.9, PR #55304)

---

## Findings

### Finding 1: `Cache::memo()` Auto-Flushes Between Requests and Jobs

**Observation:** Laravel 12.9+ (which this toolkit requires via `illuminate/support: ^12.9`) automatically flushes the `Cache::memo()` in-memory layer between HTTP requests and between queue job executions. The documentation states: "The memo cache driver allows you to temporarily store resolved cache values in memory during a single request or job execution." Values are "automatically flushed between requests and between job executions."

However, `Cache::memo()` is a **decorator** around the configured default cache store. When `Cache::memo()->rememberForever()` is called, the `rememberForever()` operation delegates to the underlying store (Redis, file, etc.). The memo layer flush clears the in-memory decorator, but the underlying store retains the `rememberForever()` value.

This means:
- **Within a single request:** Memo prevents repeated cache hits (performance optimization).
- **Between requests:** Memo layer is cleared, but the underlying store returns the previously stored value on the next `rememberForever()` call.
- **Staleness impact:** If the underlying store is `array` (process-lifetime in FPM, flushed by Octane), there is no cross-request staleness. If the underlying store is `redis` or `file`, `rememberForever()` values persist permanently until explicitly invalidated.

**Evidence:** [Laravel 12.x Cache Documentation](https://laravel.com/docs/12.x/cache) — "Cache Memoization" section. [Laravel News: Memoized Cache Driver in Laravel 12.9](https://laravel-news.com/laravel-12-9-0) — PR #55304 by Tim MacDonald. The toolkit's `composer.json` requires `illuminate/support: ^12.9`.

**Confidence:** High — official documentation confirms auto-flush behavior; the decorator delegation to `rememberForever()` follows standard decorator pattern described in the PR.

### Finding 2: Static Property Caches Are Not Managed by Any Auto-Flush Mechanism

**Observation:** Neither Laravel Octane nor the queue worker system provides automatic flushing of static properties on arbitrary classes. Octane's listener architecture flushes framework-internal state (translator, session, authentication guards, array cache store, container scoped instances) but does NOT reset static properties on application or package classes.

The Octane best practices repository explicitly warns: "Static state persists across requests within worker processes... Data added to static collections accumulates across requests, creating unintended sharing between users." The recommended mitigation is to avoid static state entirely, or manage it manually via lifecycle listeners.

This directly impacts the toolkit's `ApiResource::$schemaCache` (private static array with no public flush method) and `RepositoryResolver::$repositories` / `$map` (static arrays, with only `$repositories` having a `flush()` method).

**Evidence:** [Laravel Octane Best Practices](https://github.com/michael-rubel/laravel-octane-best-practices) — static property warnings. [Laravel 12.x Octane Documentation](https://laravel.com/docs/12.x/octane) — "Be careful... adding data to a statically maintained array will result in a memory leak." No mention of any framework-provided static property reset mechanism.

**Confidence:** High — multiple independent sources confirm static properties require manual management.

### Finding 3: Octane's `OperationTerminated` Event Is the Established Hook for Package Cache Flushing

**Observation:** Octane provides a listener-based architecture for managing state between requests. The key event hierarchy is:

| Event | When Fired | Scope |
|-------|------------|-------|
| `RequestReceived` | Before handling an HTTP request | Octane HTTP only |
| `RequestTerminated` | After handling an HTTP request | Octane HTTP only |
| `TaskTerminated` | After a task completes | Octane tasks |
| `TickTerminated` | After a tick interval | Octane ticks |
| `OperationTerminated` | Interface implemented by all three above | All Octane contexts |

The `OperationTerminated` interface (implemented by `RequestTerminated`, `TaskTerminated`, `TickTerminated`) is the recommended hook point for package flush listeners because it covers all Octane execution contexts.

Laravel's own `FlushOnce` listener (which flushes `once()` memoised values) listens on `OperationTerminated`. Octane's default config includes `FlushTemporaryContainerInstances`, `FlushUploadedFiles`, `FlushLogContext`, and optional `DisconnectFromDatabases` and `CollectGarbage` — all on `OperationTerminated`.

**Evidence:** [Laravel Octane 2.x config/octane.php](https://github.com/laravel/octane/blob/2.x/config/octane.php). [Laravel.io: Memoisation Using the `once` Helper](https://laravel.io/articles/memoisation-in-laravel-using-the-once-helper) — documents `FlushOnce` listener on `OperationTerminated`.

**Confidence:** High — sourced directly from the Octane repository and official documentation.

### Finding 4: Spatie's Config-Flag Pattern for Opt-In Octane Flushing

**Observation:** The `spatie/laravel-permission` package (one of the most widely used Laravel packages) implements an opt-in Octane compatibility pattern:

1. A config flag `permission.register_octane_reset_listener` (default: `false`).
2. When enabled, the package registers a listener that calls `$event->sandbox->make(PermissionRegistrar::class)->clearPermissionsCollection()` on the Octane reset event.
3. The listener is NOT registered by default — users must opt in by setting the config flag.

This pattern matches the API toolkit's constraint that "cache invalidation must be opt-in (not forced on every request)."

**Evidence:** [spatie/laravel-permission documentation — Cache](https://spatie.be/docs/laravel-permission/v6/advanced-usage/cache), [GitHub issue #2575](https://github.com/spatie/laravel-permission/issues/2575) — Octane/Kubernetes deployment discussion.

**Confidence:** High — directly observed in the spatie/laravel-permission documentation and codebase.

### Finding 5: Queue Workers Require a Separate Flush Mechanism

**Observation:** Queue workers are NOT covered by Octane's `OperationTerminated` event. They have their own event system:

| Event | When Fired |
|-------|------------|
| `JobProcessing` / `Queue::before()` | Before a job is processed |
| `JobProcessed` / `Queue::after()` | After a job is successfully processed |
| `JobFailed` / `Queue::failing()` | When a job fails |
| `Queue::looping()` | Before the worker polls for the next job |

A separate listener or the same flush logic must be registered on `JobProcessed` and `JobFailed` to clear stale state between jobs. There is no unified event interface spanning both Octane and queue workers — they are parallel systems requiring parallel registration.

The `Cache::memo()` auto-flush handles the memo layer for both contexts, but static properties and singleton state must be flushed manually in both.

**Evidence:** [Laravel 12.x Queues Documentation](https://laravel.com/docs/12.x/queues) — queue events section.

**Confidence:** High — official Laravel documentation confirms the event separation.

### Finding 6: `once()` Helper as the Recommended Alternative to Static Property Caching

**Observation:** Laravel's `once()` helper provides a framework-managed alternative to static property caching that automatically handles Octane compatibility. It:

1. Caches the return value of a closure for the duration of the current request.
2. Is automatically flushed via the `FlushOnce` listener on `OperationTerminated`.
3. Scopes caching per-instance for object methods and globally for static methods.

The `once()` helper is semantically equivalent to what `ApiResource::$schemaCache` does manually (cache compiled schema per class), but with built-in lifecycle management. However, `once()` does not support explicit cache keys — it caches by call site, which may not suit all use cases (e.g., caching per-model-class rather than per-call-site).

**Evidence:** [Laravel.io: Memoisation Using the `once` Helper](https://laravel.io/articles/memoisation-in-laravel-using-the-once-helper). The `FlushOnce` listener calls `Once::flush()` which sets the internal state to `null`, discarding all cached values.

**Confidence:** Medium — `once()` is well-documented for Octane, but its key-less caching model may not map directly to all toolkit use cases (e.g., keyed per model class).

---

## Implications

- The `Cache::memo()` auto-flush in Laravel 12.9+ already solves the in-memory memoization layer for both Octane and queue workers. The remaining problem is the `rememberForever()` values in the underlying cache store and the static property caches.
- A two-pronged approach is needed: (1) an `OperationTerminated` listener for Octane contexts, and (2) `JobProcessed`/`JobFailed` listeners for queue worker contexts. Both must flush the same set of caches.
- The spatie config-flag pattern (`register_octane_reset_listener`) is a proven, well-understood opt-in mechanism that matches the toolkit's constraint of not degrading FPM performance.
- Static property caches (`ApiResource::$schemaCache`, `RepositoryResolver` statics) require explicit public flush methods to be added — they cannot be managed externally.
- The `once()` helper could replace some static property caches, but may require architectural changes to support per-key caching semantics.
- `Cache::memo()->rememberForever()` may need to be reconsidered — if the intent is process-lifetime caching, the `rememberForever()` call to the underlying store creates permanent persistence that survives even FPM restarts (when using Redis/file drivers). This could cause staleness even outside long-running contexts.

---

## Open Threads

- Should the `rememberForever()` calls be replaced with TTL-based `remember()` to prevent permanent staleness in the underlying store, or is `rememberForever()` intentional because the cached metadata (schema columns, relation detection, casts) is expected to be stable within a deployment?
- Can the toolkit's `ApiServiceProvider` detect whether Octane is installed and auto-register the flush listener, or should it always require explicit opt-in?
- Should the `once()` helper be used for `ApiResource::$schemaCache` instead of a manual static array, given its built-in Octane compatibility?
- Does `Cache::memo()` auto-flush extend to Reverb (WebSocket server) contexts, or is that a separate long-running environment that needs its own flush mechanism?

---

## References

- Traces to: [Intake Brief](../intake-brief.md)
- Sources:
  - [Laravel 12.x Cache Documentation — Memoization](https://laravel.com/docs/12.x/cache)
  - [Laravel 12.x Octane Documentation](https://laravel.com/docs/12.x/octane)
  - [Laravel News: Memoized Cache Driver in Laravel 12.9](https://laravel-news.com/laravel-12-9-0)
  - [Laravel Octane Best Practices](https://github.com/michael-rubel/laravel-octane-best-practices)
  - [Memoisation in Laravel Using the `once` Helper](https://laravel.io/articles/memoisation-in-laravel-using-the-once-helper)
  - [spatie/laravel-permission — Cache Documentation](https://spatie.be/docs/laravel-permission/v6/advanced-usage/cache)
  - [Laravel Octane 2.x config/octane.php](https://github.com/laravel/octane/blob/2.x/config/octane.php)
  - [Laravel 12.x Queues Documentation](https://laravel.com/docs/12.x/queues)
  - [Laravel Framework Discussion #47958 — Shared Static Properties](https://github.com/laravel/framework/discussions/47958)
