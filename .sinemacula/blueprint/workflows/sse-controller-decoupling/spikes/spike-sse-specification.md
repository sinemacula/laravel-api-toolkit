# Spike: SSE Specification Coverage

The current `respondWithEventStream()` implementation supports only a subset of the SSE specification defined in the WHATWG HTML Living Standard; it lacks structured event emission (event types, IDs, retry, multiline data) and delegates all wire-format concerns to an opaque callback.

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

What parts of the SSE specification (event IDs, retry, multiline data, named events, comments, connection lifecycle) does the current `respondWithEventStream()` implementation support, and what gaps exist relative to the specification?

---

## Methodology

1. **Source code analysis** of `src/Http/Routing/Controller.php` (lines 20-158), specifically the `respondWithEventStream()` method (line 75), `runEventStream()` (line 100), and `runStreamCallback()` (line 147), plus the test file at `tests/Unit/Http/Routing/ControllerTest.php`.
2. **Specification review** using the WHATWG HTML Living Standard section 9.2 ("Server-sent events") at `https://html.spec.whatwg.org/multipage/server-sent-events.html`, supplemented by the MDN documentation on SSE at `https://developer.mozilla.org/en-US/docs/Web/API/Server-sent_events/Using_server-sent_events`.
3. **Gap analysis** comparing each specification-defined feature against the implementation to determine coverage, partial support, or absence.

---

## Findings

### 1. The Implementation Supports SSE Comments for Keep-Alive

**Observation:** The implementation correctly uses SSE comment lines (lines beginning with `:`) for keep-alive purposes. An initial comment `":\n\n"` is emitted at stream open (line 102-103), and a periodic heartbeat comment `":\n\n"` is sent every 20 seconds (line 124-126, configurable via the `HEARTBEAT_INTERVAL` constant on line 25). The specification states that "a colon as the first character of a line is in essence a comment, and is ignored" and that comment lines "can be used to prevent connections from timing out."

**Evidence:** `src/Http/Routing/Controller.php`, lines 102-103 (initial comment) and lines 123-127 (heartbeat comment). The WHATWG specification section on "parsing an event stream" defines lines starting with U+003A COLON as comments that are ignored by the client parser.

**Confidence:** High -- the implementation directly matches the specification's keep-alive pattern; the comment format is byte-for-byte correct (`:\n\n`).

---

### 2. Content-Type and Transport Headers Are Correctly Set

**Observation:** The implementation sets four response headers (lines 77-82): `Content-Type: text/event-stream`, `Cache-Control: no-cache, no-transform`, `Connection: keep-alive`, and `X-Accel-Buffering: no`. The specification requires `text/event-stream` as the MIME type. The `Cache-Control: no-cache` header prevents intermediary caching. The `X-Accel-Buffering: no` header is a practical addition for nginx reverse proxies that is not part of the specification but is a widely-adopted operational necessity for SSE.

**Evidence:** `src/Http/Routing/Controller.php`, lines 77-82. The WHATWG specification states that the server must respond with `Content-Type: text/event-stream`. MDN documentation confirms `Cache-Control: no-cache` as a required server-side header.

**Confidence:** High -- the headers match specification requirements and include appropriate operational headers.

---

### 3. Named Events Are Not Supported by the Emitter API

**Observation:** The SSE specification defines an `event:` field that allows the server to dispatch events to specific named event listeners on the client (e.g., `event: update\ndata: {...}\n\n`). Events without an `event:` field are dispatched as generic `message` events. The current implementation provides no API for the callback to emit named events. The only place an `event:` field appears is in the error handler (line 154: `echo "event: error\n\n";`), which is hardcoded and not exposed to consumers. The callback signature is `callable(): void` (line 69), meaning consumers must manually `echo` raw SSE wire format if they want named events.

**Evidence:** `src/Http/Routing/Controller.php`, line 69 (callback type `callable(): void`), line 154 (hardcoded `event: error`). The WHATWG specification section "processing the field" states: "If the field name is 'event', set the event type buffer to field value." The MDN documentation states: "Each message received has an event field identifying the type of event described."

**Confidence:** High -- the callback receives no emitter interface and the type hint `callable(): void` confirms no event-emission API exists.

---

### 4. Event IDs and Last-Event-ID Reconnection Are Not Supported

**Observation:** The SSE specification defines an `id:` field that sets the `lastEventId` property on the EventSource object. When the connection drops and the client reconnects, it sends a `Last-Event-ID` HTTP header containing the last received event ID, enabling the server to resume from where it left off. The current implementation has no mechanism to set event IDs, no mechanism to read the `Last-Event-ID` header from the reconnecting request, and no concept of event sequencing. The callback cannot emit `id:` fields because it has no emitter API.

