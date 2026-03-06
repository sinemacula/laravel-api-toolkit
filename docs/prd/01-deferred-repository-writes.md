# PRD: 01 Deferred Repository Writes

A write pool that buffers fire-and-forget insert operations through the repository layer and flushes them automatically
at the end of the request lifecycle.

---

## Governance

| Field     | Value                                                                                                                                                                                          |
|-----------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Created   | 2026-02-26                                                                                                                                                                                     |
| Status    | approved                                                                                                                                                                                       |
| Owned by  | Ben                                                                                                                                                                                            |
| Traces to | [Prioritization](../../.sinemacula/.blueprint/workflows/deferred-repository-operations/prioritization.md) — Ranks 1-3: Per-call write penalty, No batching abstraction, Octane state isolation |

---

## Overview

Developers using the Laravel API Toolkit frequently perform many small insert operations within a single request — audit
log entries, activity records, notification logs, and other cross-cutting writes. Each of these currently executes an
individual database query immediately through the repository layer, creating unnecessary round trips that degrade
request performance.

This PRD defines a deferred write pool that collects insert operations in memory and flushes them as a single bulk
operation at the end of the request lifecycle. The pool integrates transparently with the existing repository API,
requiring minimal changes to call sites — developers opt into deferral per operation while the system handles
collection, lifecycle management, and bulk execution automatically.

The feature must work correctly across all Laravel 12 execution contexts — standard HTTP requests, CLI commands, queue
jobs, and Octane environments — with proper state isolation to prevent cross-request data leakage in long-lived
processes.

---

## Target Users

| Persona          | Description                                                                                            | Key Need                                                                                         |
|------------------|--------------------------------------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------|
| API Developer    | Developers building REST APIs with the toolkit who perform multiple fire-and-forget writes per request | Reduce per-request database round trips for writes that don't need immediate visibility          |
| Package Consumer | Developers consuming the toolkit in Octane or queue-heavy environments                                 | Confidence that deferred writes are correctly isolated per request/job and automatically flushed |

**Primary user:** API Developer

---

## Goals

- Reduce database round trips for fire-and-forget insert operations within a single request
- Provide a repository-level abstraction for deferred writes that maintains API consistency
- Ensure correct behaviour across all Laravel 12 execution contexts (HTTP, CLI, queue, Octane)

## Non-Goals

- Deferring update operations (coalescing complexity, consistency risks — deferred to a future cycle)
- Replacing or modifying the existing synchronous write path (existing behaviour must remain unchanged)
- Providing a general-purpose database batching utility outside the repository pattern
- Supporting deferred writes that need to return the created model or its ID to the caller

---

## Problem

**User problem:** Every insert through the repository layer executes an individual database query immediately. A request
that generates 15 audit log entries incurs 15 separate INSERT round trips, even though none of these writes need to be
visible within the current request. Developers who want to batch these must collect records manually outside the
repository pattern, breaking the consistency of the abstraction.

**Business problem:** Accumulated per-call write overhead increases API response latency and database load. This is a
competitive disadvantage for a toolkit that positions itself as a performant REST API scaffold. The lack of a batching
abstraction forces developers into workarounds that undermine the repository pattern's value.

**Current state:** Developers either accept the per-call overhead or implement ad-hoc bulk insert logic outside the
repository layer, bypassing the repository abstraction and its cast resolution, relation handling, and consistency
guarantees.

**Evidence:**

- [Spike: Deferred Write Patterns](../../.sinemacula/.blueprint/workflows/deferred-repository-operations/spikes/spike-deferred-write-patterns.md) —
  Finding 2: bulk `insert()` is ~95% faster than individual `create()` calls; Finding 3: no mainstream Laravel package
  provides a repository-level write buffer
- [Problem Map](../../.sinemacula/.blueprint/workflows/deferred-repository-operations/problem-map.md) — Cluster: Write
  Performance Overhead (Problems 1-2), Cluster: Flush Lifecycle Complexity (Problem 4)

---

## Proposed Solution

