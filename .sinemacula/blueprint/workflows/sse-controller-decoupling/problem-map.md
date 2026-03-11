# Problem Map: SSE Implementation Decoupled from Controller

The SSE transport logic embedded in the base controller creates coupling, testability, and specification-conformance problems for developers building or consuming real-time streaming features with the Laravel API Toolkit.

---

## Governance

| Field     | Value                                                          |
|-----------|----------------------------------------------------------------|
| Created   | 2026-03-10                                                     |
| Status    | draft                                                          |
| Owned by  | Product Analyst                                                |
| Traces to | [Intake Brief](intake-brief.md), Spikes (2 documents)         |

---

## Input Spikes

| # | Spike                            | Path                                          | Key Finding                                                                                         |
|---|----------------------------------|-----------------------------------------------|-----------------------------------------------------------------------------------------------------|
| 1 | Current SSE Implementation Analysis | spikes/spike-current-implementation.md      | Seven distinct responsibilities are embedded in the base controller, occupying 54% of the class body, with only heartbeat interval configurable |
| 2 | SSE Specification Coverage       | spikes/spike-sse-specification.md             | The implementation provides only transport scaffolding; it lacks an emitter abstraction, named events, event IDs, retry fields, and multiline data handling, delegating all wire-format concerns to opaque callbacks |

---

## Problem Clusters

### Cluster: Inheritance Burden on Non-Streaming Controllers

**Theme:** Developers whose controllers do not use SSE still inherit SSE-specific code, constants, and imports, increasing the cognitive and maintenance surface of the base controller.

**Affected users:** All developers extending the base controller, whether or not they use SSE streaming.

#### Problem 1: SSE Logic Inflates the Base Controller

- **Description:** Every controller that extends the base controller inherits 85 lines of SSE transport logic (three methods plus a constant), which constitutes 54% of the controller's body. Developers reading, maintaining, or debugging the base controller must navigate SSE-specific code even when their controllers serve only JSON responses.
- **Evidence:** Spike 1, Finding 1 (three SSE methods occupy lines 24-158, 85 lines, ~54% of the controller body) and Finding 3 (seven distinct responsibilities embedded in the controller).
- **Severity:** Medium
- **Frequency:** Daily -- every developer working with any controller encounters this code.

#### Problem 2: SSE Logic Cannot Be Used Outside the Controller Hierarchy

- **Description:** Because the SSE methods are defined on the abstract base controller (one protected, two private), developers who need SSE streaming from a non-controller context (such as a job, artisan command, or standalone service class) cannot access this functionality without extending the controller or duplicating the implementation.
- **Evidence:** Spike 1, Finding 2 (SSE methods only referenced in the controller and its tests) and Finding 3 (the methods are embedded in the controller with no separate abstraction). Intake Brief, Problem Signal ("forces any SSE consumer to extend the controller").
- **Severity:** High
- **Frequency:** Occasionally -- arises when developers need SSE in contexts outside the HTTP controller layer.

#### Problem 3: SSE Behaviour Is Frozen with Minimal Extension Points

- **Description:** The two core SSE methods (`runEventStream`, `runStreamCallback`) are private and cannot be overridden by subclasses. The only customisation point is the `HEARTBEAT_INTERVAL` constant via late static binding. Developers who need to modify any other aspect of SSE behaviour (error format, header set, polling strategy, connection lifecycle) cannot do so without forking or patching the base controller.
- **Evidence:** Spike 1, Finding 4 (heartbeat interval is the only configurable parameter; no configuration file, service provider config, or dependency injection point exists for other behaviours).
- **Severity:** Medium
- **Frequency:** Occasionally -- arises when developers need to customise SSE behaviour beyond heartbeat timing.

---

### Cluster: Specification Conformance Gaps

**Theme:** The SSE implementation does not conform to several aspects of the WHATWG SSE specification, which can cause silent failures, malformed streams, or missing protocol features for consumers.

**Affected users:** Developers building SSE-powered features and the end-users of their applications who connect via EventSource clients.

#### Problem 4: Error Events Are Silently Dropped by Spec-Compliant Clients

