# Task 03: Controller Delegation

Reduce the controller's `respondWithEventStream()` to a thin delegation wrapper that instantiates `EventStream` and forwards all parameters.

---

## Governance

| Field        | Value                                                                                           |
|--------------|-------------------------------------------------------------------------------------------------|
| Created      | 2026-03-10                                                                                      |
| Status       | draft                                                                                           |
| Owned by     | Developer                                                                                       |
| Traces to    | [Architecture](../.sinemacula/build/workflows/sse-stream-extraction/architecture.md)            |
| Task Number  | 03                                                                                              |
| Tier         | 2                                                                                               |
| Dependencies | task-01, task-02                                                                                |

---

## Objective

Replace the SSE transport logic in the base controller with a thin delegation to `EventStream`, removing the private SSE methods while retaining the `HEARTBEAT_INTERVAL` constant and the unchanged `respondWithEventStream()` signature.

---

## Scope

### Files to Create

None.

### Files to Modify

| Path                              | Component  | Description of Changes                                                                                      |
|-----------------------------------|------------|--------------------------------------------------------------------------------------------------------------|
| `src/Http/Routing/Controller.php` | Controller | Replace `respondWithEventStream()` body with `EventStream` delegation; remove `runEventStream` and `runStreamCallback` private methods |

---

## Specification

### Controller Modifications

**File:** `src/Http/Routing/Controller.php`

**What to keep unchanged:**

- The class declaration (`abstract class Controller extends LaravelController`)
- The `use ValidatesRequests;` trait import
- The `HEARTBEAT_INTERVAL` constant (`protected const int HEARTBEAT_INTERVAL = 20;`)
- The `respondWithData()`, `respondWithItem()`, and `respondWithCollection()` methods (untouched)
- The `respondWithEventStream()` method signature: `protected function respondWithEventStream(callable $callback, int $interval = 1, HttpStatus $status = HttpStatus::OK, array $headers = []): StreamedResponse`

**What to change:**

1. **Replace the `respondWithEventStream()` method body.** The current body (lines 77-87) constructs headers, creates a streamed response via `Response::stream()`, and calls `$this->runEventStream()`. Replace with delegation to `EventStream`:

   ```
   return (new EventStream(static::HEARTBEAT_INTERVAL))
       ->toResponse($callback, $interval, $status, $headers);
   ```

   This is the complete method body. It instantiates `EventStream` with `static::HEARTBEAT_INTERVAL` (preserving late-static-binding for subclass overrides) and delegates all parameters unchanged.

2. **Remove the `runEventStream()` private method** (lines 100-136). This logic now lives in `EventStream::runEventStream()`.

3. **Remove the `runStreamCallback()` private method** (lines 147-158). This logic is inlined in `EventStream::runEventStream()` with the `handleStreamError()` extension point.

4. **Update imports.** Add `use SineMacula\ApiToolkit\Sse\EventStream;`. Remove `use Illuminate\Support\Facades\Response;` (no longer used by any method in the controller -- `respondWithData()` uses `Response::json()` so check carefully whether `Response` is still used). Actually, `respondWithData()` at line 37 uses `Response::json(...)`, so `Response` is still needed. Keep the import.

5. **Update the `respondWithEventStream()` docblock.** The method now delegates to `EventStream`. Update the description line to indicate delegation. The `@param` and `@return` tags remain unchanged since the signature has not changed. The callable parameter docblock type can be broadened to `callable(): void|callable(\SineMacula\ApiToolkit\Sse\Emitter): void` to reflect that the emitter-accepting signature is now supported (REQ-09). However, this is a docblock-only change -- the actual PHP type hint remains `callable`.

**After modification, the controller's SSE-related code should be:**

- The `HEARTBEAT_INTERVAL` constant (1 line)
- The `respondWithEventStream()` method (docblock + 1-line body inside the method braces)
- Zero private SSE methods

This satisfies AC-04 (controller body reduced to a delegation method of no more than 10 lines; private SSE methods removed).

### Backward Compatibility Verification

The following must be verified by running existing tests:

1. `testRespondWithEventStreamReturnsStreamedResponse` -- same response type returned
2. `testRespondWithEventStreamSetsSseHeaders` -- same headers set (SSE headers merged the same way)
3. `testRespondWithEventStreamAcceptsCustomHeaders` -- custom headers preserved
4. `testRespondWithEventStreamExecutesStreamBody` -- callback executes, heartbeat fires
5. `testRespondWithEventStreamEmitsErrorEventAndBreaksWhenCallbackThrows` -- error handling preserved
6. `testRespondWithEventStreamBreaksOnFirstCheckOfSecondIteration` -- abort detection preserved
7. `testHeartbeatIntervalConstantEqualsTwenty` -- constant still defined
8. `testHeartbeatIntervalConstantCanBeOverriddenBySubclass` -- late-static-binding preserved

All of these tests must pass WITHOUT any modification to test assertions. The function overrides in the `Sse` namespace (added in Task 01) intercept the built-in calls that now execute in the `Sse` namespace rather than the `Http\Routing` namespace.

