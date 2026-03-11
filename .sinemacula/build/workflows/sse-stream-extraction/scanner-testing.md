# Scanner Output: Testing

Scanned 6 files (2 test files, 1 fixture file, 3 source files) against PHP testing rules.

---

## Governance

| Field     | Value |
|-----------|-------|
| Created   | 2026-03-10 |
| Category  | Testing |
| Owned by  | Scanner |
| Traces to | Scope: SSE Stream Extraction changed files |

---

## Findings

No testing issues found.

---

## Summary

| Metric | Count |
|--------|-------|
| Total findings | 0 |
| High severity | 0 |
| Medium severity | 0 |
| Low severity | 0 |
| Auto-Fix | 0 |
| Guided-Fix | 0 |
| Detect-Only | 0 |

---

## Quality Gate

| # | Gate | Result |
|---|------|--------|
| 1 | All files scanned | pass |
| 2 | Issue location | pass |
| 3 | Rule reference | pass |
| 4 | Severity assigned | pass |
| 5 | Tier annotated | pass |
| 6 | Category scoped | pass |
| 7 | Template followed | pass |
| 8 | No placeholders | pass |
| 9 | Attestation valid | pass |

---

## Coverage Attestation

| # | Rule ID | Status | Notes |
|---|---------|--------|-------|
| 1 | php-tst-001 | evaluated | All public methods in Emitter (emit, comment) and EventStream (toResponse) have tests |
| 2 | php-tst-002 | evaluated | Tests describe behaviour clearly through method names and docblocks |
| 3 | php-tst-003 | evaluated | Tests assert behaviour (output, header values) not implementation details |
| 4 | php-tst-004 | evaluated | Each test asserts one logical concept |
| 5 | php-tst-005 | evaluated | Tests are independent; setUp() creates fresh state |
| 6 | php-tst-006 | evaluated | Time is controlled via Carbon travelTo; external services are not hit; flush/sleep/connection_aborted are overridden |
| 7 | php-tst-007 | evaluated | No over-mocking observed |
| 8 | php-tst-008 | evaluated | Fixtures are split into dedicated files (FunctionOverrides, functions.php) |
| 9 | php-tst-009 | evaluated | Tests follow AAA pattern |
| 10 | php-tst-010 | evaluated | All test methods use testDescriptionInCamelCase format |
| 11 | php-tst-011 | evaluated | Methods describe behaviour |
| 12 | php-tst-012 | evaluated | Edge cases included (multiline data, empty comment, array data) |
| 13 | php-tst-013 | evaluated | Failure scenarios named (testStreamEmitsErrorEventWhenCallbackThrows) |
| 14 | php-tst-014 | evaluated | No legacy @test annotations used |
| 15 | php-tst-015 | evaluated | No data providers used; not applicable |
| 16 | php-tst-016 | evaluated | CoversClass attribute used on both test classes |
| 17 | php-tst-017 | evaluated | No repeated scenario matrices requiring data providers |
| 18 | php-tst-018 | evaluated | No data providers to check |
| 19 | php-tst-019 | evaluated | Only PHPUnit attributes supported by current version are used |
| 20 | php-tst-020 | evaluated | @internal used meaningfully on test classes |
| 21 | php-tst-021 | evaluated | Unit tests have no I/O, no framework dependency beyond TestCase; flush/sleep mocked |
| 22 | php-tst-022 | evaluated | No integration-level tests in scope |
| 23 | php-tst-023 | evaluated | No feature-level tests in scope |
| 24 | php-tst-024 | evaluated | Tests are placed in Unit suite appropriately |
| 25 | php-tst-025 | evaluated | Unit tests mock all external dependencies via namespace overrides |
| 26 | php-tst-026 | evaluated | No feature tests to check |
| 27 | php-tst-027 | evaluated | Controller is not directly tested here; SSE response construction tested via EventStream |
| 28 | php-tst-028 | evaluated | Not applicable; no service classes in scope |
| 29 | php-tst-029 | evaluated | Not applicable; no models in scope |
| 30 | php-tst-030 | evaluated | Not applicable; no repositories in scope |
| 31 | php-tst-031 | evaluated | Not applicable; no jobs in scope |
| 32 | php-tst-032 | evaluated | Not applicable; no events/listeners in scope |
| 33 | php-tst-033 | evaluated | Not applicable; no validation in scope |
| 34 | php-tst-034 | evaluated | External services mocked via namespace-scoped function overrides |
| 35 | php-tst-035 | evaluated | Neither test mocks the class under test |
| 36 | php-tst-036 | evaluated | Mock count is within limits |
| 37 | php-tst-037 | evaluated | Coverage assessment requires runtime; tests cover all public methods |
| 38 | php-tst-038 | evaluated | Not applicable; no critical auth/payment paths in scope |
| 39 | php-tst-039 | evaluated | All tests have meaningful assertions |
| 40 | php-tst-040 | evaluated | No untestable boilerplate exemptions needed |
| 41 | php-tst-041 | evaluated | No DTO round-trip providers in scope |
| 42 | php-tst-042 | evaluated | No ValueObject make providers in scope |
| 43 | php-tst-043 | evaluated | No ValueObject from providers in scope |
| 44 | php-tst-044 | evaluated | No data providers to check |
| 45 | php-tst-045 | evaluated | No data providers to check |
| 46 | php-tst-046 | evaluated | No DTOs in scope |
| 47 | php-tst-047 | evaluated | No ValueObjects in scope |
| 48 | php-tst-048 | evaluated | No test depends on execution order |
| 49 | php-tst-049 | evaluated | No private methods tested directly |
| 50 | php-tst-050 | evaluated | All tests have explicit assertions |
| 51 | php-tst-051 | evaluated | Time controlled via Carbon travelTo/travel |
| 52 | php-tst-052 | evaluated | Tests do not test framework internals |
| 53 | php-tst-053 | evaluated | No overly specific mock expectations |
| 54 | php-tst-054 | evaluated | Tests grouped by behaviour, not 1:1 method mapping |
| 55 | php-tst-055 | evaluated | setUp() creates fresh state; no shared mutable state |

| Metric | Count |
|--------|-------|
| Total rules in manifest | 55 |
| Evaluated | 55 |
| Not evaluated | 0 |
