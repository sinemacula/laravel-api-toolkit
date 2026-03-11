# Architecture: SSE Stream Extraction

Extract SSE transport logic from the base controller into a standalone `EventStream` class with a structured `Emitter` for wire-format abstraction, then reduce the controller's `respondWithEventStream()` to a thin delegation wrapper. The new component lives under `src/Sse/` and is usable from any PHP context without a controller dependency.

---

## Governance

| Field     | Value                                                                    |
|-----------|--------------------------------------------------------------------------|
| Created   | 2026-03-10                                                               |
| Status    | approved                                                                 |
| Owned by  | Architect                                                                |
| Traces to | [Spec Extract](.sinemacula/build/workflows/sse-stream-extraction/spec.md) |

---

## Overview

The current SSE implementation is entirely embedded in the base `Controller` class: a protected constant (`HEARTBEAT_INTERVAL`), a protected method (`respondWithEventStream`), and two private methods (`runEventStream`, `runStreamCallback`). This architecture makes SSE streaming impossible without extending the controller and prevents direct unit testing without namespace-scoped function overrides targeting the controller's namespace.

The extraction creates a new `Sse` namespace (`SineMacula\ApiToolkit\Sse`) containing two classes. `Emitter` provides a structured API for emitting SSE events (data with an optional named event type) without requiring callers to construct wire-format strings. `EventStream` owns the complete SSE transport lifecycle: response construction with SSE headers, the polling loop, heartbeat emission, output buffer flushing, connection-abort detection, and error handling. It accepts a heartbeat interval via its constructor, replacing the constant-based override mechanism.

The controller retains `respondWithEventStream()` with its unchanged signature. Internally it instantiates `EventStream` and delegates. The method body shrinks to thin delegation (well under the 10-line budget). The `HEARTBEAT_INTERVAL` constant remains on the controller for backward compatibility and is forwarded to the `EventStream` constructor. Extension points that were previously frozen private methods (error handling, connection lifecycle) become protected methods on `EventStream`, overridable by subclassing.

---

## Components

| Component    | Responsibility                                                                                       | New / Modified | Location                                         |
|--------------|------------------------------------------------------------------------------------------------------|----------------|--------------------------------------------------|
| Emitter      | Structured SSE event emission: formats data and optional event type into wire-format, flushes output | New            | `src/Sse/Emitter.php`                            |
| EventStream  | SSE transport lifecycle: response construction, polling loop, heartbeat, error handling, extension points | New            | `src/Sse/EventStream.php`                        |
| Controller   | Thin delegation of `respondWithEventStream()` to `EventStream`; retains `HEARTBEAT_INTERVAL` for backward compatibility | Modified       | `src/Http/Routing/Controller.php`                |
| Overrides    | Namespace-scoped function overrides retarget to `Sse` namespace for `EventStream` built-in calls     | Modified       | `tests/Fixtures/Overrides/functions.php`         |

---

## Interfaces

### Emitter (public API)

The `Emitter` is a concrete class, not hidden behind an interface. It provides two public methods. It is instantiated internally by `EventStream` and passed to the user's callback (REQ-09).

```
Emitter::emit(string|array $data, ?string $event = null): void
```

Formats the given data as SSE wire-format and writes it to the output stream. When `$data` is a string, each line becomes a separate `data:` line per the SSE specification. When `$data` is an array, it is JSON-encoded first, then emitted as a single `data:` line. When `$event` is provided, an `event:` line precedes the data lines. The block is terminated with a blank line (`\n\n`). Calls `flush()` after writing.

```
Emitter::comment(string $text = ''): void
```

Writes an SSE comment line (`:` prefix). Used internally for heartbeat keep-alive comments but available publicly for callers who need to send keep-alive signals from within their callbacks.

### EventStream (public API)

```
EventStream::__construct(int $heartbeat_interval = 20)
```

Accepts a configurable heartbeat interval in seconds. The default of 20 matches the current `HEARTBEAT_INTERVAL` constant for backward compatibility.

