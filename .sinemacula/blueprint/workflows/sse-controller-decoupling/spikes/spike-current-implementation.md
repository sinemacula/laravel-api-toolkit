# Spike: Current SSE Implementation Analysis

An analysis of the exact responsibilities, control flow, and coupling points of the SSE event-stream implementation embedded in the base API controller.

---

## Governance

| Field     | Value |
|-----------|-------|
| Created   | 2026-03-10 |
| Status    | draft |
| Owned by  | Researcher |
| Traces to | [Intake Brief](../intake-brief.md) |

---

## Research Question

What are the exact responsibilities and control flow of `respondWithEventStream()` in the base controller, and what coupling exists between transport-level SSE logic and controller concerns?

---

## Methodology

Static analysis of the codebase was conducted by:

1. Reading `src/Http/Routing/Controller.php` in full (159 lines) to map all methods and their responsibilities.
2. Searching the entire codebase for references to `respondWithEventStream`, `runEventStream`, `runStreamCallback`, `SSE`, `heartbeat`, `StreamedResponse`, `text/event-stream`, `X-Accel-Buffering`, `expectsStream`, and `HEARTBEAT_INTERVAL`.
3. Reading all test fixtures and tests covering the SSE functionality (`tests/Unit/Http/Routing/ControllerTest.php`, `tests/Fixtures/Overrides/functions.php`, `tests/Fixtures/Support/FunctionOverrides.php`).
4. Reading the related but distinct `RespondsWithStream` trait (`src/Http/Concerns/RespondsWithStream.php`) to compare streaming patterns.
5. Searching configuration files for any SSE/streaming settings.
6. Checking the service provider (`src/ApiServiceProvider.php`) for SSE-related macros.

No external web research was needed; this spike is based entirely on source code evidence.

---

## Findings

### Finding 1: Three Distinct Methods Comprise the SSE Subsystem

**Observation:** The SSE functionality is implemented as three methods on the base `Controller` class:

1. `respondWithEventStream()` (protected, lines 75-87) -- the public API for subclass controllers. Merges SSE headers, wraps `runEventStream` in a `Response::stream()` closure, returns a `StreamedResponse`.
2. `runEventStream()` (private, lines 100-136) -- the event loop. Emits an initial keep-alive comment, enters an infinite `while(true)` loop that checks `connection_aborted()`, invokes the callback, manages output buffering (`ob_flush` / `flush`), emits heartbeat comments on a timer, re-checks connection state, then sleeps.
3. `runStreamCallback()` (private, lines 147-158) -- exception boundary. Invokes the user callback in a try/catch, calls `report($e)` on failure, emits a bare `event: error\n\n` SSE event, and returns false to signal the loop to break.

**Evidence:** `src/Http/Routing/Controller.php`, lines 75-158. The class is 159 lines total; the SSE subsystem occupies lines 24-158 (the `HEARTBEAT_INTERVAL` constant plus all three methods), which is 85 lines or approximately 54% of the controller's body.

**Confidence:** High -- direct source code analysis of the complete file.

### Finding 2: The SSE Methods Are Only Consumed Internally

**Observation:** `respondWithEventStream()` is referenced in exactly two files: its definition in `Controller.php` and its tests in `ControllerTest.php`. No concrete controller subclass in the codebase currently calls it. The two private methods (`runEventStream`, `runStreamCallback`) are never referenced outside `Controller.php`.

**Evidence:** `rg -n "respondWithEventStream" --type php -l` returned only `src/Http/Routing/Controller.php` and `tests/Unit/Http/Routing/ControllerTest.php`. No fixture controllers or integration tests exercise the method.

**Confidence:** High -- exhaustive codebase search. However, downstream consuming applications (outside this package) may use it; that cannot be verified from this repository alone.

### Finding 3: Seven Distinct Responsibilities Are Embedded in the Controller

**Observation:** The three SSE methods embed the following discrete responsibilities within the abstract base controller:

