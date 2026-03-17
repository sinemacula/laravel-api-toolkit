# PRD: WritePool Flush Reliability

Make `WritePool::flush()` failures visible, configurable, and recoverable -- replacing the current silent data loss
behavior with structured flush results, configurable failure strategies, and failed-record preservation while
maintaining
backward compatibility with the existing log-and-continue default.

---

## Governance

| Field     | Value                                                                                                             |
|-----------|-------------------------------------------------------------------------------------------------------------------|
| Created   | 2026-03-15                                                                                                        |
| Status    | approved                                                                                                          |
| Owned by  | Product Analyst                                                                                                   |
| Traces to | [Prioritization](../../.sinemacula/blueprint/workflows/writepool-silent-failure/prioritization.md)                |
| Problems  | P1 (flush swallows exceptions), P2 (failed records lost), P4 (no structured result), P7 (subscriber cannot react) |

---

## Background
 
The `WritePool` class buffers insert attribute arrays in memory and flushes them as chunked bulk `INSERT` statements.
It is used by the `Deferrable` trait to give API repositories opt-in deferred write capability. The
`WritePoolFlushSubscriber` ensures buffered records are flushed at lifecycle boundaries (end of HTTP request, CLI
command, or queue job).

When a chunk insert fails, `WritePool::flush()` catches the exception, logs it, and continues to the next chunk. After
all chunks are attempted, the buffer is unconditionally cleared. This means:

- Failed records are permanently lost with no mechanism to recover or retry them
- Callers have no way to detect that records were dropped (`flush()` returns `void`)
- The `WritePoolFlushSubscriber` fires at the last possible moment with no failure awareness
- Transient failures (deadlocks, connection timeouts) cause the same permanent data loss as constraint violations

The feature is well-tested (unit and integration), configuration exists at `api-toolkit.deferred_writes`, and the
WritePool is registered as a scoped singleton for Octane compatibility.

---

## User Capabilities

### UC-1: Developer can detect flush failures programmatically

A developer calling `flush()` or `flushWrites()` can inspect the result to determine whether all records were
persisted, how many succeeded, how many failed, and which tables had failures.

**Acceptance criteria:**

- `flush()` returns a `WritePoolFlushResult` value object
- `WritePoolFlushResult` exposes: `isSuccessful(): bool`, `successCount(): int`, `failureCount(): int`,
  `totalCount(): int`, `failures(): array` (keyed by table name, containing failed records and exception details)
- `Deferrable::flushWrites()` returns the same `WritePoolFlushResult`, propagating it from the underlying WritePool
- When no failures occur, `isSuccessful()` returns `true` and `failureCount()` returns `0`
- When failures occur, `isSuccessful()` returns `false` and `failures()` contains the affected tables, record data, and
  exception messages
- The `WritePoolFlushResult` is immutable

### UC-2: Developer can configure the failure strategy

A developer can configure how `flush()` behaves when a chunk insert fails, choosing between logging (current behavior),
throwing, or collecting failures.

**Acceptance criteria:**

- A new config key `api-toolkit.deferred_writes.on_failure` accepts values: `log` (default), `throw`, `collect`
- `log` strategy: catch exception, log at error level, continue to next chunk, clear buffer, return result with failure
  details (backward-compatible default)
- `throw` strategy: on first chunk failure, throw a `WritePoolFlushException` containing the flush result (with partial
  success/failure details). Failed records from the throwing chunk and all unprocessed records are preserved in the
  buffer. Successfully inserted chunks are not rolled back.
- `collect` strategy: catch exception, accumulate failure details, continue to all remaining chunks, then return result.
  Failed records are preserved in the buffer. Successfully inserted chunks are cleared from the buffer.
- The strategy is passed to the `WritePool` constructor via the service provider binding
- The strategy can be overridden per flush call: `flush(strategy: FlushStrategy::Throw)`

### UC-3: Developer can recover failed records

A developer can access records that failed to persist and retry them, either by re-flushing or by inspecting and
correcting them.

**Acceptance criteria:**

