# Scanner Output: Testing

Scanned 6 files for testing rule compliance: `src/Sse/Emitter.php`, `src/Sse/EventStream.php`, `src/Http/Routing/Controller.php`, `tests/Unit/Sse/EmitterTest.php`, `tests/Unit/Sse/EventStreamTest.php`, and `tests/Fixtures/Overrides/functions.php`.

---

## Governance

| Field     | Value                                                       |
|-----------|-------------------------------------------------------------|
| Created   | 2026-03-11                                                  |
| Category  | Testing                                                     |
| Owned by  | Scanner                                                     |
| Traces to | Scope: SSE stream extraction -- 6 files (3 source, 3 test) |

---

## Findings

No testing issues found.

---

## Summary

| Metric          | Count |
|-----------------|-------|
| Total findings  | 0     |
| High severity   | 0     |
| Medium severity | 0     |
| Low severity    | 0     |
| Auto-Fix        | 0     |
| Guided-Fix      | 0     |
| Detect-Only     | 0     |

---

## Quality Gate

| # | Gate                 | Result |
|---|----------------------|--------|
| 1 | All files scanned    | pass   |
| 2 | Issue location       | pass   |
| 3 | Rule reference       | pass   |
| 4 | Severity assigned    | pass   |
| 5 | Tier annotated       | pass   |
| 6 | Category scoped      | pass   |
| 7 | Template followed    | pass   |
| 8 | No placeholders      | pass   |
| 9 | Attestation valid    | pass   |

All 6 files were scanned. Zero findings, so gates 2-5 are vacuously satisfied. 55 rule identifiers extracted from the assigned rule source; 55 attestation rows produced (55 evaluated, 0 not-evaluated), matching the manifest count of 55. No cross-category findings. Template structure matches scanner.template.md. No placeholder text remains.

---

## Coverage Attestation

