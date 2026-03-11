# PRD: 11 SSE Specification Conformance

Ensure that SSE event emission conforms to the WHATWG specification so that error events reach clients and multiline
data does not corrupt the stream.

---

## Governance

| Field     | Value                                                                                                                                                                       |
|-----------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Created   | 2026-03-10                                                                                                                                                                  |
| Status    | draft                                                                                                                                                                       |
| Owned by  | Ben                                                                                                                                                                         |
| Traces to | [Prioritization](.sinemacula/blueprint/workflows/sse-controller-decoupling/prioritization.md) -- Rank 4: Error events silently dropped (P4) + Rank 3: Malformed output (P5) |

---

## Overview

The SSE emitter produced by PRD 10 (SSE Stream Extraction) provides the structural abstraction for event emission.
However, two specification-conformance problems remain that this PRD addresses.

First, when a callback throws an exception during streaming, the current implementation emits `event: error\n\n` with no
`data:` field. The WHATWG HTML Living Standard specifies that an event without a `data:` field is silently discarded by
the client's EventSource parser. This means server-side errors go unreported to the client despite the implementation's
explicit intent to signal them. The error event is a no-op on spec-compliant clients.

Second, because the callback has no structured emission mechanism, there is no protection against malformed SSE output.
If a developer's callback emits data containing literal newline characters -- which is common when sending JSON payloads
or multi-line text -- the SSE parser interprets the embedded newlines as field boundaries, corrupting the stream
silently. The WHATWG specification requires that multiline data be sent as multiple consecutive `data:` lines, but the
current implementation provides no utility to enforce or automate this.

These two problems are addressed together because they share a common root: the emitter abstraction created by PRD 10
is the natural location where wire-format correctness is enforced. Fixing error events requires emitting a `data:` field
alongside the `event:` field, and protecting against malformed output requires the emitter to handle newline encoding in
data payloads. Both are wire-format conformance fixes within the same component.

---

## Target Users

| Persona                  | Description                                                                              | Key Need                                                                                         |
|--------------------------|------------------------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------|
| SSE feature developer    | Developer building real-time features using the toolkit's SSE streaming capabilities      | Error events actually reach the client so the application can display or handle failure states    |
| SSE callback author      | Developer writing the callback logic that emits events during streaming                   | Data payloads containing newlines are transmitted correctly without corrupting the stream         |
| Package maintainer       | Developer maintaining and evolving the toolkit's SSE implementation                       | Wire-format output conforms to the WHATWG specification without requiring manual format knowledge |

**Primary user:** SSE feature developer

---

## Goals

- Error events emitted during streaming are received by spec-compliant EventSource clients
- Data payloads containing newline characters are transmitted without corrupting the SSE stream
- Wire-format conformance is enforced by the emitter, not delegated to consumer callbacks

## Non-Goals

- Implementing client reconnection protocol support (`id:` and `retry:` fields) -- this is a separate concern (P7, P1 tier) and event ordering/sequencing is the consuming application's responsibility
- Providing named event type support as a general capability -- named events are part of the emitter abstraction in PRD 10, not this conformance PRD
- Changing the heartbeat format or interval -- heartbeat comments already conform to the specification
- Modifying the SSE header set (`Content-Type`, `Cache-Control`, `Connection`, `X-Accel-Buffering`) -- these are already correct per the specification
- Implementing backpressure or client disconnect debouncing -- these are operational concerns beyond specification conformance
- Handling PHP SAPI-specific output buffering differences -- output buffer management is an extraction concern (PRD 10), not a wire-format concern

---

## Problem

**User problem:** Developers building SSE-powered features encounter two silent failure modes. When their callback
throws an exception, they expect the client to receive an error signal -- but spec-compliant EventSource clients
silently discard the error event because it lacks a `data:` field, leaving the client unaware that an error occurred.
When their callback emits data containing newline characters (common in JSON payloads), the SSE stream is silently
corrupted because embedded newlines are interpreted as field boundaries by the parser. In both cases, the failure is
silent: no error, no warning, no indication that anything went wrong.

**Business problem:** An API toolkit that emits non-conformant SSE events undermines developer trust in the package's
reliability. Silent stream corruption and unreported errors increase debugging time for consumers and create the
impression that the toolkit's SSE support is unreliable. For an open-source package aiming to provide consistent API
patterns, specification conformance is a baseline expectation.

**Current state:** Developers who encounter error events during streaming see no client-side notification -- the error
is reported server-side via Laravel's `report()` helper but the client receives nothing. Developers who emit multiline
data must manually split their data into multiple `data:` lines following the SSE wire format, or accept that their
stream will be corrupted. Most developers are unaware of either issue until they encounter unexpected client behaviour.

**Evidence:**

- [Spike: SSE Specification Coverage](../../.sinemacula/blueprint/workflows/sse-controller-decoupling/spikes/spike-sse-specification.md) --
  Finding 8: The error event `event: error\n\n` lacks a `data:` field; the WHATWG specification states that events with
  empty data buffers are not dispatched. A conforming emission requires at least `data: \n` alongside the event type.