- When using the `throw` or `collect` strategy, records from failed chunks are retained in the buffer
- Records from successfully inserted chunks are removed from the buffer
- After a partial flush failure, `count()` returns only the count of retained (failed) records
- A subsequent `flush()` call attempts to insert only the retained records
- `isEmpty()` returns `false` when failed records are retained
- The `log` strategy preserves the current behavior: all records are cleared from the buffer regardless of failures

### UC-4: WritePoolFlushSubscriber handles flush failures appropriately

The lifecycle subscriber detects flush failures and takes configurable action rather than silently discarding them.

**Acceptance criteria:**

- The subscriber inspects the `WritePoolFlushResult` returned by `flush()`
- When the result indicates failures, the subscriber logs a warning with failure summary (table names, failure count,
  total count)
- The subscriber dispatches a `WritePoolFlushFailed` event when failures are detected, containing the
  `WritePoolFlushResult`
- Consuming applications can listen for `WritePoolFlushFailed` to implement custom escalation (alerting, dead-letter
  queue, metrics)
- The subscriber does not throw -- lifecycle boundary handlers must not interfere with the response or process lifecycle

### UC-5: Auto-flush failures are handled consistently

When `add()` triggers an auto-flush due to the pool limit, the failure behavior matches the configured strategy without
surprising the caller.

**Acceptance criteria:**

- Auto-flush in `add()` uses the `collect` strategy regardless of the configured strategy (auto-flush must not throw
  from `add()`)
- Failed records from auto-flush are retained in the buffer
- The auto-flush result is accessible via a new method: `lastAutoFlushResult(): ?WritePoolFlushResult`
- If the configured strategy is `log`, auto-flush falls back to `log` behavior (backward compatible: buffer cleared,
  error logged)

---

## Out of Scope

- **Chunk subdivision / binary search for bad records:** Subdividing a failed chunk to isolate invalid records and save
  valid ones is a valuable optimization but adds significant complexity and database round trips. Deferred to a future
  iteration. (Addresses P3 from the problem map.)
- **Per-chunk retry for transient failures:** Retry with backoff for deadlocks and connection timeouts is a natural
  follow-on but is a separate concern. The `collect` strategy with failed-record retention provides the foundation for
  retry -- a future `RetryableWritePool` decorator or built-in retry strategy can build on the flush result and buffer
  preservation. (Addresses P6 from the problem map.)
- **Record-level logging:** Logging full record data in error messages raises privacy/security concerns and is
  deliberately avoided. The flush result object provides programmatic access to failed records for callers that need
  them. (Addresses P5 from the problem map.)
- **Dead-letter queue integration:** Persisting failed records to a separate storage (database table, file, queue) for
  later recovery is a consuming-application concern. The `WritePoolFlushFailed` event provides the hook for applications
  to implement this.

---

## New Classes

| Class                     | Namespace                                     | Purpose                                                                                                                             |
|---------------------------|-----------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------|
| `WritePoolFlushResult`    | `SineMacula\ApiToolkit\Repositories\Concerns` | Immutable value object returned by `flush()` containing success/failure counts, table-level failure details, and failed record data |
| `WritePoolFlushException` | `SineMacula\ApiToolkit\Exceptions`            | Exception thrown by the `throw` strategy, wrapping the `WritePoolFlushResult` for callers using try/catch                           |
| `FlushStrategy`           | `SineMacula\ApiToolkit\Enums`                 | Enum with cases `Log`, `Throw`, `Collect` representing the configurable failure behavior                                            |
| `WritePoolFlushFailed`    | `SineMacula\ApiToolkit\Events`                | Event dispatched by the subscriber when flush reports failures, containing the `WritePoolFlushResult`                               |

---

## Modified Classes

| Class                      | Change                                                                                                                                                                                                                                                                                                                                             |
|----------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `WritePool`                | Accept `FlushStrategy` in constructor; change `flush()` return type from `void` to `WritePoolFlushResult`; implement strategy-specific behavior; selective buffer clearing for `throw`/`collect` strategies; add `flush(strategy:)` override parameter; add `lastAutoFlushResult()` method; update auto-flush in `add()` to use `collect` strategy |
| `Deferrable`               | Change `flushWrites()` return type from `void` to `WritePoolFlushResult`; propagate result from WritePool                                                                                                                                                                                                                                          |
| `WritePoolFlushSubscriber` | Inspect `WritePoolFlushResult` from `flush()`; log warning on failure; dispatch `WritePoolFlushFailed` event                                                                                                                                                                                                                                       |
| `ApiServiceProvider`       | Pass `on_failure` config to WritePool constructor as `FlushStrategy`                                                                                                                                                                                                                                                                               |