### Critical Detail: Function Override Namespace

After this change, when `respondWithEventStream()` is called, the execution flow is:

1. Controller (in `Http\Routing` namespace) calls `EventStream::toResponse()`
2. `EventStream::toResponse()` creates a `StreamedResponse` whose callback calls `EventStream::runEventStream()`
3. `runEventStream()` (in the `Sse` namespace) calls `connection_aborted()`, `sleep()`, `flush()`, `ob_flush()`, `ob_get_level()`
4. These unqualified calls resolve to the `SineMacula\ApiToolkit\Sse` namespace stubs (added in Task 01)
5. The `Sse` namespace stubs delegate to `FunctionOverrides::get()`, which returns the test overrides

This is why the `Sse` namespace override block was added in Task 01, and why the existing controller tests will continue to pass -- the override mechanism follows the calls to their new namespace.

### Imports After Modification

The controller should have these `use` statements after modification:

```php
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Routing\Controller as LaravelController;
use Illuminate\Support\Facades\Response;
use SineMacula\ApiToolkit\Enums\HttpStatus;
use SineMacula\ApiToolkit\Sse\EventStream;
use Symfony\Component\HttpFoundation\StreamedResponse;
```

Note: `StreamedResponse` is still needed for the return type declaration.

---

## Test Expectations

This task creates no new test files. All verification is performed by running the existing `ControllerTest` test suite.

| Test                                                                   | Type     | Description                                                                  |
|------------------------------------------------------------------------|----------|------------------------------------------------------------------------------|
| `testRespondWithEventStreamReturnsStreamedResponse` (existing)         | Existing | Must pass -- response type unchanged                                         |
| `testRespondWithEventStreamSetsSseHeaders` (existing)                  | Existing | Must pass -- headers unchanged                                               |
| `testRespondWithEventStreamAcceptsCustomHeaders` (existing)            | Existing | Must pass -- custom header passthrough unchanged                             |
| `testRespondWithEventStreamExecutesStreamBody` (existing)              | Existing | Must pass -- callback execution and heartbeat unchanged                      |
| `testRespondWithEventStreamEmitsErrorEventAndBreaksWhenCallbackThrows` (existing) | Existing | Must pass -- error event emission unchanged                   |
| `testRespondWithEventStreamBreaksOnFirstCheckOfSecondIteration` (existing) | Existing | Must pass -- abort detection unchanged                                   |
| `testHeartbeatIntervalConstantEqualsTwenty` (existing)                 | Existing | Must pass -- constant retained                                               |
| `testHeartbeatIntervalConstantCanBeOverriddenBySubclass` (existing)    | Existing | Must pass -- late-static-binding preserved through `static::HEARTBEAT_INTERVAL` |
| All non-SSE controller tests (existing)                                | Existing | Must pass -- `respondWithData`, `respondWithItem`, `respondWithCollection` untouched |

---

## Acceptance Criteria

| ID      | Criterion                                                                                                                              | Traces to |
|---------|----------------------------------------------------------------------------------------------------------------------------------------|-----------|
| AC-03-1 | The `respondWithEventStream()` method body is reduced to a single delegation statement (well under the 10-line budget)                 | REQ-04    |
| AC-03-2 | The private `runEventStream()` method is removed from the controller                                                                   | REQ-04    |
| AC-03-3 | The private `runStreamCallback()` method is removed from the controller                                                                | REQ-04    |
| AC-03-4 | The `HEARTBEAT_INTERVAL` constant is retained on the controller                                                                        | REQ-03    |
| AC-03-5 | The `respondWithEventStream()` method signature is unchanged                                                                           | REQ-03    |
| AC-03-6 | Subclass overrides of `HEARTBEAT_INTERVAL` via late static binding are preserved (`static::HEARTBEAT_INTERVAL` passed to `EventStream`) | REQ-03    |
| AC-03-7 | All existing `ControllerTest` SSE tests pass without modification to test assertions                                                   | NFR-02    |
| AC-03-8 | All existing `ControllerTest` non-SSE tests pass without modification                                                                  | NFR-02    |
| AC-03-9 | The modified code passes PHPStan level 8 (`composer check`)                                                                            | NFR-01    |
| AC-03-10 | `composer test` passes with all tests green                                                                                           | NFR-02    |

---

## Language Pack Rules

### Naming

- Names descriptive and unambiguous {#php-nam-001}
- Intent over mechanics {#php-nam-004}

### Structure

- Single-line method signatures by default {#php-str-001}
- Simple control blocks: no blank line padding {#php-str-007}

### Documentation

- Update method docblock to reflect delegation {#php-doc-010}
- Fully qualified types in docblocks {#php-doc-005}
- Broaden callable docblock type to include `Emitter`-accepting signature

---

## References

- Traces to: [Architecture](../.sinemacula/build/workflows/sse-stream-extraction/architecture.md)
- Spec Extract: [Spec](../.sinemacula/build/workflows/sse-stream-extraction/spec.md)
