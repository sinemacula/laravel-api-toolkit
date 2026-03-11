# Intake Brief: SSE Implementation Decoupled from Controller

Extract Server-Sent Events transport logic from the base controller into a dedicated, reusable streaming class.

---

## Governance

| Field     | Value         |
|-----------|---------------|
| Created   | 2026-03-10    |
| Status    | approved      |
| Owned by  | Ben           |
| Traces to | User idea     |

---

## Raw Idea

ISSUE-10: SSE (Server-Sent Events) Implementation Coupled to Controller

**Severity:** Low
**File(s):** `src/Http/Routing/Controller.php` (method `respondWithEventStream`)

### Problem

The `respondWithEventStream()` method in the base controller contains ~36 lines of transport-level SSE logic:

- Connection state management (`connection_aborted()`)
- Heartbeat interval tracking (20-second configurable interval)
- Output buffering and flushing (`ob_flush()`, `flush()`)
- Error event emission
- Initial keep-alive comment (`:\n\n`)
- Sleep/polling loop with configurable interval

This is transport protocol logic that doesn't belong in a controller. It:

1. Makes the controller harder to test (requires output buffering mocks).
2. Cannot be reused outside the controller hierarchy.
3. Mixes HTTP concerns (response headers) with streaming concerns (heartbeat, flush).
4. Has no backpressure or client disconnect debouncing.

### Desired Outcome

Extract SSE logic into a dedicated `SseStream` or `EventStreamResponse` class:

1. **`EventStreamResponse`** class extending `StreamedResponse` that encapsulates headers, heartbeat, and connection management.
2. **`SseEmitter`** interface that the callback receives, with `emit(string $event, mixed $data): void` and `heartbeat(): void` methods.
3. **Configurable heartbeat** -- interval, format, and whether to send at all.
4. The controller's `respondWithEventStream()` becomes a thin wrapper: `return new EventStreamResponse($callback)`.

### Constraints

- Must maintain backward compatibility with existing `respondWithEventStream()` call sites.
- Must work with Laravel's response testing utilities.
- Heartbeat and connection management must remain functional across different PHP SAPI environments (FPM, CLI, Octane).

---

## Problem Signal

**Who has this problem:** Developers building real-time features using the Laravel API Toolkit who need SSE streaming capabilities -- both maintainers of the toolkit itself and consumers extending the base controller.

**What is the problem:** The SSE transport logic (connection management, heartbeat, output buffering, event emission) is embedded directly in the base controller's `respondWithEventStream()` method, making it untestable in isolation, non-reusable outside the controller hierarchy, and tightly coupled to HTTP response concerns.

**Why it matters:** The coupling prevents independent testing of SSE behavior, forces any SSE consumer to extend the controller, and makes the streaming logic difficult to maintain or extend (e.g., adding backpressure, custom event formatting, or alternative transport). It also increases the surface area of the base controller with protocol-specific concerns.

**Current alternatives:** Developers must either extend the base controller to use SSE (inheriting unrelated controller concerns) or copy-paste the streaming logic into their own classes, duplicating the implementation.

---

## Context

**Domain:** PHP/Laravel API framework package -- specifically the HTTP streaming layer for real-time event delivery.

**Business context:** The Laravel API Toolkit is an open-source package providing consistent REST API patterns. SSE support was added as a controller method but has grown to include transport-level concerns that exceed what a controller should own. Extracting this improves the package's architectural quality and testability.

**Constraints:**

- Backward compatibility with existing `respondWithEventStream()` call sites is required
- Must work across PHP SAPI environments (FPM, CLI, Octane)
- Must integrate with Laravel's response testing utilities
- The package requires PHP ^8.3

**Assumptions:**

- The current SSE implementation is functional and covers the core use cases
- Consumers use the controller method rather than building their own SSE from scratch
- The extraction is a refactor, not a feature change -- observable behavior should remain identical

---

## Success Signals

| Signal                     | Description                                                                                  |
|----------------------------|----------------------------------------------------------------------------------------------|
| Testability improvement    | SSE streaming logic can be unit tested without controller instantiation or output buffering   |
| Reusability outside controller | SSE streaming can be used from jobs, artisan commands, or standalone classes             |
| Controller simplification  | `respondWithEventStream()` reduces to a thin wrapper (< 5 lines)                            |
| No breaking changes        | Existing call sites continue to work without modification                                    |

---

## Open Questions

- What does the current `respondWithEventStream()` implementation look like in detail, and what is the full set of responsibilities it handles?
- Are there any consumers of this method in the wild that use it in unexpected ways (e.g., subclassing and overriding parts of the SSE logic)?
- How do other PHP/Laravel packages implement SSE streaming -- is there a de facto pattern?
- Should the extracted class support event ID and retry fields per the SSE specification, or keep the current feature set?
- What testing patterns work best for SSE streaming in Laravel (e.g., testing streamed response content)?
- How should the extracted class handle Octane's persistent worker model differently from FPM's request-per-process model?

---

## Research Seeds

| Topic                              | Question                                                                                                         | Priority |
|------------------------------------|------------------------------------------------------------------------------------------------------------------|----------|
| Current implementation analysis    | What are the exact responsibilities and control flow of `respondWithEventStream()`, and what coupling exists?     | high     |
| SSE specification coverage         | What parts of the SSE specification (event IDs, retry, multiline data, named events) does the current implementation support, and what gaps exist? | high     |
| PHP/Laravel SSE patterns           | How do other Laravel/PHP packages (e.g., Livewire, Reverb, beyondcode/laravel-server-timing) implement SSE, and what patterns are reusable? | medium   |
| StreamedResponse testing           | What testing patterns exist for Laravel `StreamedResponse` subclasses, and how can SSE-specific assertions be built? | medium   |
| Octane/persistent worker SSE       | What are the specific considerations for SSE in Laravel Octane (connection lifecycle, memory leaks, worker limits)? | low      |

---

## References

- Source: User idea (captured 2026-03-10)
- ISSUES.md: ISSUE-10
