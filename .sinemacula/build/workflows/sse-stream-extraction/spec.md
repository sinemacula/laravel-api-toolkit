# Spec Extract: SSE Stream Extraction

Extract SSE transport logic from the base controller into a dedicated, independently usable streaming component with a structured emitter abstraction and proper extension points.

---

## Governance

| Field     | Value                                                                                    |
|-----------|------------------------------------------------------------------------------------------|
| Created   | 2026-03-10                                                                               |
| Status    | approved                                                                                 |
| Owned by  | Architect                                                                                |
| Traces to | [PRD](docs/prd/10-sse-stream-extraction.md) -- PRD: 10 SSE Stream Extraction             |

---

## Source PRD

| Field | Value                                |
|-------|--------------------------------------|
| Path  | docs/prd/10-sse-stream-extraction.md |
| Title | PRD: 10 SSE Stream Extraction        |

---

## Requirements

### Functional Requirements

| ID     | Requirement                                                                                                                                                                                     | Priority | Source                  |
|--------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|----------|-------------------------|
| REQ-01 | Developer can create and use SSE streams from any PHP context (controller action, job, artisan command, standalone class) without extending or depending on the base controller                  | P0       | Requirements > Must Have |
| REQ-02 | Developer can emit SSE events by passing event data to a structured emitter rather than manually constructing SSE wire-format strings                                                           | P0       | Requirements > Must Have |
| REQ-03 | Existing `respondWithEventStream()` call sites continue to function with identical observable behaviour (same response headers, same heartbeat, same callback invocation pattern, same error handling) | P0       | Requirements > Must Have |
| REQ-04 | The base controller no longer contains SSE transport logic (connection lifecycle, heartbeat emission, output buffering, error handling) in its body; it retains at most a thin delegation method  | P0       | Requirements > Must Have |
| REQ-05 | Developer can configure the heartbeat interval on the SSE streaming component, replacing the current constant-only override mechanism                                                           | P0       | Requirements > Must Have |
| REQ-06 | Developer can extend or override SSE streaming behaviour (error handling, connection lifecycle management) through the component's extension points, replacing the current frozen private methods | P0       | Requirements > Must Have |
| REQ-07 | Developer can understand which PHP SAPI environments (FPM, CLI, Octane) the SSE component supports and any environment-specific considerations through documentation or docblocks                | P1       | Requirements > Should Have |
| REQ-08 | Package maintainers can test SSE streaming behaviour with less fixture infrastructure than the current namespace-scoped function override approach                                               | P1       | Requirements > Should Have |
| REQ-09 | Developer's callback receives the emitter as a parameter, enabling structured event emission from within the callback without capturing external variables                                       | P2       | Requirements > Nice to Have |

### Non-Functional Requirements

| ID     | Requirement                                                    | Category      | Source                  |
|--------|----------------------------------------------------------------|---------------|-------------------------|
| NFR-01 | All changes pass PHPStan level 8 analysis with no new errors or suppressions | Compliance    | Requirements > Must Have |
| NFR-02 | All existing SSE tests pass without assertion changes           | Compatibility | Success Criteria        |

---

## Acceptance Criteria

| ID    | Criterion                                                                                                                                                                                                                                                                                                                                        | Traces to |
|-------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|-----------|
| AC-01 | A test demonstrates SSE stream creation and event emission from a non-controller context (e.g., a plain PHP class) with no reference to the base controller. The stream produces correct SSE wire-format output including headers, keep-alive comments, and event data.                                                                           | REQ-01    |
| AC-02 | A developer can send an event with data through the emitter without writing any SSE protocol syntax (`data:`, `event:`, `\n\n`). The emitter correctly formats single-line and multiline data values according to the SSE specification (multiline data is split into multiple `data:` lines). The emitter supports named event types.              | REQ-02    |
| AC-03 | All existing SSE tests in the test suite pass without modification to test assertions. The `respondWithEventStream()` method signature remains unchanged. The method returns the same response type with the same headers.                                                                                                                        | REQ-03    |
| AC-04 | The base controller's SSE-related code is reduced to a delegation method of no more than 10 lines. The SSE constant, private event loop method, and private error handling method are no longer defined on the controller.                                                                                                                        | REQ-04    |
| AC-05 | A test demonstrates configuring a custom heartbeat interval on the SSE component. The configured interval is respected during streaming. The default interval matches the current 20-second default for backward compatibility.                                                                                                                   | REQ-05    |
| AC-06 | A test demonstrates extending the SSE component to customise error handling behaviour (e.g., changing what is emitted when a callback throws). The extension does not require modifying the base component's source code.                                                                                                                         | REQ-06    |
| AC-07 | The component's documentation or docblocks note any SAPI-specific behaviour differences for output buffering and connection detection.                                                                                                                                                                                                            | REQ-07    |
| AC-08 | The SSE component's test suite does not require namespace-scoped overrides of PHP built-in functions in the controller's namespace. Function overrides, if still needed, exist only in the extracted component's namespace.                                                                                                                        | REQ-08    |
| AC-09 | The callback signature accepts the emitter as a parameter. Existing callbacks that accept no parameters continue to work (backward compatible).                                                                                                                                                                                                   | REQ-09    |
| AC-10 | `composer check` passes with no new errors or suppressions.                                                                                                                                                                                                                                                                                      | NFR-01    |

