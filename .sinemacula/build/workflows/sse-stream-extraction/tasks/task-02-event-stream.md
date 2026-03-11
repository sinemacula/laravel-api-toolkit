# Task 02: EventStream

Create the `EventStream` class that owns the complete SSE transport lifecycle and verify it through standalone unit tests.

---

## Governance

| Field        | Value                                                                                           |
|--------------|-------------------------------------------------------------------------------------------------|
| Created      | 2026-03-10                                                                                      |
| Status       | draft                                                                                           |
| Owned by     | Developer                                                                                       |
| Traces to    | [Architecture](../.sinemacula/build/workflows/sse-stream-extraction/architecture.md)            |
| Task Number  | 02                                                                                              |
| Tier         | 2                                                                                               |
| Dependencies | task-01                                                                                         |

---

## Objective

Create the `EventStream` class that manages SSE response construction, the polling loop, heartbeat emission, connection-abort detection, error handling, and extension points, then verify it through standalone tests that do not depend on the controller.

---

## Scope

### Files to Create

| Path                                  | Component   | Description                                                                                    |
|---------------------------------------|-------------|------------------------------------------------------------------------------------------------|
| `src/Sse/EventStream.php`            | EventStream | SSE transport lifecycle: response construction, polling loop, heartbeat, extension points       |
| `tests/Unit/Sse/EventStreamTest.php` | EventStream | Tests for standalone SSE streaming, heartbeat, error handling, callback arity, extension points |

### Files to Modify

None. This task depends on Task 01 having created `Emitter` and the `Sse` namespace overrides.

---

## Specification

### EventStream Class

**Namespace:** `SineMacula\ApiToolkit\Sse`

**Class declaration:** `class EventStream` -- not `final`, because extension points (protected methods) are designed to be overridden by subclasses (REQ-06).

**Constructor:**

```
public function __construct(
    int $heartbeat_interval = 20,
)
```

The constructor accepts a configurable heartbeat interval in seconds. The default of 20 matches the current `HEARTBEAT_INTERVAL` constant on the controller for backward compatibility. Store the value as a `private readonly int` property.

