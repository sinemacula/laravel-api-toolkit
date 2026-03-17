# PRD: Exception Handler Coverage

Expand the `ApiExceptionHandler` to map all known Laravel/Symfony HTTP-layer exceptions to the correct internal
exception types with proper status codes, add a generic `HttpException` catch-all to preserve `abort()` status codes,
and introduce new exception classes for commonly needed HTTP semantics. Application-layer exceptions (database errors,
business logic) that reach the handler remain 500 -- they indicate unhandled bugs, not expected conditions.

---

## Governance

| Field     | Value                                                                                                                                |
|-----------|--------------------------------------------------------------------------------------------------------------------------------------|
| Created   | 2026-03-16                                                                                                                           |
| Status    | approved                                                                                                                             |
| Owned by  | Product Analyst                                                                                                                      |
| Traces to | [Prioritization](../../.sinemacula/blueprint/workflows/exception-handler-coverage/prioritization.md)                                 |
| Problems  | P1 (generic HttpException catch-all), P3 (BadRequestHttpException), P4 (ServiceUnavailableHttpException), P5 (new exception classes) |

---

## Background
 
The `ApiExceptionHandler::mapApiException()` method maps ~14 specific exception types to 8 `ApiException` subclasses.
Any exception not in this list falls to the `default` branch and becomes `UnhandledException` (HTTP 500).

Key gaps identified by the spike:

- **Every `abort()` call** with a non-mapped status code (409, 423, 503, etc.) produces a 500 instead of preserving
  the developer's intended status code
- **`BadRequestHttpException`** from Symfony produces a 500 instead of 400
- **`ServiceUnavailableHttpException`** produces a 500 despite the toolkit having `MaintenanceModeException` (503)
- **No exception classes** exist for 409 Conflict, 410 Gone, 413 Content Too Large, 423 Locked, or general 503

### Design philosophy: HTTP-layer vs. application-layer exceptions

The handler maps two categories of exceptions differently:

- **HTTP-layer exceptions** (Symfony `HttpException` subclasses, Laravel auth/validation exceptions): These represent
  intentional HTTP semantics chosen by the developer or framework. `abort(409)` means "I want a 409." The handler
  should respect this and produce the correct status code with the toolkit's structured error format.

- **Application-layer exceptions** (database errors, business logic exceptions): These represent unhandled conditions
  that should have been caught earlier. A `UniqueConstraintViolationException` reaching the handler means the developer
  didn't validate for duplicates -- the 500 is the correct signal ("you have a bug"). Auto-mapping it to 409 would
  mask the underlying code quality issue. If a developer wants a 409 for a duplicate, they should catch the exception
  and throw `ConflictException` explicitly.

This means the handler's job is: **map all known HTTP-layer exceptions to correct status codes; let everything else
remain 500 as a signal that something unhandled reached the boundary.**

This is v2. Breaking changes are acceptable.

---

## User Capabilities

### UC-1: abort() calls preserve the original HTTP status code

When a developer calls `abort(409)`, `abort(423)`, `abort(503)`, or any other HTTP status code, the API response
uses that status code with the toolkit's structured error format, rather than producing a generic 500.

**Acceptance criteria:**

- The mapper includes a catch-all branch for `HttpExceptionInterface` (after all specific exception branches)
- The catch-all preserves the original status code via `$exception->getStatusCode()`
- The response uses the toolkit's JSON error format (`{error: {status, code, title, detail, meta}}`)
- The title is derived from the HTTP status phrase (e.g., "Conflict" for 409, "Locked" for 423)
- A generic error code is used for unmapped status codes (e.g., `ErrorCode::HTTP_ERROR`)
- Headers from the original `HttpException` are preserved via `$exception->getHeaders()`
- This catch-all is positioned after all specific exception branches so that typed exceptions (404, 405, 429, etc.)
  still use their dedicated `ApiException` subclasses

### UC-2: Developers can throw semantically correct exceptions for common HTTP statuses

When a developer needs to express Conflict (409), Gone (410), Content Too Large (413), Locked (423), or Service
Unavailable (503), they can throw dedicated toolkit exception classes that produce the correct HTTP status code with
the toolkit's structured error format.

**Acceptance criteria:**