A developer performing fire-and-forget inserts through a repository can opt individual operations into a deferred pool.
The pool collects these operations in memory throughout the request. At the end of the request lifecycle, the pool
automatically flushes all collected inserts as bulk operations — one per target table.

### Key Capabilities

- Developer can mark an insert operation as deferred, and it is collected in memory instead of executing immediately
- The pool flushes automatically at the end of the request lifecycle without any explicit action from the developer
- Developer can manually trigger a flush at any point during the request if needed
- Each request/job has its own isolated pool — no state leaks between requests in long-lived processes
- Existing synchronous write operations continue to work identically with no changes required

### User Journey

1. Developer identifies a write operation that doesn't need immediate persistence (e.g., audit log insert)
2. Developer modifies the call site to indicate the operation should be deferred
3. The repository accepts the data and adds it to the in-memory pool instead of persisting immediately
4. The request continues normally — more deferred writes may accumulate
5. At request end, all pooled inserts are automatically flushed as bulk operations
6. If the developer needs mid-request persistence, they can trigger a manual flush

---

## Requirements

### Must Have (P0)

- **Deferred insert capability:** Developer can defer an insert operation through the repository so that it is collected
  in memory rather than persisted immediately
  - **Acceptance criteria:** A deferred insert does not execute a database query at the time of the call; the data is
      held in memory until flush

- **Automatic lifecycle flush:** The pool flushes all collected inserts automatically at the end of the request
  lifecycle without developer intervention
  - **Acceptance criteria:** All deferred inserts accumulated during a request are persisted to the database after the
      response is sent, with zero explicit flush calls from the developer

- **Bulk execution:** The flush executes collected inserts as bulk operations grouped by target table, not as individual
  queries
  - **Acceptance criteria:** A request that defers N inserts to the same table results in ceil(N / chunk_size) INSERT
      statements at flush time, not N individual statements

- **Multi-context support:** The pool flushes correctly in HTTP requests, CLI commands, and queue jobs under Laravel 12
  - **Acceptance criteria:** Deferred inserts are flushed at the end of an HTTP request, at the end of a CLI command,
      and after each queue job completes (including failed jobs)

- **Octane isolation:** Each Octane request has its own isolated pool with no state leakage between requests
  - **Acceptance criteria:** Two consecutive Octane requests that each defer inserts produce independent bulk flushes;
      no data from request A appears in request B's flush

- **Backward compatibility:** All existing repository write operations continue to function identically
  - **Acceptance criteria:** The full existing test suite passes without modification after the feature is added

- **Timestamp preservation:** Deferred inserts record timestamps at the time of deferral, not at the time of flush
  - **Acceptance criteria:** A deferred insert created at 12:00:01 and flushed at 12:00:05 has a `created_at` value of
      12:00:01

### Should Have (P1)

- **Manual flush:** Developer can trigger a flush of the pool at any point during the request
  - **Acceptance criteria:** After a manual flush call, all currently pooled inserts are persisted and the pool is
      empty; subsequent deferred inserts accumulate into a fresh pool

- **Chunked flush:** The flush respects database placeholder limits by splitting large pools into chunks
  - **Acceptance criteria:** A pool of 10,000 inserts for a 10-column table flushes successfully without exceeding
      database parameter binding limits

- **Auto-flush on pool size threshold:** The pool automatically flushes when it reaches a configurable size limit,
  preventing unbounded memory growth
  - **Acceptance criteria:** When the pool size reaches the configured threshold, all currently pooled inserts are
      flushed immediately; subsequent deferred inserts accumulate into a fresh pool

### Nice to Have (P2)

- **Flush failure handling:** When a chunk fails during flush, the error is surfaced clearly and remaining chunks are
  still attempted
  - **Acceptance criteria:** If chunk 3 of 5 fails, chunks 4 and 5 are still executed and the failure is logged with
      the specific records that failed

---

## Success Criteria