---

## Constraints

### Scope Boundaries

- This is a structural extraction, not a behaviour change; the current SSE implementation is assumed to be functionally correct for its supported feature set
- The extraction covers SSE transport logic only (connection lifecycle, heartbeat, output buffering, error handling, response construction)
- The controller's `respondWithEventStream()` method becomes a thin delegation wrapper
- Output buffering behaviour must work in standard PHP-FPM environments; Octane and alternative SAPI compatibility is a should-have, not a must-have
- The `HEARTBEAT_INTERVAL` constant override via late static binding may be used by existing consumers and must have an equivalent customisation path in the extracted component

### Non-Goals

- Implementing event ordering, sequencing, or deduplication logic (consuming application's responsibility)
- Adding `id:` or `retry:` field support to the emitter (separate concern; P7 in prioritization, P1 tier)
- Implementing client reconnection or `Last-Event-ID` handling (application-level concern)
- Changing the SSE polling/sleep loop model to a push-based model
- Unifying output buffer management between the SSE component and the `RespondsWithStream` trait (P9, separate concern)
- Formalising the coupling between the `expectsStream` macro and the SSE response component (P10, separate concern)
- Fixing the error event wire format to include a `data:` field (P4, depends on the emitter abstraction this PRD creates, but is a separate correctness fix)
- Adding validation to prevent malformed SSE output from callbacks (P5, depends on the emitter abstraction this PRD creates, but is a separate correctness fix)
- Supporting alternative streaming transports (WebSockets, long polling)
- Modifying the `expectsStream` request macro in the service provider
- Implementing event ordering, sequencing, deduplication, or replay logic (consuming application's responsibility)
- Implementing client reconnection handling or `Last-Event-ID` header processing (application-level concern)
- Changing the polling/sleep loop model to event-driven or push-based streaming
- Supporting alternative real-time transports (WebSockets, long polling, HTTP/2 server push)
- Backpressure or client disconnect debouncing

---

## Success Criteria

| Metric                               | Baseline                                                                    | Target                                                                      | Measurement Method                                                               |
|--------------------------------------|-----------------------------------------------------------------------------|-----------------------------------------------------------------------------|----------------------------------------------------------------------------------|
| SSE usability outside controller     | 0 contexts -- SSE only usable by extending the controller                   | SSE usable from any PHP context without controller dependency               | Test case: SSE stream created and used from a non-controller class               |
| Base controller SSE code             | 85 lines, 54% of controller body (3 methods + 1 constant)                  | No more than 10 lines of delegation code; 0 SSE transport methods           | Line count of SSE-related code in the controller after extraction                |
| Wire-format abstraction coverage     | 0% -- all wire format manually echoed by consumers                         | Core SSE fields (data, event type) emittable through structured abstraction | Emitter API supports data emission and named events without raw wire-format echo |
| Extension points for SSE behaviour   | 1 (HEARTBEAT_INTERVAL constant via late static binding)                    | Heartbeat interval, error handling, and connection lifecycle are overridable | Test case: each behaviour customised through extension point                     |
| Backward compatibility               | All existing SSE tests pass                                                 | All existing SSE tests pass without assertion changes                       | `composer test` exit code                                                        |
| Static analysis                      | All checks pass                                                             | All checks pass with no new errors or suppressions                          | `composer check` exit code                                                       |

---

## Dependencies

| Dependency                                                                                              | Type     | Notes                                                                                   |
|---------------------------------------------------------------------------------------------------------|----------|-----------------------------------------------------------------------------------------|
| Laravel's `StreamedResponse` (or equivalent response type) for constructing streaming HTTP responses     | Package  | Must remain available in the target Laravel version                                      |
| PHP 8.3+ language features                                                                              | Platform | Per project requirements                                                                |
| `respondWithEventStream()` method signature on the base controller                                      | Internal | Current public API contract for existing consumers; must remain unchanged               |
| `HEARTBEAT_INTERVAL` constant override mechanism                                                        | Internal | Current extension point that existing consumers may use; must have equivalent migration |

---

## Open Questions

None. The PRD is well-formed with no ambiguities requiring resolution.

---

## References

- Traces to: [PRD](docs/prd/10-sse-stream-extraction.md) -- PRD: 10 SSE Stream Extraction