- New exception classes are added, each extending `ApiException` with `CODE` and `HTTP_STATUS` constants:
    - `ConflictException` -- HTTP 409, error code `ErrorCode::CONFLICT`
    - `GoneException` -- HTTP 410, error code `ErrorCode::GONE`
    - `PayloadTooLargeException` -- HTTP 413, error code `ErrorCode::PAYLOAD_TOO_LARGE`
    - `LockedException` -- HTTP 423, error code `ErrorCode::LOCKED`
    - `ServiceUnavailableException` -- HTTP 503, error code `ErrorCode::SERVICE_UNAVAILABLE`
- Each exception class follows the existing pattern (~20 lines, extends `ApiException`, defines constants)
- New `ErrorCode` enum cases are added in the HTTP Errors category (10108+)
- Translation keys are added for each new error code (`api-toolkit::exceptions.{code}.{title|detail}`)
- These classes are for **intentional use by developers** -- they are not auto-mapped from application-layer exceptions

### UC-3: All Symfony HttpException subclasses produce correct status codes

When any Symfony `HttpException` subclass is thrown (whether directly, via middleware, or via `abort()`), the response
uses the correct HTTP status code rather than falling through to 500.

**Acceptance criteria:**

- `BadRequestHttpException` maps to the existing `BadRequestException` (400)
- `ServiceUnavailableHttpException` maps to the new `ServiceUnavailableException` (503)
- `PostTooLargeException` (Laravel's 413 exception) maps to the new `PayloadTooLargeException` (413)
- All other Symfony `HttpException` subclasses (406, 409, 410, 411, 412, 415, 422, 423, 428) are caught by the generic
  `HttpExceptionInterface` catch-all (UC-1) and produce the correct status code
- Specific branches are added before the generic catch-all only where a dedicated toolkit `ApiException` subclass
  exists and provides a more specific error code than the generic `HTTP_ERROR`

---

## Out of Scope

- **Database exception mapping:** `UniqueConstraintViolationException`, `QueryException`, `DeadlockException`,
  `LostConnectionException`, and all other database exceptions remain unmapped (500). These are application-layer
  errors that indicate unhandled conditions. If a developer wants a specific status code, they should catch the
  exception in their application code and throw the appropriate toolkit exception (e.g., `ConflictException`).
- **Error code extensibility documentation (P6):** Documenting conventions for application-defined error codes is
  deferred. The technical mechanism already works.
- **Transient database error surfacing:** Whether to expose deadlocks or connection losses as 503 to API consumers is
  deferred. These are infrastructure concerns, not HTTP semantics.

---

## New Classes

| Class                         | Namespace                          | Purpose                                                                         |
|-------------------------------|------------------------------------|---------------------------------------------------------------------------------|
| `ConflictException`           | `SineMacula\ApiToolkit\Exceptions` | HTTP 409 -- resource conflict (for intentional use by developers)               |
| `GoneException`               | `SineMacula\ApiToolkit\Exceptions` | HTTP 410 -- resource permanently removed                                        |
| `PayloadTooLargeException`    | `SineMacula\ApiToolkit\Exceptions` | HTTP 413 -- request body exceeds size limit                                     |
| `LockedException`             | `SineMacula\ApiToolkit\Exceptions` | HTTP 423 -- resource is locked                                                  |
| `ServiceUnavailableException` | `SineMacula\ApiToolkit\Exceptions` | HTTP 503 -- service temporarily unavailable (general, not maintenance-specific) |

---

## New ErrorCode Cases

| Case                  | Value | Category                                 |
|-----------------------|-------|------------------------------------------|
| `CONFLICT`            | 10108 | HTTP Errors                              |
| `GONE`                | 10109 | HTTP Errors                              |
| `PAYLOAD_TOO_LARGE`   | 10110 | HTTP Errors                              |
| `LOCKED`              | 10111 | HTTP Errors                              |
| `SERVICE_UNAVAILABLE` | 10112 | HTTP Errors                              |
| `HTTP_ERROR`          | 10113 | HTTP Errors (generic, for the catch-all) |

---

## Modified Classes

| Class                 | Change                                                                                                                                                                                                                                                                                                                                                                 |
|-----------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `ApiExceptionHandler` | Add mapper branches: `BadRequestHttpException` -> `BadRequestException`, `ServiceUnavailableHttpException` -> `ServiceUnavailableException`, `PostTooLargeException` -> `PayloadTooLargeException`. Add generic `HttpExceptionInterface` catch-all as the last branch before `default`. The catch-all produces a dynamic response preserving the original status code. |
| `ErrorCode`           | Add 6 new enum cases: `CONFLICT`, `GONE`, `PAYLOAD_TOO_LARGE`, `LOCKED`, `SERVICE_UNAVAILABLE`, `HTTP_ERROR`                                                                                                                                                                                                                                                           |
| `ApiException`        | Add support for runtime status code override (needed for the generic `HttpException` catch-all). This may require a new subclass or a constructor parameter.                                                                                                                                                                                                           |

---

## Mapper Branch Order

The `match(true)` block should be ordered from most specific to most general:

```
1.  NotFoundHttpException           -> NotFoundException             (404)
2.  BackedEnumCaseNotFoundException -> NotFoundException             (404)
3.  ModelNotFoundException          -> NotFoundException             (404)
4.  SuspiciousOperationException    -> NotFoundException             (404)
5.  RecordsNotFoundException        -> NotFoundException             (404)
6.  MethodNotAllowedHttpException   -> NotAllowedException           (405)
7.  BadRequestHttpException         -> BadRequestException           (400)  [NEW]
8.  RequestExceptionInterface       -> BadRequestException           (400)
9.  LaravelUnauthorizedException    -> ForbiddenException            (403)
10. AuthorizationException          -> ForbiddenException            (403)
11. AccessDeniedHttpException       -> ForbiddenException            (403)
12. AuthenticationException         -> UnauthenticatedException      (401)
13. LaravelTokenMismatchException   -> TokenMismatchException        (419)
14. ValidationException             -> InvalidInputException         (422)
15. TooManyRequestsHttpException    -> TooManyRequestsException      (429)
16. ServiceUnavailableHttpException -> ServiceUnavailableException   (503)  [NEW]
17. PostTooLargeException           -> PayloadTooLargeException      (413)  [NEW]
18. HttpExceptionInterface          -> dynamic status preservation   (*)   [NEW catch-all]
19. default                         -> UnhandledException            (500)
```

Note: No database exception branches. `UniqueConstraintViolationException`, `QueryException`, etc. fall through to
`default` -> `UnhandledException` (500) by design.

---

## Success Metrics

| Metric                         | Baseline | Target          | Measurement                                 |
|--------------------------------|----------|-----------------|---------------------------------------------|
| `abort(409)` status code       | 500      | 409             | Test: abort(409) produces 409 JSON response |
| `abort(503)` status code       | 500      | 503             | Test: abort(503) produces 503 JSON response |
| `abort(423)` status code       | 500      | 423             | Test: abort(423) produces 423 JSON response |
| BadRequestHttpException status | 500      | 400             | Test: BadRequestHttpException produces 400  |
| QueryException status          | 500      | 500 (unchanged) | Test: QueryException still produces 500     |
| Existing mappings unchanged    | Correct  | Correct         | Test: all existing test assertions pass     |

---

## Testing Strategy

- **Unit tests for each new exception class:** Verify `CODE`, `HTTP_STATUS`, `getInternalErrorCode()`,
  `getHttpStatusCode()`, translation key resolution.
- **Unit tests for new mapper branches:**
    - `BadRequestHttpException` -> 400 with BadRequestException error code
    - `ServiceUnavailableHttpException` -> 503 with ServiceUnavailableException error code
    - `PostTooLargeException` -> 413 with PayloadTooLargeException error code
- **Unit tests for the generic HttpException catch-all:**
    - `abort(409)` -> 409 response with toolkit JSON format
    - `abort(423)` -> 423 response
    - `abort(451)` -> 451 response
    - `HttpException` with custom headers -> headers preserved in response
- **Unit tests confirming application-layer exceptions remain 500:**
    - `UniqueConstraintViolationException` -> 500 (not mapped to 409)
    - `QueryException` -> 500
    - Generic `\RuntimeException` -> 500
- **Regression tests:** All existing mapper branch tests pass without modification
- **Integration tests:** Full request lifecycle with `abort()` calls producing correct status codes

---

## References

- Prioritization: .sinemacula/blueprint/workflows/exception-handler-coverage/prioritization.md
- Problem Map: .sinemacula/blueprint/workflows/exception-handler-coverage/problem-map.md
- Spike: .sinemacula/blueprint/workflows/exception-handler-coverage/spikes/spike-exception-coverage-gaps.md
- Intake Brief: .sinemacula/blueprint/workflows/exception-handler-coverage/intake-brief.md
- Source: ISSUES.md (ISSUE-13)
