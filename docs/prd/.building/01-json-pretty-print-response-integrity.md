# PRD: 01 JSON Pretty-Print Response Integrity

Ensure the JSON pretty-print middleware preserves response data integrity by correctly handling different response types and retaining existing encoding options.

---

## Governance

| Field     | Value                                                                                                     |
|-----------|-----------------------------------------------------------------------------------------------------------|
| Created   | 2026-03-10                                                                                                |
| Status    | approved                                                                                                  |
| Owned by  | Ben                                                                                                       |
| Traces to | [Prioritization](.sinemacula/blueprint/workflows/json-pretty-print-inefficiency/prioritization.md) — Rank 1: Non-JSON Response Corruption, Rank 2: Encoding Option Loss |

---

## Overview

The `JsonPrettyPrint` middleware in the Laravel API Toolkit silently corrupts response data in two ways: it destroys non-JSON response bodies by replacing them with the string `"null"`, and it discards all existing JSON encoding options (such as `JSON_UNESCAPED_SLASHES` and Symfony's default HTML-safe encoding flags) when applying `JSON_PRETTY_PRINT`. These are correctness failures, not performance issues — discovery research confirmed that re-encoding is unavoidable at the middleware level regardless of the approach used.

The middleware should be aware of response types, using the framework's encoding options API for `JsonResponse` instances (which preserves existing options) and safely skipping responses that are not JSON. This restores the toolkit's core promise of consistent, reliable API responses when the `?pretty=true` query parameter is used.

This is a low-severity issue in terms of production urgency — the P0 priority reflects high alignment with the toolkit's core vision and low implementation effort, not critical production impact. The `?pretty=true` parameter is primarily a developer debugging tool.

---

## Target Users

| Persona                  | Description                                                                                                    | Key Need                                                                                     |
|--------------------------|----------------------------------------------------------------------------------------------------------------|----------------------------------------------------------------------------------------------|
| API Developer            | Developer building an API using the toolkit who uses `?pretty=true` to inspect response structure during development | Accurate, faithful pretty-printed output that matches what the API would return without `?pretty` — just formatted |
| Toolkit Consumer         | Developer who has integrated the toolkit into an application that serves both API and non-API routes           | Confidence that the globally-registered middleware will not corrupt non-JSON responses         |

**Primary user:** API Developer

---

## Goals

- Eliminate silent data corruption when the middleware encounters non-JSON responses with `?pretty=true`
- Preserve all encoding options set during response construction when applying pretty-printing
- Ensure encoding consistency between the middleware and the exception handler for the same `?pretty=true` trigger

## Non-Goals

- Optimising the performance of the decode/re-encode cycle (discovery research confirmed re-encoding is unavoidable at the middleware level; the cost is inherent to the approach)
- Changing the `?pretty=true` trigger mechanism or query parameter name
- Moving the middleware from global to route-group scope (this is an architectural concern covered by ISSUE-07)
- Modifying the exception handler's pretty-print logic (the middleware should be the single authority for pretty-printing)

---

## Problem

**User problem:** When a developer adds `?pretty=true` to an API request for debugging, the middleware silently alters the response beyond just formatting: encoding options like `JSON_UNESCAPED_SLASHES` are stripped (causing URLs to display with escaped slashes `\/`), and non-JSON responses are replaced with the literal string `"null"`. The developer cannot trust that `?pretty=true` output faithfully represents the actual API response, undermining its value as a debugging tool.

**Business problem:** An API toolkit that silently corrupts response data — even in a debugging feature — erodes developer trust. The encoding inconsistency between the middleware and exception handler means the same `?pretty=true` parameter produces different encoding behavior depending on whether the response is a success or an error, which is confusing and suggests a lack of internal consistency.

**Current state:** The middleware applies `json_encode(json_decode($response->getContent()), JSON_PRETTY_PRINT)` to every response when `?pretty=true` is set, regardless of response type. Non-JSON responses are silently destroyed. JSON responses lose all encoding options except `JSON_PRETTY_PRINT`. Exception responses are double-processed (the exception handler already applied pretty-printing) and lose `JSON_UNESCAPED_SLASHES` in the second pass.

**Evidence:**
- Spike "Response Encoding Mechanics", Finding 2: middleware discards all existing encoding options
- Spike "Response Encoding Mechanics", Finding 5: existing test confirms non-JSON content becomes `"null"`
- Spike "Response Encoding Mechanics", Finding 6: exception responses are double-processed, losing `JSON_UNESCAPED_SLASHES`
- Problem Map, Cluster "Response Data Integrity": Problems 1 and 2

---

## Proposed Solution

When a developer appends `?pretty=true` to an API request, the middleware applies pretty-printing in a type-aware manner:

- For JSON responses, the developer sees correctly formatted output with all original encoding options preserved. URLs remain unescaped, HTML entities remain safely encoded, and any custom encoding options set during response construction are retained.
- For non-JSON responses (HTML pages, streamed downloads, plain text), the middleware passes the response through untouched. The developer's response is never corrupted.
- For exception responses that have already been pretty-printed by the exception handler, the middleware either skips the redundant processing or applies it idempotently — the developer sees the same correct output either way.

### Key Capabilities

- Developer can use `?pretty=true` on any API endpoint and receive faithfully formatted JSON output with all encoding options preserved
- Developer can use `?pretty=true` without risk of corrupting non-JSON responses that share the middleware pipeline
- Developer sees consistent encoding behavior between success responses and error responses when using `?pretty=true`

---

## Requirements

### Must Have (P0)

- **Response type awareness:** The middleware only applies pretty-printing to responses that contain JSON content. Non-JSON responses pass through unmodified.
  - **Acceptance criteria:** When `?pretty=true` is set and the response is not a JSON response, the response body and headers are identical to the response without `?pretty=true`.

- **Encoding option preservation:** When applying pretty-printing to a JSON response, the middleware preserves all existing encoding options and adds `JSON_PRETTY_PRINT` alongside them.
  - **Acceptance criteria:** A JSON response constructed with `JSON_UNESCAPED_SLASHES | JSON_HEX_TAG` and then pretty-printed by the middleware retains both `JSON_UNESCAPED_SLASHES` and `JSON_HEX_TAG` in addition to `JSON_PRETTY_PRINT`.

- **Idempotent pretty-printing:** The middleware does not degrade responses that have already been pretty-printed (e.g., by the exception handler). Applying the middleware to an already-pretty-printed response produces the same output as applying it once.
  - **Acceptance criteria:** An exception response constructed with `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES` retains `JSON_UNESCAPED_SLASHES` after the middleware processes it.

### Should Have (P1)

- **Streamed response safety:** The middleware explicitly skips streamed responses (`StreamedResponse`, `BinaryFileResponse`) without attempting to read their content.
  - **Acceptance criteria:** A `StreamedResponse` passing through the middleware with `?pretty=true` is returned unmodified; no call to `getContent()` is made.

### Nice to Have (P2)

- **Non-JSON fallback with content-type guard:** For plain `Response` instances (not `JsonResponse`) that contain JSON content (detectable via `application/json` content-type header), the middleware applies a guarded pretty-print that preserves the original content if JSON decoding fails.

---

## Success Criteria

| Metric                           | Baseline                                                                 | Target                                            | How Measured                                                                                       |
|----------------------------------|--------------------------------------------------------------------------|---------------------------------------------------|----------------------------------------------------------------------------------------------------|
| Non-JSON response integrity      | Non-JSON content with `?pretty=true` is replaced with `"null"`           | Non-JSON content is returned unmodified            | Unit test: assert non-JSON response body is identical before and after middleware with `?pretty=true` |
| Encoding option preservation     | All encoding options are discarded; only `JSON_PRETTY_PRINT` is applied  | All original encoding options are preserved        | Unit test: construct `JsonResponse` with known options, verify all are present after middleware      |
| Exception response consistency   | Exception responses lose `JSON_UNESCAPED_SLASHES` after middleware pass  | Exception responses retain all encoding options    | Unit test: pretty-printed exception response retains `JSON_UNESCAPED_SLASHES` after middleware       |
| Static analysis                  | Passes PHPStan level 8                                                   | Continues to pass PHPStan level 8                  | `composer check` passes                                                                             |

---

## Dependencies

- None. All changes are internal to the `JsonPrettyPrint` middleware. The framework APIs (`JsonResponse::setEncodingOptions()`, `getEncodingOptions()`) are stable public APIs in both Laravel and Symfony.

---

## Assumptions

- The vast majority of responses passing through the middleware are `JsonResponse` instances, based on the analysis that all controller and exception handler response methods return `JsonResponse`.
- The `?pretty=true` feature is primarily used for developer debugging, not production traffic. Performance of the decode/re-encode cycle is acceptable for this use case.
- Consuming applications do not rely on the current behavior where encoding options are stripped (e.g., no consumer expects `?pretty=true` to produce escaped slashes).

---

## Risks

| Risk                                                    | Impact                                                                          | Likelihood | Mitigation                                                                                                         |
|---------------------------------------------------------|---------------------------------------------------------------------------------|------------|---------------------------------------------------------------------------------------------------------------------|
| Existing tests assert the current buggy behavior        | Test `testHandlesNonJsonContentWithPrettyParam` asserts content becomes `"null"` | High       | Update the test to assert the corrected behavior (non-JSON content passes through unchanged)                        |
| Consumer code relies on the encoding option stripping   | A consuming application could break if encoding options are now preserved        | Low        | This is a bug fix, not a behavior change; the previous behavior was unintentional and undocumented                  |
| Plain `Response` with JSON content is missed            | Edge case where a non-`JsonResponse` carries JSON content and is not pretty-printed | Low    | The P2 requirement covers this fallback; all toolkit response methods already return `JsonResponse`                  |

---

## Out of Scope

- Middleware scoping (moving from global to route-group registration) — this is ISSUE-07's concern
- Exception handler refactoring (removing its pretty-print logic in favour of the middleware) — this is a separate architectural decision
- Performance optimisation of the decode/re-encode cycle — discovery confirmed this is inherent to the middleware approach
- Changes to the `?pretty=true` query parameter or its boolean parsing logic

---

## Release Criteria

- All existing tests pass (updated to reflect corrected behavior where applicable)
- New tests cover: JSON response encoding option preservation, non-JSON response pass-through, streamed response safety, idempotent pretty-printing
- `composer check` passes (PHPStan level 8, PHP-CS-Fixer, CodeSniffer)
- `composer test` passes with full coverage of the modified middleware
- No breaking changes to the public API (the `?pretty=true` parameter continues to work as before, with corrected output)

---

## Traceability

| Artifact             | Path                                                                                                    |
|----------------------|---------------------------------------------------------------------------------------------------------|
| Intake Brief         | `.sinemacula/blueprint/workflows/json-pretty-print-inefficiency/intake-brief.md`                        |
| Relevant Spikes      | `.sinemacula/blueprint/workflows/json-pretty-print-inefficiency/spikes/spike-response-encoding-mechanics.md` |
| Problem Map Entry    | Response Data Integrity > Problem 1: Non-JSON Response Corruption, Problem 2: Encoding Option Loss      |
| Prioritization Entry | Rank 1: Non-JSON Response Corruption (P0, score 8), Rank 2: Encoding Option Loss (P0, score 8)         |

---

## References

- Traces to: [Prioritization](.sinemacula/blueprint/workflows/json-pretty-print-inefficiency/prioritization.md) — Ranks 1-2
- Intake Brief: `.sinemacula/blueprint/workflows/json-pretty-print-inefficiency/intake-brief.md`
- Related issue: ISSUE-07 (Service Provider Uses Fragile Kernel Middleware Manipulation) — middleware scoping dependency
- Related issue: ISSUE-02 (JsonPrettyPrint Middleware Decode/Re-encode Inefficiency) — original issue description