| Metric                                                      | Baseline                       | Target                                          | How Measured                                                      |
|-------------------------------------------------------------|--------------------------------|-------------------------------------------------|-------------------------------------------------------------------|
| DB queries per request for N deferred inserts to same table | N individual INSERT statements | ceil(N / chunk_size) INSERT statements          | Query log comparison in integration test with 15 deferred inserts |
| Existing test suite pass rate                               | 100%                           | 100%                                            | `composer test` — all existing tests pass without modification    |
| Execution context coverage                                  | N/A — new capability           | Flush verified in HTTP, CLI, and queue contexts | Dedicated integration tests per context                           |
| Octane isolation                                            | N/A — new capability           | Zero cross-request data leakage                 | Integration test with consecutive Octane-simulated requests       |

---

## Dependencies

- Laravel 12+ (required; minimum supported version)
- `sinemacula/laravel-repositories` (sibling package providing the base `Repository` class)

---

## Assumptions

- Deferred insert operations do not need to return the created model or its auto-increment ID to the caller
- The pool only needs to handle simple attribute arrays for insert, not complex model lifecycle operations (events,
  relations, observers)
- Laravel's `app()->terminating()` callback fires reliably per request in standard HTTP, CLI, and Octane 2.x contexts
- Fire-and-forget writes (audit logs, activity tracking) are the primary use case; operations requiring immediate
  visibility will continue to use the synchronous path

---

## Risks

| Risk                                    | Impact                                                                                                  | Likelihood | Mitigation                                                                                                                                               |
|-----------------------------------------|---------------------------------------------------------------------------------------------------------|------------|----------------------------------------------------------------------------------------------------------------------------------------------------------|
| Data loss on abnormal termination       | Pooled writes are lost if the process terminates before flush (OOM, SIGKILL, PHP fatal error)           | Low        | Accept as a known limitation for fire-and-forget writes; document clearly; consider a shutdown function safety net                                       |
| Pool grows unboundedly in long requests | Memory pressure if a request defers thousands of writes                                                 | Low        | Auto-flush at a configurable pool size threshold (P1 requirement) prevents unbounded growth; chunked flush handles large batches                         |
| Bulk insert bypasses model events       | Deferred writes flushed via bulk `insert()` do not fire Eloquent model events (creating, created, etc.) | Medium     | Document explicitly that deferred writes bypass model events; this is acceptable for fire-and-forget writes and consistent with the bulk insert contract |

---

## Out of Scope

- Deferred update operations (coalescing complexity makes this a separate feature)
- Deferred delete operations
- Returning created model instances or auto-increment IDs from deferred inserts
- Cross-request pooling (each request/job has an independent pool)
- Automatic retry of failed flush operations
- Integration with model events or observers for deferred writes

---

## Release Criteria

- All P0 requirements have passing acceptance tests
- Existing test suite passes without modification (`composer test`)
- Static analysis passes (`composer check`)
- Feature works correctly under HTTP, CLI, and queue execution contexts
- Feature works correctly under Octane 2.x with no cross-request state leakage
- Documentation of the deferred write API and its limitations (no model events, no ID retrieval, fire-and-forget only)

---

## Traceability

| Artifact             | Path                                                                                                                                      |
|----------------------|-------------------------------------------------------------------------------------------------------------------------------------------|
| Intake Brief         | .blueprint/workflows/deferred-repository-operations/intake-brief.md                                                                       |
| Relevant Spikes      | .blueprint/workflows/deferred-repository-operations/spikes/spike-deferred-write-patterns.md                                               |
| Problem Map Entry    | Write Performance Overhead > Per-call write penalty, No batching abstraction; Flush Lifecycle Complexity > Octane state isolation risk    |
| Prioritization Entry | Rank 1: Per-call write penalty (P0, score 9); Rank 2: No batching abstraction (P0, score 8); Rank 3: Octane state isolation (P0, score 7) |

---

## References

- Traces to: [Prioritization](../../.sinemacula/.blueprint/workflows/deferred-repository-operations/prioritization.md) —
  Ranks 1-3
- Intake Brief: .blueprint/workflows/deferred-repository-operations/intake-brief.md