```
EventStream::toResponse(callable $callback, int $interval = 1, HttpStatus $status = HttpStatus::OK, array $headers = []): StreamedResponse
```

Constructs and returns a `StreamedResponse` with SSE headers merged onto the provided headers. The `$callback` parameter accepts either `callable(): void` (existing pattern) or `callable(Emitter): void` (new pattern, REQ-09). The method detects the callback's arity and passes the `Emitter` instance only when the callback accepts a parameter. The `$interval` parameter controls the sleep duration between polling iterations (same as the existing `$interval` parameter on `respondWithEventStream`).

### EventStream (extension points -- protected methods)

```
EventStream::handleStreamError(Throwable $exception, Emitter $emitter): bool
```

Called when the user callback throws. Default implementation reports the exception via `report()`, emits an `event: error` comment, and returns `false` to break the loop. Subclasses may override to customise error handling behaviour (REQ-06).

```
EventStream::onStreamStart(Emitter $emitter): void
```

Called once before the polling loop begins. Default implementation emits the initial keep-alive comment. Subclasses may override to perform setup or emit an initial event (REQ-06).

```
EventStream::onStreamEnd(): void
```

Called after the polling loop exits (whether from abort, error, or natural termination). Default implementation is empty. Subclasses may override for cleanup (REQ-06).

### Controller -> EventStream

The controller's `respondWithEventStream()` instantiates `EventStream` with `static::HEARTBEAT_INTERVAL` and calls `toResponse()`, passing through all parameters unchanged.

---

## File Change Manifest

| Action   | Path                                      | Component   | Description                                                                                      |
|----------|-------------------------------------------|-------------|--------------------------------------------------------------------------------------------------|
| Create   | `src/Sse/Emitter.php`                     | Emitter     | Structured SSE event emitter with `emit()` and `comment()` methods                              |
| Create   | `src/Sse/EventStream.php`                 | EventStream | SSE transport lifecycle: response construction, polling loop, heartbeat, extension points        |
| Modify   | `src/Http/Routing/Controller.php`         | Controller  | Replace SSE method bodies with delegation to `EventStream`; remove private methods; retain constant |
| Modify   | `tests/Fixtures/Overrides/functions.php`  | Overrides   | Add namespace-scoped overrides for `SineMacula\ApiToolkit\Sse` namespace                        |
| Create   | `tests/Unit/Sse/EmitterTest.php`          | Emitter     | Tests for structured event emission, wire-format correctness, multiline data splitting           |
| Create   | `tests/Unit/Sse/EventStreamTest.php`      | EventStream | Tests for standalone SSE streaming, heartbeat configuration, error handling, extension points    |

---

## Dependencies

| Dependency                            | Type     | Version | Purpose                                                       |
|---------------------------------------|----------|---------|---------------------------------------------------------------|
| `symfony/http-foundation`             | Package  | *       | `StreamedResponse` for SSE response construction              |
| `illuminate/support`                  | Package  | ^12.9   | `Response` facade (used by controller delegation), `now()` helper |
| `sinemacula/laravel-api-toolkit`      | Internal | self    | `HttpStatus` enum for status code parameter                   |
| PHP built-in functions                | Platform | ^8.3    | `connection_aborted()`, `flush()`, `ob_flush()`, `ob_get_level()`, `sleep()` |

No new external dependencies are introduced. All dependencies are already present in the project's `composer.json`.

---

## Integration Points

### Controller backward compatibility

The `respondWithEventStream()` method on `Controller` retains its exact signature: `callable $callback, int $interval = 1, HttpStatus $status = HttpStatus::OK, array $headers = []`. It returns the same `StreamedResponse` type with the same SSE headers. The `HEARTBEAT_INTERVAL` constant remains as a `protected const int` on the controller so that existing subclasses overriding it via late static binding continue to work. The controller passes `static::HEARTBEAT_INTERVAL` to the `EventStream` constructor.

### Callback arity detection (REQ-09)