| # | Rule ID | Status | Notes |
|---|---------|--------|-------|
| 1 | php-tst-001 | evaluated | `Emitter` has 2 public methods (`emit`, `comment`), both tested in `EmitterTest` (9 test methods). `EventStream` has 1 public method (`toResponse`), tested in `EventStreamTest` (16 test methods). `Controller::respondWithEventStream` is protected and tested in existing `ControllerTest` (out of scan scope but confirmed present). `functions.php` contains test-support overrides, not production logic requiring tests. |
| 2 | php-tst-002 | evaluated | Test names in both test classes describe behaviour clearly; a reader can understand SSE event emission and stream lifecycle from the tests alone. |
| 3 | php-tst-003 | evaluated | Tests assert on output format and behavioural contracts (SSE wire format, header values, callback execution, abort detection), not on internal implementation details. |
| 4 | php-tst-004 | evaluated | Each test method asserts one logical concept (e.g., single-line emit, multiline split, JSON encoding, flush call, header presence). |
| 5 | php-tst-005 | evaluated | Tests are independent; `setUp` creates fresh state, and `tearDown` in base `TestCase` calls `FunctionOverrides::reset()`. No test depends on another's state or order. |
| 6 | php-tst-006 | evaluated | Time is controlled via `travelTo(now())` and `travel()->seconds()`. External functions (`flush`, `connection_aborted`, `sleep`, `ob_flush`, `ob_get_level`) are intercepted via namespace-scoped overrides. No real APIs are called. |
| 7 | php-tst-007 | evaluated | No over-mocking observed; namespace-scoped function overrides are a minimal, targeted approach to intercepting built-in PHP functions. |
| 8 | php-tst-008 | evaluated | Test infrastructure is appropriately split: `FunctionOverrides` registry in its own class, namespace-scoped overrides in `functions.php`. |
| 9 | php-tst-009 | evaluated | Tests follow Arrange/Act/Assert: set up overrides and objects, execute the method, then assert on output or state. |
| 10 | php-tst-010 | evaluated | All test methods use `test{DescriptionInCamelCase}` naming format. |
| 11 | php-tst-011 | evaluated | Test names describe behaviour (e.g., `testEmitWritesSingleLineDataWithTerminator`, `testStreamBreaksOnConnectionAborted`). |
| 12 | php-tst-012 | evaluated | Edge cases are included (e.g., empty comment, multiline data, array data, no-parameter callback, second abort check path). |
| 13 | php-tst-013 | evaluated | Failure scenarios named appropriately (e.g., `testStreamEmitsErrorEventWhenCallbackThrows`). |
| 14 | php-tst-014 | evaluated | Tests use `test` prefix method naming rather than `#[Test]` attribute; both approaches are acceptable per the rule. No legacy `@test` annotations present. |
| 15 | php-tst-015 | evaluated | No data providers are used; no `@dataProvider` annotations present. Not applicable to these tests. |
| 16 | php-tst-016 | evaluated | Both test classes use `#[CoversClass(...)]` attribute (modern form). |
| 17 | php-tst-017 | evaluated | No repeated scenario matrices are present that would benefit from data providers. |
| 18 | php-tst-018 | evaluated | No data providers present; rule not applicable to these tests. |
| 19 | php-tst-019 | evaluated | Only PHPUnit attributes supported by the current version are used (`CoversClass`). |
| 20 | php-tst-020 | evaluated | `@internal` is used on both test classes, which is meaningful (test classes are not public API). |
| 21 | php-tst-021 | evaluated | `EmitterTest` and `EventStreamTest` are in `tests/Unit/` and test classes in isolation with mocked dependencies (namespace-scoped function overrides). No I/O or framework services beyond the test harness. |
| 22 | php-tst-022 | evaluated | No integration-level tests in scope; not applicable to these files. |
| 23 | php-tst-023 | evaluated | No feature-level tests in scope; not applicable to these files. |
| 24 | php-tst-024 | evaluated | Tests are placed in `tests/Unit/Sse/`, matching the lowest-level suite that validates the behaviour. |
| 25 | php-tst-025 | evaluated | Unit tests mock/stub all dependencies via namespace-scoped `FunctionOverrides`; classes are tested in isolation. |
| 26 | php-tst-026 | evaluated | No feature tests in scope; rule not applicable. |
| 27 | php-tst-027 | evaluated | Controller tests exist in `ControllerTest.php` (outside scan scope but verified present) covering status codes, response structure, SSE headers, and custom headers for `respondWithEventStream`. |
| 28 | php-tst-028 | evaluated | Not applicable; no service classes in scope. |
| 29 | php-tst-029 | evaluated | Not applicable; no model classes in scope. |
| 30 | php-tst-030 | evaluated | Not applicable; no repository classes in scope. |
| 31 | php-tst-031 | evaluated | Not applicable; no job classes in scope. |
| 32 | php-tst-032 | evaluated | Not applicable; no event/listener classes in scope. |
| 33 | php-tst-033 | evaluated | Not applicable; no validation classes in scope. |
| 34 | php-tst-034 | evaluated | External functions are intercepted via namespace-scoped overrides (equivalent to fakes for built-in PHP functions). |
| 35 | php-tst-035 | evaluated | Neither `Emitter` nor `EventStream` is mocked in their own test class; only external dependencies are intercepted. |
| 36 | php-tst-036 | evaluated | Function override count per test is within limits; these are lightweight namespace-scoped function stubs, not object mocks with complex expectations. |
| 37 | php-tst-037 | evaluated | Coverage percentage cannot be measured by static analysis; both public methods of `Emitter` and the public method of `EventStream` are thoroughly tested with multiple scenarios. |
| 38 | php-tst-038 | evaluated | Not applicable; no payment, authentication, or authorisation paths in scope. |
| 39 | php-tst-039 | evaluated | Tests contain meaningful assertions (exact output comparison via `assertSame`, boolean state verification, instance type checks), not just line coverage. |
| 40 | php-tst-040 | evaluated | `functions.php` contains test-support overrides (framework boilerplate equivalent); no explicit tests are needed for it. `EventStream` constructor is a simple property promotion; tested implicitly through `toResponse`. |
| 41 | php-tst-041 | evaluated | No DTO round-trip tests in scope; not applicable. |
| 42 | php-tst-042 | evaluated | No ValueObject make tests in scope; not applicable. |
| 43 | php-tst-043 | evaluated | No ValueObject from tests in scope; not applicable. |
| 44 | php-tst-044 | evaluated | No data providers present; rule not applicable. |
| 45 | php-tst-045 | evaluated | Exception/error behaviour is tested (e.g., `testStreamEmitsErrorEventWhenCallbackThrows`, `testHandleStreamErrorIsOverridableBySubclass`). |
| 46 | php-tst-046 | evaluated | No DTO classes in scope; not applicable. |
| 47 | php-tst-047 | evaluated | No ValueObject classes in scope; not applicable. |
| 48 | php-tst-048 | evaluated | Each test sets up its own state via `setUp` and inline overrides; no execution order dependency. |
| 49 | php-tst-049 | evaluated | No private methods are tested directly. `runEventStream` and `flushOutput` (private in `EventStream`) are exercised through the public `toResponse` method. |
| 50 | php-tst-050 | evaluated | Every test contains explicit assertions (`assertSame`, `assertTrue`, `assertFalse`, `assertInstanceOf`, `assertStringContainsString`, `assertStringStartsWith`, `assertGreaterThanOrEqual`, etc.). |
| 51 | php-tst-051 | evaluated | Time is controlled via `travelTo(now())` and `travel()->seconds()` (Carbon test clock), not hard-coded dates. |
| 52 | php-tst-052 | evaluated | Tests verify the SSE classes' behaviour (wire format, headers, lifecycle hooks, error handling), not Laravel/Symfony internals. |
| 53 | php-tst-053 | evaluated | Assertions target outcomes (output content, boolean flags, call counts), not implementation details. |
| 54 | php-tst-054 | evaluated | Tests are grouped by behaviour (emit string, emit array, emit with event, comment, flush, stream lifecycle, heartbeat, abort detection, error handling, hook overrides), not 1:1 method mirroring. |
| 55 | php-tst-055 | evaluated | No shared mutable state between tests; `setUp` creates a fresh `Emitter` and sets overrides; base `TestCase::tearDown` calls `FunctionOverrides::reset()`. |

| Metric                  | Count |
|-------------------------|-------|
| Total rules in manifest | 55    |
| Evaluated               | 55    |
| Not evaluated           | 0     |

---

## References

- Category: Testing
- Rule source: `/Users/ben/.claude/plugins/cache/sinemacula/build/0.2.11/packs/php/testing.md`
- Pack config: `/Users/ben/.claude/plugins/cache/sinemacula/build/0.2.11/packs/php/pack.toml`
- Traces to: Scope -- SSE stream extraction, 6 files (3 source, 3 test)