1. **SSE header composition** (lines 77-82): Sets `Content-Type: text/event-stream`, `Cache-Control: no-cache, no-transform`, `Connection: keep-alive`, and `X-Accel-Buffering: no`. Custom headers are merged underneath (SSE headers always win via `array_merge` ordering).
2. **Response construction** (lines 84-86): Delegates to Laravel's `Response::stream()` facade to build a `StreamedResponse`.
3. **Connection lifecycle management** (lines 107, 109-111, 129-131): The infinite loop with dual `connection_aborted()` checks per iteration -- one at the top of the loop (line 109) and one after the heartbeat/flush cycle (line 130).
4. **Heartbeat emission** (lines 105, 123-127): Tracks elapsed time using Carbon's `now()` and `diffInSeconds()`. Emits an SSE comment (`: \n\n`) when `HEARTBEAT_INTERVAL` (20 seconds, overridable via `static::`) elapses.
5. **Output buffer management** (lines 102-103, 117-119, 122, 125): Initial flush, conditional `ob_flush()` when `ob_get_level() > 0`, and explicit `flush()` calls to push data to the client.
6. **Polling interval control** (line 134): `sleep($interval)` between iterations. The interval defaults to 1 second and is passed as a parameter.
7. **Error handling and reporting** (lines 149-157): Catches any `Throwable`, reports it via Laravel's `report()` helper, emits a minimal `event: error\n\n` SSE event (no data payload), and terminates the stream.

**Evidence:** `src/Http/Routing/Controller.php`, lines 75-158, with specific line references above.

**Confidence:** High -- each responsibility is directly visible in the source code.

### Finding 4: The Heartbeat Interval Is the Only Configurable Parameter

**Observation:** The `HEARTBEAT_INTERVAL` constant (20 seconds) is the only SSE behaviour that can be customized by subclasses. It uses `static::HEARTBEAT_INTERVAL` (late static binding), so subclasses can override it via a `protected const int HEARTBEAT_INTERVAL = N;` declaration. There is no configuration file, service provider config key, or dependency injection point for any other SSE behaviour (header set, error format, polling interval strategy, etc.).

**Evidence:** `src/Http/Routing/Controller.php`, line 25 (constant definition) and line 123 (`static::HEARTBEAT_INTERVAL` usage). `tests/Unit/Http/Routing/ControllerTest.php`, lines 280-297 (test confirming subclass override). Configuration search of `config/api-toolkit.php` yielded no SSE-related keys.

**Confidence:** High -- exhaustive search of configuration and constant usage.

### Finding 5: A Parallel Streaming Pattern Exists in RespondsWithStream

**Observation:** The `RespondsWithStream` trait (`src/Http/Concerns/RespondsWithStream.php`) implements CSV streaming with a structurally similar but independent pattern: it also manages output buffering (`ob_get_level()` check at line 58, `ob_flush()` at line 59, `flush()` at line 62) and constructs a `StreamedResponse` (via `Response::streamDownload`). Both the controller SSE methods and this trait share the same output buffer management pattern but have no shared abstraction. The trait is used for download-style streaming (CSV export), while the controller methods handle push-style streaming (SSE).

**Evidence:** `src/Http/Concerns/RespondsWithStream.php`, lines 58-62 (buffer management) and line 121-126 (`createStreamedResponse` method). Compare with `src/Http/Routing/Controller.php`, lines 117-122 (identical buffer pattern).

**Confidence:** High -- direct code comparison.

### Finding 6: The expectsStream Macro Creates a Separate Content-Negotiation Coupling Point

**Observation:** The `ApiServiceProvider` registers a `Request::macro('expectsStream')` that checks whether the `Accept` header equals `text/event-stream` (line 209 of `ApiServiceProvider.php`). This macro is defined in the service provider but is not referenced by the controller's SSE methods. It represents a content-negotiation concern that is aware of SSE at the transport level but is decoupled from the response construction. The macro is tested only for existence (`hasMacro` assertion in `ApiServiceProviderTest.php`, line 151), not for correctness of its return value.

**Evidence:** `src/ApiServiceProvider.php`, line 209. `tests/Integration/ApiServiceProviderTest.php`, line 151. No other file references `expectsStream`.

**Confidence:** High -- exhaustive codebase search.

### Finding 7: Testing Requires Namespace-Scoped Function Overrides

**Observation:** Testing the SSE event loop requires overriding four PHP built-in functions: `connection_aborted()`, `sleep()`, `flush()`, and `ob_flush()`. This is achieved through namespace-scoped function overrides in `tests/Fixtures/Overrides/functions.php`, which defines replacement functions in both `SineMacula\ApiToolkit\Http\Routing` and `SineMacula\ApiToolkit\Http\Concerns` namespaces. Each override delegates to a static `FunctionOverrides` registry. The test file (`ControllerTest.php`) sets up these overrides before each SSE test and uses `ob_start()`/`ob_end_clean()` or `ob_get_clean()` to capture output. The tests cannot verify timing behaviour directly; they simulate it by advancing Carbon's test clock (`$this->travel(25)->seconds()`) and controlling abort counts via closures.

**Evidence:** `tests/Fixtures/Overrides/functions.php`, lines 1-135 (four function overrides across two namespaces). `tests/Fixtures/Support/FunctionOverrides.php`, lines 1-56 (the static registry). `tests/Unit/Http/Routing/ControllerTest.php`, lines 228-372 (four SSE-specific tests).

