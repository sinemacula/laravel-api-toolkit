# Spike: Deferred Write Patterns for Laravel Repositories

Investigated how deferred/buffered database writes can be implemented within the Laravel request lifecycle, covering flush hooks, bulk operation primitives, existing ecosystem patterns, and codebase integration points.

---

## Governance

| Field     | Value                                                                                              |
|-----------|----------------------------------------------------------------------------------------------------|
| Created   | 2026-02-26                                                                                         |
| Status    | draft                                                                                              |
| Owned by  | Researcher                                                                                         |
| Traces to | [Intake Brief](../intake-brief.md)                                                                 |

---

## Research Question

What mechanisms exist in Laravel for reliably flushing a pool of buffered database writes at the end of a request, what bulk operation primitives are available, and how do existing packages solve this problem?

---

## Methodology

Four parallel research tracks were conducted:

1. **Laravel lifecycle hooks** -- web research on Laravel terminate events, `RequestHandled`, terminable middleware, queue job events, and Octane lifecycle; cross-referenced with framework source code (Kernel, Application, Worker).
2. **Eloquent bulk operations** -- web research on `insert()`, `upsert()`, `insertOrIgnore()`, `fillAndInsert()`, batch size limits, and ID retrieval; referenced Laravel 12.x documentation and community benchmarks.
3. **Deferred write patterns** -- web research on spatie/laravel-activitylog, owen-it/laravel-auditing, Monolog BufferHandler, Laravel Telescope, laravel-batch packages, and Laravel's `defer()` helper.
4. **Existing codebase analysis** -- explored `ApiRepository`, `ApiServiceProvider`, `Service`, repository traits, and configuration to identify integration points and architectural constraints.

---

## Findings

### Finding 1: No Single Universal Flush Hook Exists

**Observation:** There is no single Laravel event or callback that fires reliably at the end of every execution context (HTTP, CLI, queue jobs). Coverage requires a combination approach.

**Evidence:**

| Hook                          | HTTP | CLI | Queue (per-job) |
|-------------------------------|------|-----|------------------|
| `app()->terminating()`        | Yes  | Yes | No               |
| `Terminating` event           | Yes  | Yes | No               |
| Terminable middleware          | Yes  | No  | No               |
| `RequestHandled` event        | Yes  | No  | No               |
| `JobProcessed` / `JobFailed`  | No   | No  | Yes              |

The established pattern (used by Laravel Telescope) is:
- `app()->terminating()` for HTTP + CLI contexts
- `JobProcessed` / `JobFailed` event listeners for queue jobs

The `app()->terminating()` callbacks execute **after** the response is sent to the client (in HTTP), making them ideal for deferred writes that don't need to be visible in the response.

**Confidence:** High -- confirmed against Laravel 12.x framework source code and multiple independent sources.

### Finding 2: Eloquent Bulk Insert Is the Right Primitive for Pooled Inserts

**Observation:** `Model::insert()` accepts an array of associative arrays and executes a single `INSERT` statement. It is the most efficient primitive for flushing a pool of deferred inserts.

**Evidence:**

| Method               | Events | Timestamps | Returns    | Bulk |
|----------------------|--------|------------|------------|------|
| `Model::create()`    | Yes    | Yes        | Model      | No   |
| `Model::insert()`    | No     | No         | `bool`     | Yes  |
| `Model::upsert()`    | No     | Yes        | `int`      | Yes  |
| `Model::fillAndInsert()` | No | Yes        | `bool`     | Yes  |

Key constraints:
- MySQL hard limit of **65,535 placeholders** per prepared statement (~6,553 rows for a 10-column table)
- Recommended batch size: 1,000-5,000 rows (benchmarks show diminishing returns beyond this)
- Bulk inserts **do not** fire model events and **cannot** return auto-increment IDs
- `Model::upsert()` supports insert-or-update in a single statement (useful for deferred updates)

**Confidence:** High -- documented in Laravel 12.x official documentation and confirmed by community benchmarks showing ~95% speedup vs individual `create()` calls.

### Finding 3: No Mainstream Laravel Package Implements a Repository-Level Write Buffer

**Observation:** The two most popular audit/logging packages (spatie/laravel-activitylog, owen-it/laravel-auditing) both perform synchronous, per-event database writes. Neither implements batching or deferral. No mainstream package provides a generic repository-level write buffer.

**Evidence:**
- **spatie/laravel-activitylog**: Every model event triggers an immediate `Activity` insert (~2-10ms per logged action). A deferred mode was proposed in Discussion #1353 (December 2024) but has zero replies and no implementation.
- **owen-it/laravel-auditing**: Synchronous writes by default. Queue mode exists but was removed and re-added due to loss of session context (cannot resolve authenticated user in queue worker).
- **wfeller/laravel-batch** and **mavinoo/laravelBatch**: Provide bulk operation helpers but are explicit-execution tools, not automatic buffers.

**Confidence:** High -- verified against package source code, GitHub issues, and documentation.

### Finding 4: Telescope and Monolog BufferHandler Are the Canonical Reference Implementations

**Observation:** Two existing implementations demonstrate the "collect in memory, flush at lifecycle boundary" pattern that maps directly to the proposed feature.

**Evidence:**