- [Spike: SSE Specification Coverage](../../.sinemacula/blueprint/workflows/sse-controller-decoupling/spikes/spike-sse-specification.md) --
  Finding 6: Multiline data fields are not handled; embedded newlines in `data:` values corrupt the stream because the
  SSE parser splits lines on CR, LF, or CRLF.
- [Spike: Current SSE Implementation Analysis](../../.sinemacula/blueprint/workflows/sse-controller-decoupling/spikes/spike-current-implementation.md) --
  Finding 8: The error event format is minimal and non-standard; the output `"event: error\n\n"` has the event type but
  no data line.
- [Problem Map](../../.sinemacula/blueprint/workflows/sse-controller-decoupling/problem-map.md) --
  Cluster: Specification Conformance Gaps, Problems 4 and 5.

---

## Proposed Solution

When a server-side error occurs during SSE streaming, the developer's client-side EventSource listener receives an error
event that the browser dispatches to the appropriate event handler. The error event conforms to the WHATWG specification
by including a `data:` field, ensuring that spec-compliant clients do not silently discard it. The developer does not
need to know the wire-format rules for error events -- the emitter handles this automatically.

When a developer emits data that contains newline characters, the emitter automatically encodes the data into the
correct multi-line `data:` format required by the SSE specification. The developer passes their data as-is and the
emitter handles the newline splitting. A developer sending a pretty-printed JSON payload or multi-line text receives the
same data on the client side, correctly reassembled by the EventSource parser, without needing to understand or
implement the wire-format encoding rules.

### Key Capabilities

- Developer's client-side error event listeners fire when a server-side error occurs during streaming
- Developer can emit data containing newline characters without corrupting the SSE stream
- Developer does not need to know SSE wire-format encoding rules to emit valid events
- Existing callback behaviour is preserved -- no breaking changes to the streaming API

---

## Requirements

### Must Have (P0)

- **Spec-conformant error events:** Developer's client-side EventSource error listeners receive error events when a
  server-side exception occurs during streaming, rather than the error event being silently discarded
  - **Acceptance criteria:** When a callback throws an exception during streaming, the emitted SSE event includes both
    an `event:` field and a `data:` field, producing output that a spec-compliant EventSource parser dispatches to
    client-side listeners. A test verifies that the emitted wire-format output contains `event: error\n` followed by
    `data:` on the next line, terminated by `\n\n`.

- **Multiline data encoding:** Developer can emit data payloads containing newline characters (LF, CR, or CRLF) and the
  data arrives intact on the client without stream corruption
  - **Acceptance criteria:** When a developer emits a data payload containing one or more newline characters, the
    emitter produces multiple consecutive `data:` lines (one per line of the original payload) as required by the WHATWG
    specification. A test verifies that a payload containing `"line1\nline2\nline3"` produces the wire-format output
    `data: line1\ndata: line2\ndata: line3\n\n`. Tests cover LF, CR, and CRLF line endings.

- **Backward compatibility:** Existing consumers of the SSE streaming API continue to function without code changes
  - **Acceptance criteria:** All existing SSE-related tests pass without modification to test assertions. The error
    event now includes a `data:` field (additive change), which is backward-compatible because clients that were
    previously ignoring the dropped error event will now receive it.

- **Static analysis compliance:** All changes pass PHPStan level 8 analysis
  - **Acceptance criteria:** `composer check` passes with no new errors or suppressions.

### Should Have (P1)

- **Error event data content:** Developer can distinguish error events from normal events on the client side, with the
  error event carrying a meaningful data payload rather than an empty one
  - **Acceptance criteria:** The error event's `data:` field contains a non-empty value that a client-side handler can
    use to identify the event as an error signal. The exact content is a design decision for the implementer, but it
    must not expose internal exception details (stack traces, file paths) to the client.

### Nice to Have (P2)

- **Encoding transparency:** Developer does not need to pre-process or escape data before emitting it through the SSE
  emitter -- all wire-format encoding is handled automatically regardless of the data content
  - **Acceptance criteria:** Data payloads containing edge-case content (empty strings, strings consisting entirely of
    newlines, strings with mixed CR/LF/CRLF endings, strings containing SSE field prefixes like `data:` or `event:`)
    are transmitted and received correctly.

---

## Success Criteria

| Metric                                  | Baseline                                                                      | Target                                                                                 | How Measured                                                                                                 |
|-----------------------------------------|-------------------------------------------------------------------------------|----------------------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------|
| Error event client delivery             | 0% -- error events are silently discarded by spec-compliant EventSource clients | 100% -- error events include a `data:` field and are dispatched by the client parser   | Unit test verifying emitted wire-format output contains both `event:` and `data:` fields                     |
| Multiline data stream integrity         | N/A -- no encoding exists; multiline data corrupts the stream                  | 100% -- all multiline payloads are correctly encoded as consecutive `data:` lines      | Unit tests for LF, CR, and CRLF line endings verifying correct `data:` line splitting in wire-format output  |
| Existing test pass rate                 | All SSE tests pass                                                             | All SSE tests pass                                                                     | `composer test` exit code                                                                                    |
| Static analysis                         | All checks pass                                                                | All checks pass                                                                        | `composer check` exit code                                                                                   |