**Confidence:** High -- direct analysis of test infrastructure.

### Finding 8: The Error Event Format Is Minimal and Non-Standard

**Observation:** When the callback throws, the controller emits `event: error\n\n` with no `data:` field (line 154 of Controller.php). The SSE specification (W3C) states that an event without a `data` field will not dispatch a `MessageEvent` to the client. The error event contains no information about the failure -- the exception is reported server-side via `report()` but the client receives only the event type with an empty payload. Additionally, there is no `retry:` field to indicate reconnection behaviour.

**Evidence:** `src/Http/Routing/Controller.php`, lines 152-156. The output `"event: error\n\n"` has the event type but no data line.

**Confidence:** Medium -- the SSE specification behaviour is based on the W3C EventSource standard, but the exact client-side handling depends on the EventSource implementation used by consumers. The claim that the event will not dispatch a `MessageEvent` is based on the spec, but some non-standard clients may handle it differently.

---

## Implications

- The SSE subsystem (85 lines, 54% of the controller body) represents a transport-specific concern that every controller subclass inherits, regardless of whether it uses event streaming. Controllers that only serve JSON responses still carry the SSE constant, the three SSE methods, and the `StreamedResponse` import.

- The seven distinct responsibilities (header composition, response construction, connection lifecycle, heartbeat emission, output buffer management, polling interval, error handling) are tightly coupled within three methods on the controller. Changing any single behaviour (e.g., heartbeat interval strategy, error event format, header set) requires modifying the base controller class, which affects all API controllers in the consuming application.

- The two private methods (`runEventStream`, `runStreamCallback`) cannot be overridden or extended by subclass controllers. The only extension point is the `HEARTBEAT_INTERVAL` constant. All other SSE behaviour is frozen.

- The duplicated output-buffer management pattern between `Controller.php` (lines 117-122) and `RespondsWithStream.php` (lines 58-62) suggests that buffer flushing is a cross-cutting concern that has been independently implemented in two locations.

- The `expectsStream` macro in the service provider and the SSE response methods in the controller represent two halves of SSE content negotiation that are not formally connected, creating implicit coupling through the shared knowledge of the `text/event-stream` content type string.

- The namespace-scoped function override infrastructure required for testing the SSE loop is non-trivial (135 lines across two files, plus the 56-line registry class). If the SSE loop were extracted from the controller, the function overrides in the `Http\Routing` namespace might be simplified or eliminated from that namespace, as they would move with the extracted component.

---

## Open Threads

- **Downstream consumer usage**: The `respondWithEventStream()` method is not used within this package. How it is used in consuming applications (what callbacks are passed, how subclasses interact with it, whether heartbeat is ever overridden) cannot be determined from this repository alone. This is critical context for understanding the actual coupling surface.

- **Error event client handling**: The minimal `event: error\n\n` format (no data payload) may or may not trigger client-side event handlers depending on the EventSource implementation. How downstream clients handle this error event warrants investigation.

- **Header merge precedence**: The `array_merge($headers, [...sse_headers...])` call means SSE headers always override user-supplied headers of the same name. Whether this is intentional or a potential issue depends on whether consumers ever need to customise SSE-mandatory headers.

- **Connection detection reliability**: The dual `connection_aborted()` check per iteration (lines 109 and 130) suggests uncertainty about when connection state becomes reliable relative to I/O operations. Whether this pattern is necessary or defensive over-checking warrants investigation.

- **The `RespondsWithStream` trait relationship**: Both the controller SSE methods and the `RespondsWithStream` trait deal with streamed responses but share no common abstraction. Whether they should share buffer-management infrastructure is an open question.

---

## References

- Traces to: [Intake Brief](../intake-brief.md)
- Sources:
  - `src/Http/Routing/Controller.php` (lines 1-159)
  - `src/Http/Concerns/RespondsWithStream.php` (lines 1-127)
  - `src/ApiServiceProvider.php` (lines 207-210)
  - `src/Enums/HttpStatus.php` (lines 1-110)
  - `tests/Unit/Http/Routing/ControllerTest.php` (lines 1-373)
  - `tests/Fixtures/Controllers/TestingController.php` (lines 1-13)
  - `tests/Fixtures/Overrides/functions.php` (lines 1-135)
  - `tests/Fixtures/Support/FunctionOverrides.php` (lines 1-56)
  - `tests/Integration/ApiServiceProviderTest.php` (line 151)
  - `config/api-toolkit.php` (searched, no SSE configuration found)
