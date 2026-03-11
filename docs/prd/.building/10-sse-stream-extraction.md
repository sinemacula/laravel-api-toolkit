# PRD: 10 SSE Stream Extraction

Extract the SSE transport logic from the base controller into a dedicated, independently usable streaming component so
that developers can use SSE from any context, emit events through a structured abstraction instead of raw wire format,
and customise streaming behaviour without modifying the base controller.

---

## Governance

| Field     | Value                                                                                                                                                                          |
|-----------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Created   | 2026-03-10                                                                                                                                                                     |
| Status    | draft                                                                                                                                                                          |
| Owned by  | Ben                                                                                                                                                                            |
| Traces to | [Prioritization](.sinemacula/blueprint/workflows/sse-controller-decoupling/prioritization.md) -- Rank 1: P2 (SSE unusable outside controller), Rank 2: P1 (SSE inflates base controller), Rank 5: P6 (consumers must know wire format), Rank 6: P3 (behaviour frozen) |

---

## Overview

The base API controller contains 85 lines of SSE transport logic -- connection lifecycle management, heartbeat
emission, output buffering, error handling, and response construction -- that constitutes 54% of the controller's body.
Every controller subclass inherits this code regardless of whether it uses event streaming. More critically, the SSE
functionality is locked inside the controller hierarchy: developers who need SSE from jobs, artisan commands, or
standalone service classes cannot access it without extending the controller or duplicating the implementation.

This PRD specifies the extraction of SSE streaming into a dedicated, independently usable component. The extraction
accomplishes four things simultaneously: it removes SSE-specific transport logic from the base controller (P1), makes
SSE streaming available outside the controller hierarchy (P2), provides an emitter abstraction so consumers no longer
need to know SSE wire format (P6), and introduces proper extension points to replace the currently frozen private
methods (P3). These four problems are bundled because they are structurally inseparable -- extracting the SSE logic
necessarily creates a standalone component (P2), reduces the controller (P1), provides the natural location for an
emitter API (P6), and replaces private methods with overridable ones (P3).

The base controller's `respondWithEventStream()` method becomes a thin wrapper that delegates to the extracted
component, preserving backward compatibility for all existing call sites.

---

## Target Users

| Persona                 | Description                                                                                | Key Need                                                                       |
|-------------------------|--------------------------------------------------------------------------------------------|--------------------------------------------------------------------------------|
| SSE feature developer   | Developer building real-time streaming features (progress, notifications, live updates)     | Use SSE streaming from any context without being forced into the controller hierarchy |
| Controller author       | Developer extending the base controller for standard JSON API endpoints                    | Work with a base controller that contains only controller-level concerns        |
| SSE callback author     | Developer writing the callback that produces events within an SSE stream                   | Emit structured events without knowing SSE wire-format syntax                  |
| Package maintainer      | Developer maintaining or extending the toolkit's SSE streaming capability                  | Modify or extend SSE behaviour through proper extension points, not by forking |

**Primary user:** SSE feature developer

---

## Goals

- SSE streaming is usable from any PHP context (controllers, jobs, artisan commands, standalone classes) without
  requiring the controller hierarchy
- The base controller's body is reduced to only controller-level concerns; SSE transport logic is no longer inherited by
  non-streaming controllers
- Developers can emit SSE events through a structured abstraction that handles wire-format encoding, eliminating the
  need to manually construct SSE protocol strings
- SSE streaming behaviour (heartbeat, error handling, connection lifecycle) can be customised through proper extension
  points rather than being frozen behind private methods
- All existing `respondWithEventStream()` call sites continue to work without modification

## Non-Goals

