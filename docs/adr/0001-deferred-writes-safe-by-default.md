# 0001 — Deferred writes are safe-by-default

- Status: Accepted
- Date: 2026-06-13

## Context

The deferred-write pool (`WritePool`, driven by the `Deferrable` trait) buffers insert attribute
arrays in memory and flushes them as chunked bulk INSERT statements at lifecycle boundaries
(`RequestHandled`, `CommandFinished`, `JobProcessed`, `JobFailed`). Its failure posture was
unsafe-by-default in three ways:

1. **Log-and-drop default.** The default `on_failure` strategy was `log`, which caught a chunk
   failure, logged at error level, continued, and then **cleared the entire buffer** — silently
   discarding the failed records. A single constraint violation in a buffered batch lost the
   affected rows with no retention and no inspectable signal beyond a log line.
2. **Memory-pressure auto-flush downgrade.** When `add()` crossed the pool limit it triggered an
   automatic flush whose strategy was rewritten so it could never throw: a consumer configured
   `throw` silently got `collect` on the auto-flush. The loud contract the consumer asked for was
   quietly broken.
3. **Boundary subscriber swallowed throwables.** The `WritePoolFlushSubscriber` caught every
   `\Throwable` at the boundary and downgraded it to a generic error log, so a configured `throw`
   never surfaced loudly and its partial result was lost.

The dropped-record count was also unobservable: counters tracked chunks, not records, so a "1
chunk failed" message hid that the chunk held hundreds of rows.

## Decision

Make the deferred-write pool safe-by-default — no silent data loss — while keeping the boundary
flush from hard-crashing completed requests.

- **New default `on_failure = 'collect'` (retain, not drop).** The safe default catches every
  chunk failure, accumulates it in the returned `WritePoolFlushResult`, and **retains the failed
  records in the in-memory buffer** for the next flush attempt. No record is dropped and no
  exception escapes. The default was *not* set to `throw`: the boundary flush runs on
  `RequestHandled` *after* the response is built, so a default-throw would turn any single
  constraint violation into a 500 for an already-completed request. `throw` remains available for
  callers that own an explicit `flushWrites()` site and want a raised exception with the partial
  result.
- **Retain = stay in the buffer.** No dead-letter store ships in the core; the buffer is the
  natural retry queue. Failed records are observable synchronously via the returned result
  (`failures()`, record-level counts) and at the boundary via the already-dispatched
  `WritePoolFlushFailed` event, which consuming applications can subscribe to for dead-letter
  sinks, alerting, or metrics.
- **Loud boundary subscriber.** The subscriber's catch is split: a `WritePoolFlushException` (the
  expected loud failure under `throw`) is escalated with a warning log and a dispatched
  `WritePoolFlushFailed` event carrying the partial result — it is no longer flattened to a
  generic error log. It is re-thrown only when the new `rethrow_at_boundary` flag is enabled
  (default off, so the boundary is never hard-crashed). Any other `\Throwable` (e.g. container
  resolution failure) keeps the generic error log.
- **Memory-pressure auto-flush honours the strategy.** The downgrade is removed; the `add()`
  auto-flush uses the configured strategy unchanged. Under `throw` it now raises out of `add()` /
  `defer()`, which gain a documented `@throws`.
- **Per-table transactional opt-in.** A new `transactional` flag (default off) wraps each table's
  chunk set in `DB::transaction()` so that table's inserts are applied all-or-nothing. Granularity
  is per-table, not whole-batch, to bound the blast radius and transaction duration. The default
  off-path is the unchanged per-chunk loop, preserving happy-path performance and the existing
  partial-persist semantics.
- **Record-level counts.** `WritePoolFlushResult` gains `flushedRecordCount`, `failedRecordCount`,
  `retainedRecordCount`, and `droppedRecordCount` alongside the existing chunk counters, so the
  "dropped-record counts surfaced" requirement is met.

## Consequences

- **Breaking change: default flip.** The default behaviour changes from log-and-drop to
  collect-and-retain. This is intentional — the old default was the silent-data-loss bug. Existing
  `Deferrable` consumers that relied on drop-on-failure now retain failed records in the scoped
  pool and re-attempt them on each subsequent boundary within the scope's lifetime. Opt back into
  the old behaviour with `DEFERRED_WRITES_ON_FAILURE=log`, which is now an explicit choice for
  genuinely disposable writes (audit, analytics, telemetry).
- **Auto-flush under `throw` can raise from `defer()`.** Previously impossible (downgraded to
  `collect`). Consumers that set `throw` and relied on `add()` never throwing get a new exception
  surface at their `defer()` call site; this is the documented fix, and `defer()` declares
  `@throws`.
- **Octane / scoped-singleton retention.** The pool is a request-scoped singleton. Retained
  failed records persist only for the scoped container lifetime and are discarded when the scope
  is reset between requests/jobs (the toolkit's Octane and queue cache-flush listeners do not
  touch the pool). The boundary subscriber runs before the scope reset, so retention is a
  within-scope retry, not a cross-request leak. For fire-and-forget writes that must never be
  retained, `log` remains the escape hatch.
- **Crash window.** Buffered writes live only in PHP memory until the boundary flush. A crash,
  out-of-memory condition, or SIGKILL before the flush loses any unflushed records. This is
  inherent to in-memory deferral; for true durability use a real queue. Documented in the
  `Deferrable` trait docblock, the `deferred_writes` config comment, and UPGRADE.md.
- **Transactional trade-offs.** A per-table transaction over a large `pool_limit` (default 10000
  rows) can be heavy; the default-off setting mitigates this. Under `transactional + collect`, a
  rolled-back table retains *all* its records (including chunks that committed before the failing
  chunk), reflected in the record-count accounting — a deliberate, documented difference from
  non-transactional collect.
- **Non-breaking additions.** The new `transactional` and `rethrow_at_boundary` config keys both
  default to behaviour-preserving values; the new `WritePoolFlushResult` getters are additive with
  defaulted constructor parameters; the `FlushStrategy` enum cases and backing values are
  unchanged, so persisted config strings remain valid.