**Evidence:** `src/Http/Routing/Controller.php`, lines 67-87 and 100-136 -- no `id:` field is written anywhere in the stream output. The WHATWG specification section "processing the field" states: "If the field name is 'id', if the field value does not contain U+0000 NULL, then set the last event ID buffer to the field value."

**Confidence:** High -- there is no code path that writes `id:` to the output stream, and no code reads `Last-Event-ID` from incoming request headers.

---

### 5. Retry Interval Is Not Supported

**Observation:** The SSE specification defines a `retry:` field that tells the client how long (in milliseconds) to wait before attempting reconnection after a connection loss. This is a server-controlled mechanism for managing reconnection backoff. The current implementation does not emit `retry:` fields at any point in the stream. Clients connecting to this implementation will use their browser's default reconnection timing (typically 3 seconds in most browser implementations).

**Evidence:** `src/Http/Routing/Controller.php`, lines 100-136 -- no `retry:` field is written to the stream. The WHATWG specification states: "If the field name is 'retry', if the field value consists of only ASCII digits, then interpret the field value as an integer in base ten, and set the event stream's reconnection time to that integer."

**Confidence:** High -- no `retry:` field exists in any code path.

---

### 6. Multiline Data Fields Are Not Handled by the Framework

**Observation:** The SSE specification requires that multiline data be sent as multiple consecutive `data:` lines, which the client concatenates with newline characters between them. For example, `data: line1\ndata: line2\n\n` produces `"line1\nline2"` in the client's `event.data`. The current implementation provides no utility for formatting multiline data. If a consumer's callback manually echoes `data:` containing a literal newline character, the SSE parser would interpret this as an incomplete field followed by an unrecognized field, corrupting the stream. The implementation provides no guard against this.

**Evidence:** `src/Http/Routing/Controller.php`, lines 100-136 -- no `data:` field formatting exists in the framework; the callback is responsible for all wire-format output. The WHATWG specification section "parsing an event stream" states lines are split on CR, LF, or CRLF, meaning embedded newlines in a `data:` value would break parsing.

**Confidence:** High -- the implementation contains no data-formatting logic; the specification's multiline handling requirement is well-documented in both WHATWG and MDN sources.

---

### 7. The Callback Receives No Emitter Abstraction

**Observation:** The callback passed to `respondWithEventStream()` is typed as `callable(): void` (line 69). It receives no arguments, no emitter object, and no context about the stream. To emit events, the callback must directly `echo` raw SSE wire-format strings. This means: (a) consumers must know SSE wire format, (b) there is no validation of output format, (c) there is no abstraction over the `event:`, `data:`, `id:`, or `retry:` fields, and (d) the callback cannot query stream state (e.g., whether the connection is still open).

**Evidence:** `src/Http/Routing/Controller.php`, line 69 (`callable(): void`), line 84 (`$callback()` invoked with no arguments in `runEventStream`). Compare with the error handler on line 154 which directly echoes `event: error\n\n` -- this is the same mechanism available to consumers but without any structure.

**Confidence:** High -- the callable type signature and invocation are explicit in the source code.

---

### 8. The Error Event Lacks a Data Field

**Observation:** When the callback throws a `Throwable`, the implementation emits `event: error\n\n` (line 154). Per the SSE specification, an event with an `event:` type but no `data:` field should not fire a corresponding event on the client. The specification states: "If the data buffer is an empty string, set the data buffer and event type buffer to the empty string and return." This means the error event as currently formatted is silently discarded by spec-compliant EventSource clients and never reaches client-side `error` event listeners.

**Evidence:** `src/Http/Routing/Controller.php`, line 154 (`echo "event: error\n\n";`). The WHATWG specification section "dispatching events" states that if the data buffer is empty, the event is not dispatched. A conforming emission would require at least `event: error\ndata: \n\n` (with an empty data field) or `event: error\ndata: {message}\n\n`.

**Confidence:** Medium -- this interpretation is based on reading the WHATWG specification's dispatching algorithm; browser implementations may vary in edge-case handling of events with no data field, but the specification text is unambiguous that empty data buffers cause the event to be dropped.

---

### 9. Connection Lifecycle Is Partially Implemented

**Observation:** The implementation handles connection open (initial comment on line 102), connection abort detection via `connection_aborted()` (lines 109, 130), and error-driven close (lines 152-157). However, several lifecycle aspects are absent: (a) there is no mechanism for the server to signal a graceful close to the client (no way to tell the client "do not reconnect"), (b) there is no hook for cleanup logic when the connection closes, and (c) the double `connection_aborted()` check per loop iteration (lines 109 and 130) is a defensive measure against the race between the first check and the callback execution, but the phpstan suppression comment on line 129 indicates this is recognized as an unusual pattern.