- Implementing event ordering, sequencing, or deduplication logic (consuming application's responsibility)
- Adding `id:` or `retry:` field support to the emitter (separate concern; P7 in prioritization, P1 tier)
- Implementing client reconnection or `Last-Event-ID` handling (application-level concern)
- Changing the SSE polling/sleep loop model to a push-based model
- Unifying output buffer management between the SSE component and the `RespondsWithStream` trait (P9, separate concern)
- Formalising the coupling between the `expectsStream` macro and the SSE response component (P10, separate concern)
- Fixing the error event wire format to include a `data:` field (P4, depends on the emitter abstraction this PRD
  creates, but is a separate correctness fix)
- Adding validation to prevent malformed SSE output from callbacks (P5, depends on the emitter abstraction this PRD
  creates, but is a separate correctness fix)
- Supporting alternative streaming transports (WebSockets, long polling)
- Modifying the `expectsStream` request macro in the service provider

---

## Problem

**User problem:** Developers who need SSE streaming outside the controller hierarchy -- from jobs, artisan commands, or
standalone service classes -- cannot access the toolkit's SSE functionality. The streaming logic is embedded as one
protected and two private methods on the abstract base controller. The only options are to extend the controller
(inheriting unrelated HTTP concerns) or to duplicate the 85 lines of transport logic. Additionally, developers writing
SSE callbacks must manually echo raw wire-format strings (`echo "event: update\ndata: {json}\n\n";`) because the
callback receives no emitter abstraction. This requires knowledge of SSE protocol syntax and provides no protection
against formatting errors. Meanwhile, every controller subclass -- including those that only serve JSON -- inherits
three SSE methods and a constant that are irrelevant to their purpose.

**Business problem:** For a shared library that aims to provide consistent, well-separated API patterns, having 54% of
the base controller dedicated to a transport protocol that most controllers never use contradicts the package's
architectural goals. The tight coupling forces consumers into the controller hierarchy for SSE access, limiting adoption
of the streaming capability. The frozen private methods prevent consumers from customising behaviour, pushing them
toward forking or workarounds that fragment the ecosystem.

**Current state:** Developers accept the coupling. Those who need SSE extend the base controller and call
`respondWithEventStream()` with a callback that manually echoes raw SSE wire format. The `HEARTBEAT_INTERVAL` constant
is the only customisation point. Developers who need SSE outside a controller duplicate the implementation.

**Evidence:**

- [Spike: Current Implementation](../../.sinemacula/blueprint/workflows/sse-controller-decoupling/spikes/spike-current-implementation.md) --
  Finding 1: Three SSE methods occupy 85 lines (54% of controller body). Finding 2: SSE methods are only referenced in
  the controller and its tests. Finding 3: Seven distinct responsibilities embedded in the controller. Finding 4:
  Heartbeat interval is the only configurable parameter.
- [Spike: SSE Specification](../../.sinemacula/blueprint/workflows/sse-controller-decoupling/spikes/spike-sse-specification.md) --
  Finding 7: Callback typed as `callable(): void` with no emitter API; consumers must echo raw wire format.
- [Problem Map](../../.sinemacula/blueprint/workflows/sse-controller-decoupling/problem-map.md) --
  Cluster: Inheritance Burden on Non-Streaming Controllers (P1, P2, P3).
  Cluster: Specification Conformance Gaps (P6).

---

## Proposed Solution

When a developer needs SSE streaming from any context -- a controller, a job, an artisan command, or a standalone
service -- they instantiate or inject the SSE streaming component directly. They are not required to extend the
controller hierarchy. The component handles all transport-level concerns: setting the correct response headers,
managing the connection lifecycle, emitting heartbeat comments, managing output buffering, and handling errors.

When writing event-producing logic, the developer receives an emitter that provides structured methods for sending SSE
events. Instead of manually constructing `echo "data: {json}\n\n";`, the developer calls a method on the emitter with
the event data. The emitter handles wire-format encoding, including proper newline handling for multiline data values.
The developer focuses on what data to send, not how to format it for the SSE protocol.

When a developer needs to customise streaming behaviour -- adjusting the heartbeat interval, changing error handling,
modifying the connection lifecycle -- they can do so through the component's extension points. The behaviour is no longer
locked behind private methods on the base controller.

When a developer has existing code that calls `respondWithEventStream()` on the base controller, it continues to work
without any changes. The controller method becomes a thin wrapper that delegates to the extracted component.

### Key Capabilities

- Developer can create SSE streams from any PHP context without extending the controller hierarchy
- Developer can emit events through a structured emitter abstraction that handles wire-format encoding
- Developer can customise heartbeat interval, error handling, and connection lifecycle through extension points
- Developer's existing `respondWithEventStream()` call sites continue to work unchanged

---

## Requirements

### Must Have (P0)

- **Independent SSE streaming:** Developer can create and use SSE streams from any PHP context (controller action, job,
  artisan command, standalone class) without extending or depending on the base controller
  - **Acceptance criteria:** A test demonstrates SSE stream creation and event emission from a non-controller context
    (e.g., a plain PHP class) with no reference to the base controller. The stream produces correct SSE wire-format
    output including headers, keep-alive comments, and event data.

- **Emitter abstraction for event emission:** Developer can emit SSE events by passing event data to a structured
  emitter rather than manually constructing SSE wire-format strings
  - **Acceptance criteria:** A developer can send an event with data through the emitter without writing any SSE
    protocol syntax (`data:`, `event:`, `\n\n`). The emitter correctly formats single-line and multiline data values
    according to the SSE specification (multiline data is split into multiple `data:` lines). The emitter supports
    named event types.

- **Controller method backward compatibility:** Developer's existing `respondWithEventStream()` call sites continue to
  function with identical observable behaviour (same response headers, same heartbeat, same callback invocation pattern,
  same error handling)
  - **Acceptance criteria:** All existing SSE tests in the test suite pass without modification to test assertions. The
    `respondWithEventStream()` method signature remains unchanged. The method returns the same response type with the
    same headers.

- **Base controller reduction:** The base controller no longer contains SSE transport logic (connection lifecycle,
  heartbeat emission, output buffering, error handling) in its body; it retains at most a thin delegation method
  - **Acceptance criteria:** The base controller's SSE-related code is reduced to a delegation method of no more than
    10 lines. The SSE constant, private event loop method, and private error handling method are no longer defined on
    the controller.

- **Customisable heartbeat:** Developer can configure the heartbeat interval on the SSE streaming component, replacing
  the current constant-only override mechanism
  - **Acceptance criteria:** A test demonstrates configuring a custom heartbeat interval on the SSE component. The
    configured interval is respected during streaming. The default interval matches the current 20-second default for
    backward compatibility.

- **Overridable behaviour:** Developer can extend or override SSE streaming behaviour (error handling, connection
  lifecycle management) through the component's extension points, replacing the current frozen private methods
  - **Acceptance criteria:** A test demonstrates extending the SSE component to customise error handling behaviour
    (e.g., changing what is emitted when a callback throws). The extension does not require modifying the base
    component's source code.

- **Static analysis compliance:** All changes pass PHPStan level 8 analysis
  - **Acceptance criteria:** `composer check` passes with no new errors or suppressions.

### Should Have (P1)

- **Cross-SAPI compatibility documentation:** Developer can understand which PHP SAPI environments (FPM, CLI, Octane)
  the SSE component supports and any environment-specific considerations
  - **Acceptance criteria:** The component's documentation or docblocks note any SAPI-specific behaviour differences
    for output buffering and connection detection.

- **Simplified test infrastructure:** Package maintainers can test SSE streaming behaviour with less fixture
  infrastructure than the current namespace-scoped function override approach
  - **Acceptance criteria:** The SSE component's test suite does not require namespace-scoped overrides of PHP built-in
    functions in the controller's namespace. Function overrides, if still needed, exist only in the extracted
    component's namespace.

### Nice to Have (P2)

- **Callback receives emitter:** Developer's callback receives the emitter as a parameter, enabling structured event
  emission from within the callback without capturing external variables
  - **Acceptance criteria:** The callback signature accepts the emitter as a parameter. Existing callbacks that accept
    no parameters continue to work (backward compatible).

---

## Success Criteria

| Metric                                  | Baseline                                                                        | Target                                                                        | How Measured                                                                     |
|-----------------------------------------|---------------------------------------------------------------------------------|-------------------------------------------------------------------------------|----------------------------------------------------------------------------------|
| SSE usability outside controller        | 0 contexts -- SSE only usable by extending the controller                       | SSE usable from any PHP context without controller dependency                 | Test case: SSE stream created and used from a non-controller class               |
| Base controller SSE code                | 85 lines, 54% of controller body (3 methods + 1 constant)                       | No more than 10 lines of delegation code; 0 SSE transport methods             | Line count of SSE-related code in the controller after extraction                |
| Wire-format abstraction coverage        | 0% -- all wire format manually echoed by consumers                              | Core SSE fields (data, event type) emittable through structured abstraction   | Emitter API supports data emission and named events without raw wire-format echo |
| Extension points for SSE behaviour      | 1 (HEARTBEAT_INTERVAL constant via late static binding)                         | Heartbeat interval, error handling, and connection lifecycle are overridable   | Test case: each behaviour customised through extension point                     |
| Backward compatibility                  | All existing SSE tests pass                                                     | All existing SSE tests pass without assertion changes                         | `composer test` exit code                                                        |
| Static analysis                         | All checks pass                                                                 | All checks pass with no new errors or suppressions                            | `composer check` exit code                                                       |

---

## Dependencies

- Laravel's `StreamedResponse` (or equivalent response type) remains available for constructing streaming HTTP responses
- PHP 8.3+ language features are available (per project requirements)
- The `respondWithEventStream()` method signature on the base controller is the current public API contract for
  existing consumers
- The `HEARTBEAT_INTERVAL` constant override mechanism is the current extension point that existing consumers may use

---

## Assumptions

- The current SSE implementation is functionally correct for its supported feature set (headers, heartbeat, connection
  detection, error handling) -- this is a structural extraction, not a behaviour change
- Consuming applications call `respondWithEventStream()` on controller subclasses; they do not directly call the private
  `runEventStream()` or `runStreamCallback()` methods (which is enforced by PHP visibility)
- The `HEARTBEAT_INTERVAL` constant override via late static binding may be used by some consumers and must have an
  equivalent customisation path in the extracted component
- Output buffering behaviour (`ob_flush`, `flush`) needs to work in standard PHP-FPM environments; Octane and
  alternative SAPI compatibility is a should-have, not a must-have

---

## Risks

| Risk                                                                  | Impact                                                                                          | Likelihood | Mitigation                                                                                                                                   |
|-----------------------------------------------------------------------|-------------------------------------------------------------------------------------------------|------------|----------------------------------------------------------------------------------------------------------------------------------------------|
| Consumers override `HEARTBEAT_INTERVAL` via late static binding       | Those overrides stop working if the constant is removed from the controller without migration    | Medium     | The controller delegation method must honour heartbeat configuration; document migration path for consumers using the constant override       |
| Extraction changes subtle timing or ordering of output flushing       | Heartbeat comments or event data may be delivered with different timing characteristics          | Low        | Preserve exact flush/buffer sequence from current implementation; existing tests verify output ordering                                       |
| Emitter abstraction adds overhead to hot path in event loop           | Measurable latency increase in high-frequency event emission scenarios                          | Low        | Keep emitter implementation minimal; benchmark against raw echo in CI if performance concerns arise                                           |
| PHP SAPI differences cause connection detection to behave differently | `connection_aborted()` may not work identically under Octane/Swoole as under FPM                | Medium     | Document known SAPI limitations; ensure the component's connection detection is overridable so consumers can provide SAPI-specific behaviour  |

---

## Out of Scope

- Adding `id:` or `retry:` SSE field support (P7 in prioritization; P1 tier -- depends on the emitter abstraction this
  PRD creates but is a separate capability addition)
- Fixing the error event to include a `data:` field for spec conformance (P4 in prioritization; P0 tier -- depends on
  the emitter abstraction but is a separate correctness fix that should be its own PRD)
- Adding wire-format validation to prevent malformed SSE output (P5 in prioritization; P0 tier -- depends on the
  emitter abstraction but is a separate correctness fix that should be its own PRD)
- Unifying output buffer management between the SSE component and the `RespondsWithStream` trait (P9; P1 tier)
- Formalising the `text/event-stream` constant shared between the `expectsStream` macro and the SSE component (P10;
  P1 tier)
- Implementing event ordering, sequencing, deduplication, or replay logic (consuming application's responsibility)
- Implementing client reconnection handling or `Last-Event-ID` header processing (application-level concern)
- Changing the polling/sleep loop model to event-driven or push-based streaming
- Supporting alternative real-time transports (WebSockets, long polling, HTTP/2 server push)
- Modifying the `expectsStream` request macro in the service provider
- Backpressure or client disconnect debouncing (mentioned in the intake brief's raw idea but not supported by spike
  evidence as a current user problem)

---

## Release Criteria

- `composer test` passes with no assertion modifications to existing tests
- `composer check` passes (PHPStan level 8, all linters)
- SSE streams can be created and used from a non-controller context (demonstrated by test)
- The base controller contains no SSE transport logic beyond a thin delegation method
- An emitter abstraction supports structured event emission (data and named event types) without raw wire-format echo
- Heartbeat interval, error handling, and connection lifecycle are customisable through extension points
- `respondWithEventStream()` call sites produce identical observable behaviour (headers, heartbeat, error handling)
- Migration guidance documented for consumers who override `HEARTBEAT_INTERVAL` via late static binding

---

## Traceability

| Artifact             | Path                                                                                                                                                                                                                                                              |
|----------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Intake Brief         | `.sinemacula/blueprint/workflows/sse-controller-decoupling/intake-brief.md`                                                                                                                                                                                       |
| Relevant Spikes      | `.sinemacula/blueprint/workflows/sse-controller-decoupling/spikes/spike-current-implementation.md`, `.sinemacula/blueprint/workflows/sse-controller-decoupling/spikes/spike-sse-specification.md`                                                                  |
| Problem Map Entry    | Inheritance Burden on Non-Streaming Controllers > P1: SSE Logic Inflates the Base Controller; Inheritance Burden on Non-Streaming Controllers > P2: SSE Logic Cannot Be Used Outside the Controller Hierarchy; Inheritance Burden on Non-Streaming Controllers > P3: SSE Behaviour Is Frozen with Minimal Extension Points; Specification Conformance Gaps > P6: Consumers Must Know SSE Wire Format to Emit Events |
| Prioritization Entry | Rank 1: P2 (Total 9, P0); Rank 2: P1 (Total 9, P0); Rank 5: P6 (Total 7, P0); Rank 6: P3 (Total 7, P0)                                                                                                                                                         |

---

## References

- Traces to: [Prioritization](.sinemacula/blueprint/workflows/sse-controller-decoupling/prioritization.md) --
  Ranks 1, 2, 5, 6
- Intake Brief: `.sinemacula/blueprint/workflows/sse-controller-decoupling/intake-brief.md`
- Strategic validation note: P6 (emitter abstraction) is a prerequisite for P4, P5, and P7 but is bundled with the
  extraction PRD because the emitter is a natural component of the extracted SSE streaming class
  (strategist flag 1, validated during Phase 3)