---

## Configuration Changes

```php
'deferred_writes' => [
    'chunk_size'  => (int) env('DEFERRED_WRITES_CHUNK_SIZE', 500),
    'pool_limit'  => (int) env('DEFERRED_WRITES_POOL_LIMIT', 10000),
    'on_failure'  => env('DEFERRED_WRITES_ON_FAILURE', 'log'),  // log, throw, collect
],
```

---

## Backward Compatibility

- **Default behavior is unchanged:** The default `on_failure` strategy is `log`, which matches the current behavior
  (catch, log, continue, clear buffer). Existing applications see no behavioral change without explicit configuration.
- **Return type change is additive:** Changing `flush()` from `void` to `WritePoolFlushResult` is backward-compatible
  because PHP does not enforce void return type matching on callers. Existing code that calls `flush()` without
  capturing the return value continues to work.
- **`flushWrites()` return type change is additive:** Same rationale as `flush()`.
- **Existing tests remain valid:** The `log` strategy test assertions (buffer cleared after failure, error logged)
  remain true for the default configuration. New tests cover the `throw` and `collect` strategies.
- **Subscriber behavior is extended, not changed:** The subscriber adds logging and event dispatch on failure but does
  not change its existing flush call. The `WritePoolFlushFailed` event is only dispatched when failures occur.

---

## Success Metrics

| Metric                               | Baseline                               | Target                                               | Measurement                                                                |
|--------------------------------------|----------------------------------------|------------------------------------------------------|----------------------------------------------------------------------------|
| Flush failures detectable by callers | 0% (void return, swallowed exceptions) | 100% (all flush calls return result)                 | Code inspection: all flush paths return `WritePoolFlushResult`             |
| Failed records recoverable           | 0% (buffer unconditionally cleared)    | 100% with `throw`/`collect` strategy                 | Test: records from failed chunks are retained and re-flushable             |
| Backward compatibility               | N/A                                    | 100% -- no behavioral change with default config     | Test: existing test suite passes without modification under `log` strategy |
| Subscriber failure awareness         | 0% (fire-and-forget void call)         | 100% (result inspected, event dispatched on failure) | Test: `WritePoolFlushFailed` event dispatched when flush has failures      |

---

## Testing Strategy

- **Unit tests for `WritePoolFlushResult`:** Construction, immutability, accessor methods, edge cases (all success, all
  failure, mixed).
- **Unit tests for `FlushStrategy` enum:** Case values, from-string construction for config parsing.
- **Unit tests for `WritePool` with each strategy:**
    - `log` strategy: existing behavior preserved (buffer cleared, error logged, result returned with failure details)
    - `throw` strategy: exception thrown on first failure, buffer retains failed + unprocessed records, exception
      contains
      result
    - `collect` strategy: all chunks attempted, buffer retains only failed records, result contains all failure details
    - Per-call strategy override: `flush(strategy: FlushStrategy::Throw)` overrides constructor config
    - Auto-flush uses `collect` strategy regardless of config
- **Unit tests for `WritePoolFlushSubscriber`:** Result inspection, warning logging on failure, `WritePoolFlushFailed`
  event dispatch.
- **Integration tests:** End-to-end deferred write with simulated failures under each strategy, verifying record
  retention and flush result accuracy.
- **Backward compatibility tests:** Existing `WritePoolTest` and `DeferrableIntegrationTest` assertions pass unchanged
  under default `log` strategy.

---

## References

- Prioritization: .sinemacula/blueprint/workflows/writepool-silent-failure/prioritization.md
- Problem Map: .sinemacula/blueprint/workflows/writepool-silent-failure/problem-map.md
- Spike: .sinemacula/blueprint/workflows/writepool-silent-failure/spikes/spike-failure-modes-and-integration.md
- Intake Brief: .sinemacula/blueprint/workflows/writepool-silent-failure/intake-brief.md
- Source: ISSUES.md (ISSUE-22)