**Evidence:** `src/Http/Routing/Controller.php`, lines 107-135 (main loop), lines 109 and 130 (double abort check), line 129 (phpstan suppression). The WHATWG specification defines three readyState values for EventSource (CONNECTING=0, OPEN=1, CLOSED=2) and specifies that the server can prevent reconnection by responding with a non-200 status or non-`text/event-stream` content type on reconnection, but there is no in-stream mechanism to signal "stop reconnecting."

**Confidence:** Medium -- the connection lifecycle behavior is partly a server-side concern (outside the spec's scope, which focuses on the client-side EventSource API), but the absence of cleanup hooks and graceful shutdown signaling is observable in the code.

---

### 10. Output Buffering Management Is Present but Fragile

**Observation:** The implementation checks for active output buffering with `ob_get_level() > 0` before calling `ob_flush()` (lines 117-119), then always calls `flush()` (line 121). This is necessary because PHP's output buffering can interfere with SSE delivery. The conditional `ob_flush()` is defensive but does not clear nested output buffers (it only flushes the innermost one). In environments with multiple output buffer layers (common in testing and some framework middleware), this could delay event delivery.

**Evidence:** `src/Http/Routing/Controller.php`, lines 117-121. Tests in `tests/Unit/Http/Routing/ControllerTest.php` override `flush` and `ob_flush` via `FunctionOverrides` (lines 239-241), confirming these are recognized as environment-dependent behaviors.

**Confidence:** Medium -- the output buffering handling works in standard PHP-FPM environments but its behavior under nested buffers is inferred from PHP's `ob_flush()` documentation rather than tested.

---

## Implications

- The current implementation provides only the transport scaffolding for SSE (correct headers, keep-alive comments, connection abort detection, polling loop) but delegates all event formatting to the consumer callback via raw `echo` statements. This means consumers must understand and correctly implement SSE wire format themselves.
- The absence of an emitter abstraction means there is no validation layer preventing malformed SSE output. A consumer that echoes a `data:` value containing literal newlines will produce a corrupted stream with no error.
- The lack of `id:` field support means clients cannot resume streams after disconnection. For any use case requiring reliable delivery (progress tracking, ordered notifications), consumers would need to implement their own sequencing outside the SSE protocol.
- The `event: error\n\n` emission (without a `data:` field) likely produces a silent no-op on spec-compliant clients, meaning server-side errors may go unreported to the client despite the implementation's intent to signal them.
- The `retry:` field gap means the server cannot influence client reconnection behavior, which could lead to reconnection storms under load if many clients disconnect simultaneously and reconnect with the browser default interval.

---

## Open Threads

- **Browser conformance on empty-data events:** The finding that `event: error\n\n` (no data field) is dropped by the spec needs verification against actual browser behavior. Chrome, Firefox, and Safari may differ in their handling of this edge case. Empirical testing would move the confidence level from medium to high.
- **Existing consumer usage patterns:** How do current consumers of `respondWithEventStream()` emit events within their callbacks? If consumers are already echoing raw SSE format, understanding their patterns would inform what an emitter API needs to support.
- **PHP SAPI variations:** The output buffering and `connection_aborted()` behavior may differ across PHP-FPM, Swoole/OpenSwoole (Octane), and RoadRunner. This could affect which lifecycle features are feasible to implement.
- **Graceful shutdown signaling:** The SSE specification does not define an in-band "close" message. Research into common patterns for server-initiated graceful close (e.g., a custom `event: close` convention, or HTTP 204 on reconnection) would inform whether the implementation should support this.

---

## References

- Traces to: [Intake Brief](../intake-brief.md)
- Sources:
  - `src/Http/Routing/Controller.php` (lines 20-158) -- primary implementation under analysis
  - `tests/Unit/Http/Routing/ControllerTest.php` (lines 164-372) -- test coverage for SSE methods
  - `tests/Fixtures/Controllers/TestingController.php` -- test fixture
  - `tests/Fixtures/Support/FunctionOverrides.php` -- function override mechanism for testing
  - [WHATWG HTML Living Standard, Section 9.2: Server-sent events](https://html.spec.whatwg.org/multipage/server-sent-events.html)
  - [MDN: Using server-sent events](https://developer.mozilla.org/en-US/docs/Web/API/Server-sent_events/Using_server-sent_events)
  - [Wikipedia: Server-sent events](https://en.wikipedia.org/wiki/Server-sent_events)
  - [javascript.info: Server Sent Events](https://javascript.info/server-sent-events)