Per the language pack, constructors with promoted properties must be multi-line ({#php-str-002}). The `$heartbeat_interval` property should be promoted:

```
public function __construct(

    /** The heartbeat interval in seconds for keep-alive comments. */
    private readonly int $heartbeat_interval = 20,

) {
}
```

**Method: `toResponse(callable $callback, int $interval = 1, HttpStatus $status = HttpStatus::OK, array $headers = []): StreamedResponse`**

This is the primary public API. It constructs and returns a `StreamedResponse` with SSE headers.

1. Merge SSE-required headers onto the provided `$headers` array (SSE headers take precedence):
   - `Content-Type: text/event-stream`
   - `Cache-Control: no-cache, no-transform`
   - `Connection: keep-alive`
   - `X-Accel-Buffering: no`

2. Detect callback arity using `ReflectionFunction` to determine whether the callback accepts a parameter. Use `new \ReflectionFunction(\Closure::fromCallable($callback))` to normalise all callable forms to a `Closure`, then check `getNumberOfParameters() >= 1`. Store the boolean result.

3. Create a new `Emitter` instance.

4. Return `new StreamedResponse(function () use ($callback, $interval, $emitter, $accepts_emitter): void { ... }, $status->getCode(), $headers)`.

5. Inside the streamed response closure, call `$this->runEventStream($callback, $interval, $emitter, $accepts_emitter)`.

**Note on response construction:** The current controller uses `Response::stream(...)` (the Laravel facade). The `EventStream` class should NOT use Laravel facades -- it must be usable from any PHP context without the framework (REQ-01). Instead, instantiate `Symfony\Component\HttpFoundation\StreamedResponse` directly. The constructor signature is `new StreamedResponse(?callable $callback, int $status, array $headers)`.

**Private method: `runEventStream(callable $callback, int $interval, Emitter $emitter, bool $accepts_emitter): void`**

This method contains the polling loop logic extracted from the current controller's `runEventStream` method.

1. Call `$this->onStreamStart($emitter)` (extension point).
2. Enter `while (true)` loop:
   a. Check `connection_aborted()` -- if truthy, `break`.
   b. Try invoking the callback:
      - If `$accepts_emitter` is true, call `$callback($emitter)`.
      - Otherwise, call `$callback()`.
   c. If the callback throws `\Throwable`:
      - Call `$this->handleStreamError($exception, $emitter)`.
      - If `handleStreamError` returns `false`, `break`.
      - If it returns `true`, continue to the next iteration (allowing recovery).
   d. Flush output: if `ob_get_level() > 0`, call `ob_flush()`, then call `flush()`.
   e. Check heartbeat: if `$heartbeat_timestamp->diffInSeconds(now()) >= $this->heartbeat_interval`, call `$emitter->comment()` to emit a keep-alive comment, and reset `$heartbeat_timestamp = now()`.
   f. Check `connection_aborted()` again -- if truthy, `break`. (This duplicates the current controller's second abort check per iteration. The `@phpstan-ignore-next-line if.alwaysFalse` suppression must be carried over since PHPStan sees the second check as always-false within a single analysis pass.)
   g. Call `sleep($interval)`.
3. After the loop exits, call `$this->onStreamEnd()` (extension point).

**Important behavioural detail:** The heartbeat check in the current controller uses `echo ":\n\n"` followed by `flush()`. After extraction, this becomes `$emitter->comment()` which writes `":\n\n"` and calls `flush()` internally. This produces identical wire output. The separate `flush()` call after `echo` in the heartbeat block is subsumed by the `comment()` method.

**Protected method: `handleStreamError(Throwable $exception, Emitter $emitter): bool`**

Default implementation:
1. Call `report($exception)` to report the exception through Laravel's exception handler.
2. Write `"event: error\n\n"` to output via `echo` and call `flush()`. (This matches the current controller behaviour exactly. Note: the architecture says "emits an `event: error` comment" but the current implementation writes `echo "event: error\n\n"` directly, not through the emitter. Maintain exact current behaviour to satisfy NFR-02.)
3. Return `false` to break the loop.

**Why not use `$emitter->emit()` for the error event:** The current controller writes `echo "event: error\n\n"; flush();`. This is NOT valid SSE (it lacks a `data:` field), but it is the current behaviour. Changing it to use the emitter would alter the wire format, which violates REQ-03 and NFR-02. The architecture explicitly lists this as a non-goal (the spec's Non-Goals section mentions "Fixing the error event wire format to include a `data:` field"). Reproduce the current behaviour exactly.

**Protected method: `onStreamStart(Emitter $emitter): void`**

Default implementation: call `$emitter->comment()` to emit the initial keep-alive comment (`":\n\n"`). This matches the current controller's `echo ":\n\n"; flush();` at the top of `runEventStream`.

**Protected method: `onStreamEnd(): void`**

Default implementation: empty body. Subclasses may override for cleanup.

**Imports required:**

- `SineMacula\ApiToolkit\Enums\HttpStatus`
- `Symfony\Component\HttpFoundation\StreamedResponse`

**Docblocks:**

- Class docblock: describe as the SSE transport lifecycle manager. Note SAPI-specific considerations: under PHP-FPM, `connection_aborted()` only updates after output is flushed; under CLI, connection abort is not meaningful; under Octane, the persistent worker process means the loop runs within the worker lifecycle. Include `@author` and `@copyright` tags.
- `toResponse()` docblock: document the callable parameter as `callable(): void|callable(\SineMacula\ApiToolkit\Sse\Emitter): void`. Document that arity detection determines whether the emitter is passed. `@param`, `@return`, all parameters documented.
- `handleStreamError()`: document return value semantics (false = break, true = continue).
- `onStreamStart()`: document as called once before the polling loop begins.
- `onStreamEnd()`: document as called after the polling loop exits.
- `runEventStream()`: private, but still needs `@param` and `@return` tags per the codebase convention.

### Existing Code Patterns to Follow

**Current polling loop structure** (from `Controller::runEventStream`, lines 100-136):

```php
echo ":\n\n";
flush();

$heartbeat_timestamp = now();

while (true) {

    if (connection_aborted()) {
        break;
    }

    if (!$this->runStreamCallback($callback)) {
        break;
    }

    if (ob_get_level() > 0) {
        ob_flush();
    }

    flush();

    if ($heartbeat_timestamp->diffInSeconds(now()) >= static::HEARTBEAT_INTERVAL) {
        echo ":\n\n";
        flush();
        $heartbeat_timestamp = now();
    }

    // @phpstan-ignore-next-line if.alwaysFalse
    if (connection_aborted()) {
        break;
    }

    sleep($interval);
}
```

The `EventStream` version replaces `echo ":\n\n"; flush();` with `$emitter->comment()` (same output), inlines the callback invocation with arity detection, and calls the extension points at the boundaries.

**Error handling** (from `Controller::runStreamCallback`, lines 147-158):

```php
try {
    $callback();
    return true;
} catch (\Throwable $e) {
    report($e);
    echo "event: error\n\n";
    flush();
    return false;
}
```

This is extracted into the `handleStreamError` extension point, with the try/catch inlined in `runEventStream`.

**Test patterns** (from `ControllerTest`):

- Use `FunctionOverrides::set()` to control `connection_aborted`, `sleep`, `flush`, `ob_flush`
- Use `$this->travelTo(now())` to freeze time, then `$this->travel(N)->seconds()` to advance
- Use `ob_start()` / `ob_get_clean()` to capture streamed output
- Get the `StreamedResponse` from `toResponse()`, then call `$response->sendContent()` within the output buffer

**Test approach for EventStream (standalone, no controller):**

Instantiate `EventStream` directly. Call `toResponse(...)` to get a `StreamedResponse`. Execute the response with `$response->sendContent()` within `ob_start()` / `ob_get_clean()`. Use `FunctionOverrides` to control loop iteration counts and suppress real system calls.

---

## Test Expectations

| Test                                                           | Type | Description                                                                                                   |
|----------------------------------------------------------------|------|---------------------------------------------------------------------------------------------------------------|
| `testToResponseReturnsStreamedResponse`                        | Unit | `toResponse(fn() => null)` returns a `StreamedResponse` instance                                              |
| `testToResponseSetsSseHeaders`                                 | Unit | Response has `Content-Type: text/event-stream`, `Cache-Control: no-cache, no-transform`, `Connection: keep-alive`, `X-Accel-Buffering: no` |
| `testToResponseAcceptsCustomHeaders`                           | Unit | Custom headers are present alongside SSE headers; SSE headers override conflicting custom headers             |
| `testToResponseAcceptsCustomStatus`                            | Unit | `toResponse(fn() => null, status: HttpStatus::ACCEPTED)` returns a 202 response                              |
| `testStreamExecutesCallback`                                   | Unit | The callback runs during `sendContent()`; verify via a flag variable                                          |
| `testStreamEmitsInitialKeepAliveComment`                       | Unit | Captured output starts with `":\n\n"`                                                                         |
| `testStreamEmitsHeartbeatAfterInterval`                        | Unit | Advance time past the heartbeat interval inside the callback; captured output contains a second `":\n\n"`     |
| `testStreamBreaksOnConnectionAborted`                          | Unit | `connection_aborted` returns 1 on the first check; callback does not execute                                  |
| `testStreamEmitsErrorEventWhenCallbackThrows`                  | Unit | Callback throws `RuntimeException`; captured output contains `"event: error\n\n"`; callback runs once         |
| `testStreamPassesEmitterWhenCallbackAcceptsParameter`          | Unit | Callback signature `function (Emitter $emitter)` receives the emitter instance                                |
| `testStreamDoesNotPassEmitterWhenCallbackAcceptsNoParameters`  | Unit | Callback signature `function ()` is called with no arguments                                                  |
| `testDefaultHeartbeatIntervalIsTwenty`                         | Unit | Construct with default; advance time by 19 seconds inside callback -- no heartbeat; advance to 20 -- heartbeat emitted |
| `testCustomHeartbeatIntervalIsRespected`                       | Unit | Construct with `heartbeat_interval: 5`; advance 5 seconds -- heartbeat emitted                                |
| `testHandleStreamErrorIsOverridableBySubclass`                 | Unit | Anonymous subclass overrides `handleStreamError` to return true; verify callback runs more than once (loop continues) |
| `testOnStreamStartIsOverridableBySubclass`                     | Unit | Anonymous subclass overrides `onStreamStart` to emit a custom event; verify custom output instead of default keep-alive |
| `testOnStreamEndIsCalledAfterLoopExits`                        | Unit | Anonymous subclass overrides `onStreamEnd` to set a flag; verify flag is set after `sendContent()`            |
| `testStreamBreaksOnSecondAbortCheck`                           | Unit | `connection_aborted` returns 0 on checks 1-2 (loop enters, callback runs), then 1 on check 3; verify loop exits cleanly |

---

## Acceptance Criteria

| ID      | Criterion                                                                                                                                       | Traces to |
|---------|-------------------------------------------------------------------------------------------------------------------------------------------------|-----------|
| AC-02-1 | `EventStream` is instantiable and usable without any controller dependency                                                                      | REQ-01    |
| AC-02-2 | `toResponse()` returns a `StreamedResponse` with correct SSE headers                                                                            | REQ-01    |
| AC-02-3 | The callback is invoked during stream execution                                                                                                 | REQ-03    |
| AC-02-4 | The polling loop emits an initial keep-alive comment, checks for connection abort, invokes the callback, flushes output, and sleeps             | REQ-03    |
| AC-02-5 | Heartbeat comments are emitted when the configured interval elapses                                                                             | REQ-05    |
| AC-02-6 | The constructor accepts a custom heartbeat interval; the default is 20                                                                          | REQ-05    |
| AC-02-7 | Error handling reports the exception and emits `"event: error\n\n"`, breaking the loop                                                         | REQ-03    |
| AC-02-8 | `handleStreamError()` is protected and overridable by subclasses                                                                                | REQ-06    |
| AC-02-9 | `onStreamStart()` is protected and overridable by subclasses                                                                                    | REQ-06    |
| AC-02-10 | `onStreamEnd()` is protected and overridable by subclasses                                                                                     | REQ-06    |
| AC-02-11 | Callback arity detection passes the `Emitter` when the callback accepts a parameter                                                            | REQ-09    |
| AC-02-12 | Callbacks that accept no parameters continue to work without receiving the emitter                                                              | REQ-09    |
| AC-02-13 | Class and method docblocks include SAPI-specific considerations                                                                                 | REQ-07    |
| AC-02-14 | All new code passes PHPStan level 8 (`composer check`)                                                                                         | NFR-01    |
| AC-02-15 | The `EventStream` class does not use Laravel facades (`Response::stream`, etc.) -- it instantiates `StreamedResponse` directly                  | REQ-01    |

---

## Language Pack Rules

### Naming

- Names descriptive and unambiguous {#php-nam-001}
- Directory context replaces prefixes -- `Sse/EventStream` not `Sse/SseEventStream` {#php-nam-007}
- One class per file {#php-nam-030}
- Namespace mirrors directory: `SineMacula\ApiToolkit\Sse` maps to `src/Sse/` {#php-nam-031}
- Test file: `EventStreamTest.php` {#php-nam-011}

### Structure

- Constructor with promoted properties must be multi-line {#php-str-002}
- Other method signatures single-line by default {#php-str-001}
- Multi-line control blocks: blank line after opening brace only {#php-str-008}
- Simple control blocks: no blank line padding {#php-str-007}
- Group related prep statements {#php-str-009}

### Documentation

- Class docblock with `@author` and `@copyright` tags {#php-doc-009, #php-doc-030}
- Constructor promoted property: single-line doc block directly above the property {#php-doc-018}
- Blank line after opening `(`, between promoted properties, and before closing `)` {#php-doc-019, #php-doc-020, #php-doc-021}
- `@param`, `@return`, `@throws` on all methods {#php-doc-010}
- Fully qualified types in docblocks {#php-doc-005}
- Author: `Ben Carey <bdmc@sinemacula.co.uk>`, Copyright: `2026 Sine Macula Limited.`

### Testing

- Extend `Tests\TestCase` base class
- Use `#[CoversClass(EventStream::class)]` attribute {#php-tst-016}
- Test method names: `test{DescriptionInCamelCase}` {#php-tst-010}
- Each test asserts one logical concept {#php-tst-004}
- Use `static::assertSame()` (existing codebase convention)
- Mark test class docblock with `@internal`

---

## References

- Traces to: [Architecture](../.sinemacula/build/workflows/sse-stream-extraction/architecture.md)
- Spec Extract: [Spec](../.sinemacula/build/workflows/sse-stream-extraction/spec.md)