---

## Dependencies

- **PRD 10 (SSE Stream Extraction):** This PRD depends on the emitter abstraction created by PRD 10. The conformance
  fixes described here (error event format, multiline data encoding) are implemented within the emitter that PRD 10
  extracts from the base controller. PRD 10 must be completed first, or these two PRDs must be implemented in sequence
  within the same work cycle.

---

## Assumptions

- The WHATWG HTML Living Standard's event dispatch algorithm is the authoritative specification for SSE client behaviour:
  events without a `data:` field are not dispatched. This is supported by the specification text and by spike research,
  though empirical browser verification was noted as a gap.
- The emitter abstraction from PRD 10 provides a structured API for event emission, replacing direct `echo` calls in
  callbacks. This PRD builds on that abstraction rather than modifying raw `echo` statements.
- Multiline data encoding (splitting on newlines into consecutive `data:` lines) is sufficient to handle all real-world
  payloads. No SSE payload requires encoding beyond newline splitting per the specification.
- Error events should not expose internal exception details (stack traces, file paths, class names) to clients, as this
  would be a security concern in production environments.

---

## Risks

| Risk                                                              | Impact                                                                                                       | Likelihood | Mitigation                                                                                                                                                 |
|-------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------|------------|------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Browser implementations differ from the WHATWG specification on empty-data event handling | The error event fix may be unnecessary for some clients or insufficient for non-conformant clients           | Low        | The fix is additive (adding a `data:` field cannot break clients that already ignore the event) and aligns with the specification regardless of browser edge cases |
| Multiline encoding introduces performance overhead for large payloads | High-frequency or large-payload streams may experience measurable latency from newline scanning and splitting | Low        | Newline splitting is a linear-time string operation; SSE payloads are typically small (individual events, not bulk data). Benchmark if performance concerns arise  |
| PRD 10 emitter design constrains conformance fixes                | The emitter API from PRD 10 may not accommodate the encoding or error-emission changes this PRD requires     | Medium     | This PRD is authored with knowledge of PRD 10's scope; the implementer should ensure the emitter API supports data encoding and error event formatting as first-class capabilities |

---

## Out of Scope

- Adding `id:` field support for event sequencing and client reconnection (P7 in prioritization, P1 tier -- event
  ordering is the consuming application's responsibility)
- Adding `retry:` field support for controlling client reconnection timing (P7 in prioritization, P1 tier)
- Extracting SSE logic from the base controller (PRD 10 -- this PRD builds on that extraction)
- Providing extension points for customizing SSE behaviour beyond error events and data encoding (P3 in prioritization,
  addressed by PRD 10's extraction)
- Modifying the heartbeat comment format (already spec-conformant)
- Modifying SSE response headers (already spec-conformant)
- Validating or sanitizing the content of data payloads beyond newline encoding (application-level concern)
- Handling PHP SAPI-specific output buffering differences (operational concern, not wire-format conformance)
- Implementing server-initiated graceful stream closure signaling (no in-band mechanism defined by the specification)

---

## Release Criteria

- `composer test` passes with no assertion modifications to pre-existing tests
- `composer check` passes (PHPStan level 8, all linters)
- Error events emitted during streaming include both an `event:` field and a `data:` field
- Data payloads containing LF, CR, and CRLF newlines are encoded as consecutive `data:` lines per the WHATWG
  specification
- No internal exception details (stack traces, file paths, class names) are exposed in error event data
- Existing SSE streaming behaviour is preserved for consumers who do not encounter errors or multiline data

---

## Traceability

| Artifact             | Path                                                                                                                                                                     |
|----------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Intake Brief         | `.sinemacula/blueprint/workflows/sse-controller-decoupling/intake-brief.md`                                                                                              |
| Relevant Spikes      | `.sinemacula/blueprint/workflows/sse-controller-decoupling/spikes/spike-current-implementation.md`, `.sinemacula/blueprint/workflows/sse-controller-decoupling/spikes/spike-sse-specification.md` |
| Problem Map Entry    | Specification Conformance Gaps > Problem 4: Error events are silently dropped by spec-compliant clients                                                                  |
| Problem Map Entry    | Specification Conformance Gaps > Problem 5: No protection against malformed SSE output                                                                                   |
| Prioritization Entry | Rank 4: P4 -- Error events silently dropped (P0, Total 8); Rank 3: P5 -- No protection against malformed output (P0, Total 8)                                           |

---

## References

- Traces to: [Prioritization](.sinemacula/blueprint/workflows/sse-controller-decoupling/prioritization.md) --
  Ranks 3 + 4
- Intake Brief: `.sinemacula/blueprint/workflows/sse-controller-decoupling/intake-brief.md`
- Depends on: PRD 10 (SSE Stream Extraction) -- the emitter abstraction where these conformance fixes are implemented
- WHATWG HTML Living Standard, Section 9.2: Server-sent events -- the authoritative specification for SSE wire format
  and client-side event dispatch behaviour