`EventStream::toResponse()` uses `ReflectionFunction` (or `ReflectionMethod` for object callables) to check whether the callback accepts at least one parameter. When it does, the `Emitter` instance is passed as the first argument. When it does not, the callback is invoked with no arguments. This preserves backward compatibility with existing `callable(): void` callbacks while enabling new `callable(Emitter): void` callbacks.

### Namespace-scoped function overrides

The current `tests/Fixtures/Overrides/functions.php` defines overrides in two namespaces: `SineMacula\ApiToolkit\Http\Concerns` (for `RespondsWithStream`) and `SineMacula\ApiToolkit\Http\Routing` (for `Controller`). After extraction, the built-in function calls (`connection_aborted`, `sleep`, `flush`, `ob_flush`, `ob_get_level`) move from the `Http\Routing` namespace to the `Sse` namespace. The override file gains a new `SineMacula\ApiToolkit\Sse` namespace block with the same function stubs. The `Http\Routing` namespace block can be reduced to only the functions that the controller itself still calls (if any), or removed entirely if all built-in calls have migrated to `EventStream`.

### Existing test preservation (NFR-02)

The existing `ControllerTest` SSE tests exercise `respondWithEventStream()` through the controller. Because the controller delegates to `EventStream`, the same function overrides must intercept the built-in calls. The override file is extended to cover the new `Sse` namespace. The existing test assertions remain unchanged -- they verify the same observable behaviour (response type, headers, stream body execution, error event emission, heartbeat). No test assertion changes are required.

---

## Risks

| Risk                                              | Probability | Impact | Mitigation                                                                                                                                             |
|---------------------------------------------------|-------------|--------|--------------------------------------------------------------------------------------------------------------------------------------------------------|
| Namespace-scoped overrides miss a built-in call   | Medium      | High   | Audit every built-in function call in `EventStream` and `Emitter`; add override stubs for each in the `Sse` namespace block; verify with existing tests |
| Callback arity detection fails for edge cases     | Low         | Medium | Use `ReflectionFunction::getNumberOfParameters()` which handles closures, first-class callables, and invokable objects; document unsupported callable forms |
| Subclass overrides of `HEARTBEAT_INTERVAL` break  | Low         | High   | Controller passes `static::HEARTBEAT_INTERVAL` (late-static-bound) to `EventStream` constructor; existing override mechanism preserved                 |
| `Emitter::emit()` multiline data splitting differs from current behaviour | Low | Medium | Current code does not split multiline data (callbacks echo raw strings). Emitter adds correct splitting per SSE spec. Since this is an additive capability used only through the new emitter API, it cannot affect existing callbacks that echo directly. |

---

## Requirement Traceability

| Requirement | Component(s)            | How Addressed                                                                                        |
|-------------|-------------------------|------------------------------------------------------------------------------------------------------|
| REQ-01      | EventStream             | Standalone class, instantiable from any PHP context without controller dependency                    |
| REQ-02      | Emitter                 | `emit()` method formats data and event type into SSE wire-format; no manual string construction      |
| REQ-03      | Controller, EventStream | Controller delegates to EventStream; same signature, same response type, same headers, same behaviour |
| REQ-04      | Controller              | Controller body reduced to delegation; private methods and transport logic removed                   |
| REQ-05      | EventStream             | Constructor accepts `$heartbeat_interval`; default 20 matches current constant                       |
| REQ-06      | EventStream             | Protected methods `handleStreamError()`, `onStreamStart()`, `onStreamEnd()` are overridable         |
| REQ-07      | EventStream, Emitter    | Docblocks on classes and methods note SAPI-specific considerations for output buffering              |
| REQ-08      | EventStream, Overrides  | Function overrides target `Sse` namespace, not controller namespace; controller tests unaffected     |
| REQ-09      | EventStream             | Callback arity detection passes `Emitter` as parameter when callback accepts it                     |
| NFR-01      | All                     | All new code written to PHPStan level 8; `composer check` must pass                                 |
| NFR-02      | Controller, Overrides   | Existing controller tests pass without assertion changes; overrides extended to cover new namespace  |

---

## References

- Traces to: [Spec Extract](.sinemacula/build/workflows/sse-stream-extraction/spec.md)