- **Description:** When a callback throws an exception, the controller emits `event: error\n\n` with no `data:` field. Per the WHATWG specification, an event without a `data:` field is silently discarded by the client's EventSource parser and never reaches client-side event listeners. This means server-side errors go unreported to the client despite the implementation's intent to signal them.
- **Evidence:** Spike 1, Finding 8 (error event format is minimal and non-standard). Spike 2, Finding 8 (the WHATWG specification states that empty data buffers cause the event to not dispatch; a conforming emission requires at least `data: \n` alongside the event type).
- **Severity:** High
- **Frequency:** Occasionally -- occurs whenever a callback throws during streaming, which may be rare in happy-path usage but critical in error scenarios.

#### Problem 5: No Protection Against Malformed SSE Output

- **Description:** The callback is typed as `callable(): void` and must manually `echo` raw SSE wire-format strings. There is no validation layer preventing malformed output. If a consumer echoes a `data:` value containing literal newline characters, the SSE parser interprets this as an incomplete field followed by an unrecognized field, corrupting the stream silently.
- **Evidence:** Spike 2, Finding 6 (multiline data fields are not handled; embedded newlines corrupt the stream) and Finding 7 (the callback receives no emitter abstraction; consumers must know SSE wire format).
- **Severity:** High
- **Frequency:** Occasionally -- occurs when consumers emit data containing newlines, which is common for JSON payloads with pretty-printing or multi-line text.

#### Problem 6: Consumers Must Know SSE Wire Format to Emit Events

- **Description:** The callback receives no arguments, no emitter object, and no context about the stream. To emit events, consumers must directly `echo` raw SSE wire-format strings (e.g., `echo "event: update\ndata: {json}\n\n";`). This requires consumers to understand the SSE wire protocol, including the correct field syntax, newline conventions, and multi-line data splitting rules.
- **Evidence:** Spike 2, Finding 7 (callback typed as `callable(): void` with no emitter API) and Finding 3 (named events are not supported by the emitter API; the only `event:` field usage is the hardcoded error handler).
- **Severity:** Medium
- **Frequency:** Daily -- every developer writing an SSE callback must deal with raw wire format.

#### Problem 7: No Support for Client Reconnection Protocol

- **Description:** The SSE specification defines `id:` and `retry:` fields that enable clients to resume streams after disconnection and control reconnection timing. The implementation has no mechanism to set event IDs, read the `Last-Event-ID` header from reconnecting requests, or emit `retry:` fields. Clients cannot resume from where they left off after a disconnect, and the server cannot influence reconnection backoff to prevent reconnection storms. Note: event ordering and sequencing is the responsibility of the consuming application, not this package; however, the lack of protocol-level `id:` and `retry:` field support means the package does not provide the wire-format primitives that consuming applications would need to implement their own reconnection strategies.
- **Evidence:** Spike 2, Finding 4 (event IDs and Last-Event-ID reconnection not supported; no `id:` field written anywhere) and Finding 5 (retry interval not supported; no `retry:` field in any code path).
- **Severity:** Low
- **Frequency:** Rarely -- relevant only when consumers need reconnection resilience, which is an application-level concern.

---

### Cluster: Testing and Maintenance Friction

**Theme:** The SSE implementation's tight coupling to the controller and to PHP runtime functions creates disproportionate testing infrastructure and ongoing maintenance burden.

**Affected users:** Package maintainers and contributors who test, review, or modify the SSE functionality.

#### Problem 8: SSE Testing Requires Heavyweight Function Override Infrastructure

- **Description:** Testing the SSE event loop requires namespace-scoped overrides of four PHP built-in functions (`connection_aborted`, `sleep`, `flush`, `ob_flush`), implemented across 135 lines in a fixtures file plus a 56-line static registry class. Tests cannot verify timing behaviour directly and must simulate it by advancing Carbon's test clock and controlling abort counts via closures. This infrastructure is non-trivial to understand, maintain, and extend.
- **Evidence:** Spike 1, Finding 7 (four function overrides across two namespaces, 135-line override file, 56-line registry, plus `ob_start`/`ob_end_clean` in test setup).
- **Severity:** Medium
- **Frequency:** Weekly -- encountered whenever SSE tests are run, reviewed, or modified.

#### Problem 9: Duplicated Output Buffer Management Across Two Components