**Laravel Telescope:**
- Accumulates entries in memory throughout the request via `ListensForStorageOpportunities`
- Flushes via `app()->terminating()` for HTTP/CLI
- Flushes via `JobProcessed` / `JobFailed` for queue jobs
- Uses chunked `insert()` to avoid exceeding parameter binding limits (~250 rows per chunk for SQL Server compatibility)

**Monolog BufferHandler:**
- `handle()` accumulates records in an in-memory `$buffer` array
- Registers `$this->close()` via `register_shutdown_function()` as a safety net
- `flush()` calls `$this->handler->handleBatch($this->buffer)` then clears the buffer
- Supports `$bufferLimit` with configurable overflow behaviour (flush vs drop oldest)

Both patterns share the same architecture: collect during execution, flush at a defined boundary, with a safety net for abnormal termination.

**Confidence:** High -- verified against Telescope source code and Monolog source code.

### Finding 5: The Existing Codebase Has Clear Integration Points

**Observation:** The current `ApiRepository::setAttributes()` method is the sole persistence entry point, and the `ApiServiceProvider` already registers event listeners — both are natural integration points for a deferred write pool.

**Evidence:**

- **`ApiRepository::setAttributes(Model $model, array|Collection $attributes): bool`** iterates attributes, resolves casts, calls `$model->save()`, then syncs relations. The `save()` call at line 139 is the single point where persistence occurs.
- **`ApiServiceProvider::boot()`** already registers middleware, Eloquent macros, and event listeners (`NotificationSending`, `NotificationSent`). Adding a `terminating()` callback here follows the existing pattern.
- **Trait-based composition** is the standard extension mechanism in the codebase (`ResolvesResource`, `ManagesCriteria`, `Lockable`). A `DefersWrites` trait on the repository would be architecturally consistent.
- **Configuration** lives in `config/api-toolkit.php` with clear section separation. A new `deferred_writes` section would fit naturally.
- The sibling `sinemacula/laravel-repositories` provides the base `Repository` class. Any changes to the base persistence mechanism would need to live in this toolkit package (which extends it via `ApiRepository`).

**Confidence:** High -- confirmed by direct codebase analysis.

### Finding 6: Deferred Updates Are Significantly More Complex Than Deferred Inserts

**Observation:** While deferred inserts are straightforward (collect rows, bulk insert), deferred updates introduce coalescing challenges and conflict with the current `setAttributes()` contract which modifies and saves an existing model.

**Evidence:**
- `setAttributes()` operates on an existing `Model` instance, calling `$model->save()`. Deferring this means the in-memory model has unsaved state, which could cause consistency issues if read before flush.
- Multiple deferred updates to the same record would need coalescing (last-write-wins or merge).
- Eloquent has no native bulk update-with-different-values method (only `upsert()`, which creates rows that don't exist).
- Audit packages that attempted deferred writes (owen-it queue mode) encountered context-loss issues.

**Confidence:** Medium -- based on architectural analysis and ecosystem precedent, but would benefit from prototype validation.

---

## Implications

- **A write pool for inserts is viable and well-precedented.** The Telescope pattern (collect + `terminating()` flush + chunked `insert()`) maps directly to this use case. Deferred inserts for audit logs, activity tracking, and similar fire-and-forget writes are the sweet spot.

- **Deferred updates should be out of scope for the initial implementation.** The coalescing complexity, potential consistency issues, and lack of ecosystem precedent make updates a poor fit for an initial release. A phased approach — inserts first, updates later if needed — reduces risk.

- **The flush hook must cover multiple contexts.** Using only `app()->terminating()` covers HTTP + CLI but misses queue jobs. Telescope's combination approach is the proven pattern.

- **A safety-net flush mechanism is advisable.** Monolog's `register_shutdown_function()` pattern provides a last-resort flush for abnormal termination. This is especially important for deferred writes where data loss is worse than for logs.

- **The pool should be scoped per-model type (table).** Bulk `insert()` operates on a single table. Grouping deferred writes by model class allows one `insert()` per table at flush time.

- **Chunk size must respect MySQL's 65,535 placeholder limit.** A sensible default (e.g., 500 rows per chunk) with configurability handles varying column counts.

---

## Open Threads

- Should the pool use `insert()` (fastest, no timestamps) or `fillAndInsert()` (applies casts and timestamps, Laravel 12.6+)?
- What is the exact failure mode if a chunked flush partially fails — should it use a database transaction?
- How should the pool interact with the Service layer's existing `$useTransaction` wrapping?
- Is `register_shutdown_function()` reliable enough as a safety net, or should the pool also integrate with Laravel's exception handler?

---

## References

- Traces to: [Intake Brief](../intake-brief.md)
- Sources:
  - Laravel 12.x Request Lifecycle, Middleware, Queues, Container documentation
  - Laravel Framework source: Kernel, Application, Worker (12.x)
  - Laravel Telescope source: `ListensForStorageOpportunities` trait
  - Monolog source: `BufferHandler`
  - spatie/laravel-activitylog: GitHub issues, discussions, documentation
  - owen-it/laravel-auditing: GitHub issues, configuration, documentation
  - wfeller/laravel-batch, mavinoo/laravelBatch: GitHub, Packagist
  - George Buckingham: Laravel Database Upsert benchmarks
  - Laravel News: `fillAndInsert()` announcement
  - Community articles on terminable middleware, `defer()` helper, Octane lifecycle