- **Description:** The base controller's SSE methods and the `RespondsWithStream` trait independently implement the same output buffer management pattern (`ob_get_level()` check, conditional `ob_flush()`, explicit `flush()`). These two components share no common abstraction for this cross-cutting concern, meaning buffer-management fixes or improvements must be applied in two places.
- **Evidence:** Spike 1, Finding 5 (identical buffer-flushing pattern in `Controller.php` lines 117-122 and `RespondsWithStream.php` lines 58-62, with no shared abstraction).
- **Severity:** Low
- **Frequency:** Rarely -- surfaces when buffer management logic needs to change, which is infrequent.

---

### Cluster: Implicit Coupling Between SSE Components

**Theme:** SSE-related concerns are spread across multiple locations in the codebase with only implicit connections, creating hidden dependencies that are easy to break.

**Affected users:** Package maintainers and developers extending SSE behaviour.

#### Problem 10: Content Negotiation and Response Construction Are Implicitly Coupled

- **Description:** The `expectsStream` request macro (registered in the service provider) checks whether the `Accept` header equals `text/event-stream`, while the controller's SSE methods set `Content-Type: text/event-stream` in the response. These two halves of SSE content negotiation share knowledge of the `text/event-stream` string literal but are not formally connected through a shared constant, enum, or interface. Changing one without updating the other would silently break content negotiation.
- **Evidence:** Spike 1, Finding 6 (the `expectsStream` macro in `ApiServiceProvider.php` line 209 is not referenced by the controller's SSE methods; the macro is tested only for existence, not correctness).
- **Severity:** Low
- **Frequency:** Rarely -- surfaces only when modifying SSE content-type handling, which is infrequent.

---

## Cross-Cutting Concerns

- **Output buffer management as a shared concern:** The pattern of checking `ob_get_level()`, conditionally calling `ob_flush()`, and calling `flush()` appears in both the controller SSE methods and the `RespondsWithStream` trait (Problem 9). It also complicates testing (Problem 8). This concern spans the "Testing and Maintenance Friction" and "Inheritance Burden" clusters and suggests that buffer management is an independently meaningful concern regardless of how SSE is structured.

- **Wire-format knowledge distributed across concerns:** The SSE wire format (`data:`, `event:`, `id:`, `retry:`, comments) is currently known only implicitly -- the controller hardcodes `event: error\n\n` and `:\n\n`, while consumers must independently know the full format to emit events. This distribution of format knowledge connects Problem 4 (error events), Problem 5 (malformed output), Problem 6 (consumers must know format), and Problem 7 (missing protocol fields).

- **PHP runtime coupling:** The SSE event loop directly calls PHP built-in functions (`connection_aborted`, `sleep`, `flush`, `ob_flush`) that behave differently across SAPIs (PHP-FPM, Octane/Swoole, RoadRunner). This runtime coupling affects both the "Inheritance Burden" cluster (behaviour cannot be adapted per environment) and the "Testing Friction" cluster (requires function overrides to test).

---

## Gaps

- **Downstream consumer usage patterns:** How consuming applications actually use `respondWithEventStream()` (what callbacks they pass, whether they override heartbeat, what wire format they emit) cannot be determined from this repository alone (Spike 1, Open Thread 1; Spike 2, Open Thread 2). This is critical for understanding the real-world coupling surface and backward compatibility requirements.

- **PHP SAPI behaviour differences:** The output buffering and `connection_aborted()` behaviour may differ across PHP-FPM, Swoole/OpenSwoole (Octane), and RoadRunner (Spike 2, Open Thread 3). No spike investigated these differences empirically, so the severity of SAPI-specific problems is uncertain.

- **Browser conformance for empty-data events:** The finding that `event: error\n\n` is silently dropped is based on the WHATWG specification text. Empirical verification against Chrome, Firefox, and Safari would move confidence from medium to high (Spike 2, Open Thread 1).

- **Testing patterns for extracted SSE components:** Neither spike investigated what testing patterns work best for standalone SSE classes in Laravel (Intake Brief, Open Question 5). Understanding this would inform how extraction affects testability in practice.

- **Competing PHP/Laravel SSE implementations:** The intake brief identified a medium-priority research seed about how other packages implement SSE (Intake Brief, Research Seeds row 3). No spike was conducted on this topic, so the problem map lacks comparative context for what patterns the ecosystem considers standard.

---

## References

- Intake Brief: [intake-brief.md](intake-brief.md)
- Spikes:
  - [spikes/spike-current-implementation.md](spikes/spike-current-implementation.md)
  - [spikes/spike-sse-specification.md](spikes/spike-sse-specification.md)
