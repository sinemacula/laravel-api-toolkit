# Enforcement Report

6 files scanned against the PHP language pack (v0.3.0) across 8 scanner categories plus linter and formatter tool checks.

---

## Governance

| Field      | Value                                          |
|------------|-------------------------------------------------|
| Created    | 2026-03-11                                     |
| Pack       | php                                            |
| Version    | 0.3.0                                          |
| Files      | 6                                              |
| Categories | 8                                              |
| Owned by   | Pipeline                                       |
| Traces to  | sse-stream-extraction (standalone re-scan)     |

---

## Summary

| Category      | Total | High | Medium | Low | Auto-Fix | Guided-Fix | Detect-Only |
|---------------|-------|------|--------|-----|----------|------------|-------------|
| Naming        | 1     | 0    | 1      | 0   | 1        | 0          | 0           |
| Styling       | 0     | 0    | 0      | 0   | 0        | 0          | 0           |
| Structure     | 9     | 0    | 3      | 6   | 0        | 9          | 0           |
| Testing       | 0     | 0    | 0      | 0   | 0        | 0          | 0           |
| Analysis      | 3     | 0    | 3      | 0   | 0        | 0          | 3           |
| Documentation | 1     | 0    | 1      | 0   | 1        | 0          | 0           |
| Complexity    | 4     | 0    | 4      | 0   | 0        | 4          | 0           |
| Code Quality  | 3     | 0    | 3      | 0   | 0        | 0          | 3           |
| Linter        | 0     | 0    | 0      | 0   | 0        | 0          | 0           |
| Formatter     | --    | --   | --     | --  | --       | --         | --          |
| **Total**     | **21**| **0**| **15** |**6**| **2**    | **13**     | **6**       |

---

## Naming Findings

| # | File | Line | Current Name | Expected Name | Rule ID | Severity | Fix-Risk Tier |
|---|------|------|--------------|---------------|---------|----------|---------------|
| 1 | src/Sse/EventStream.php | 146 | `$e` | `$exception` | php-nam-037 | Medium | auto-fix |

---

## Styling Findings

No styling findings.

---

## Structure Findings

| # | File | Issue | Anti-Pattern | Recommended Fix | Rule ID | Severity | Fix-Risk Tier |
|---|------|-------|--------------|-----------------|---------|----------|---------------|
| 1 | src/Sse/EventStream.php (line 56) | Blank line after opening brace of `toResponse` method body | Method body padded with blank line after opening brace | Remove the blank line on line 56 | php-str-006 | Medium | guided-fix |
| 2 | src/Sse/EventStream.php (line 146) | Multi-line `catch` block missing blank line after opening brace; body contains `if` block and `continue` (4 lines) | Multi-line control block missing blank-line padding | Add blank line after opening brace of `catch` block | php-str-008 | Medium | guided-fix |
| 3 | src/Sse/EventStream.php (line 156) | Multi-line `if` block missing blank line after opening brace; body contains 2 statements | Multi-line control block missing blank-line padding | Add blank line after opening brace of `if` block | php-str-008 | Medium | guided-fix |
| 4 | tests/Fixtures/Overrides/functions.php (line 29) | `if ($override !== null)` block in `ob_flush` (Http\Concerns) missing blank line after opening brace | Multi-line control block missing blank-line padding | Add blank line after opening brace | php-str-008 | Low | guided-fix |
| 5 | tests/Fixtures/Overrides/functions.php (line 46) | `if ($override !== null)` block in `flush` (Http\Concerns) missing blank line after opening brace | Multi-line control block missing blank-line padding | Add blank line after opening brace | php-str-008 | Low | guided-fix |
| 6 | tests/Fixtures/Overrides/functions.php (line 110) | `if ($override !== null)` block in `ob_flush` (Http\Routing) missing blank line after opening brace | Multi-line control block missing blank-line padding | Add blank line after opening brace | php-str-008 | Low | guided-fix |
| 7 | tests/Fixtures/Overrides/functions.php (line 129) | `if ($override !== null)` block in `flush` (Http\Routing) missing blank line after opening brace | Multi-line control block missing blank-line padding | Add blank line after opening brace | php-str-008 | Low | guided-fix |
| 8 | tests/Fixtures/Overrides/functions.php (line 192) | `if ($override !== null)` block in `flush` (Sse) missing blank line after opening brace | Multi-line control block missing blank-line padding | Add blank line after opening brace | php-str-008 | Low | guided-fix |
| 9 | tests/Fixtures/Overrides/functions.php (line 212) | `if ($override !== null)` block in `ob_flush` (Sse) missing blank line after opening brace | Multi-line control block missing blank-line padding | Add blank line after opening brace | php-str-008 | Low | guided-fix |

---

## Testing Findings

No testing findings.

---

## Analysis Findings

| # | File | Line | Rule ID | Issue | Severity | Fix-Risk Tier |
|---|------|------|---------|-------|----------|---------------|
| 1 | tests/Unit/Sse/EventStreamTest.php | 384 | php-ana-004 | Method `handleStreamError` in anonymous class overrides `EventStream::handleStreamError` but is missing the `#[\Override]` attribute | Medium | detect-only |
| 2 | tests/Unit/Sse/EventStreamTest.php | 415 | php-ana-004 | Method `onStreamStart` in anonymous class overrides `EventStream::onStreamStart` but is missing the `#[\Override]` attribute | Medium | detect-only |
| 3 | tests/Unit/Sse/EventStreamTest.php | 445 | php-ana-004 | Method `onStreamEnd` in anonymous class overrides `EventStream::onStreamEnd` but is missing the `#[\Override]` attribute | Medium | detect-only |

---

## Documentation Findings

| # | File | Line | Rule ID | Issue | Severity | Fix-Risk Tier |
|---|------|------|---------|-------|----------|---------------|
| 1 | src/Http/Routing/Controller.php | 26 | php-doc-011, php-doc-016 | Constant `HEARTBEAT_INTERVAL` doc block is missing the required `@var int` tag; should be `/** @var int The SSE heartbeat interval in seconds. */` | Medium | auto-fix |

---

## Complexity Findings

| # | File | Method / Class | Metric | Current Value | Threshold | Severity | Fix-Risk Tier |
|---|------|----------------|--------|---------------|-----------|----------|---------------|
| 1 | src/Sse/EventStream.php | EventStream::toResponse | Signature length | 142 chars | 120 chars | Medium | guided-fix |
| 2 | src/Http/Routing/Controller.php | Controller::respondWithItem | Signature length | 131 chars | 120 chars | Medium | guided-fix |
| 3 | src/Http/Routing/Controller.php | Controller::respondWithCollection | Signature length | 145 chars | 120 chars | Medium | guided-fix |
| 4 | src/Http/Routing/Controller.php | Controller::respondWithEventStream | Signature length | 158 chars | 120 chars | Medium | guided-fix |

---

## Code Quality Findings

| # | File | Line | Rule ID | Issue | Severity | Fix-Risk Tier |
|---|------|------|---------|-------|----------|---------------|
| 1 | tests/Unit/Sse/EmitterTest.php | 21 | Repeating type hints | `@var \SineMacula\ApiToolkit\Sse\Emitter` docblock restates the type already declared on the typed property `private Emitter $emitter` without additional description | Medium | detect-only |
| 2 | src/Sse/Emitter.php | 6 | Inflated docblock descriptions | Class docblock contains ~5 sentences across 3 paragraphs; third paragraph discusses namespace-scoped flush resolution and PHP-FPM output buffering configuration (implementation/deployment details, not purpose) | Medium | detect-only |
| 3 | src/Sse/EventStream.php | 9 | Inflated docblock descriptions | Class docblock contains ~5 sentences across 3 paragraphs; third paragraph discusses SAPI-specific behavior for PHP-FPM, CLI, and Octane (runtime details, not purpose) | Medium | detect-only |

---

## Tool Results

### Linter

PHPStan reported 6 warnings when analysing files in isolation, all of the form "No error to ignore is reported on line N" -- these are false positives caused by `@phpstan-ignore` annotations whose target errors only manifest during full-project analysis. `composer check` passes cleanly with zero issues. No actual linter violations.

### Formatter

Formatter check not run -- `php-cs-fixer` requires project-level invocation via `composer check` when scanning multiple files. `composer check` passes cleanly.

---

## Quality Gate

### Per-Scanner Results

| Scanner       | G1   | G2   | G3   | G4   | G5   | G6   | G7   | G8   | G9   | Result |
|---------------|------|------|------|------|------|------|------|------|------|--------|
| Naming        | pass | pass | pass | pass | pass | pass | pass | pass | pass | pass   |
| Styling       | pass | pass | pass | pass | pass | pass | pass | pass | pass | pass   |
| Structure     | pass | pass | pass | pass | pass | pass | pass | pass | pass | pass   |
| Testing       | pass | pass | pass | pass | pass | pass | pass | pass | pass | pass   |
| Analysis      | pass | pass | pass | pass | pass | pass | pass | pass | pass | pass   |
| Documentation | pass | pass | pass | pass | pass | pass | pass | pass | pass | pass   |
| Complexity    | pass | pass | pass | pass | pass | pass | pass | pass | N/A  | pass   |
| Code Quality  | pass | pass | pass | pass | pass | pass | pass | pass | N/A  | pass   |

### Aggregate Gate

| # | Gate                       | Result |
|---|----------------------------|--------|
| 1 | All scanners completed     | pass   |
| 2 | All per-scanner gates pass | pass   |
| 3 | Summary counts verified    | pass   |

---

## Coverage Attestation

### Per-File Attestation

#### naming.md (php-nam)

| Rule ID | Status | Notes |
|---------|--------|-------|
| php-nam-001 through php-nam-040 | evaluated | All 40 rules evaluated |

#### styling.md (php-sty)

| Rule ID | Status | Notes |
|---------|--------|-------|
| php-sty-001 through php-sty-017 | evaluated | All 17 rules evaluated |

#### structure.md (php-str)

| Rule ID | Status | Notes |
|---------|--------|-------|
| php-str-001 through php-str-009 | evaluated | All 9 rules evaluated |

#### testing.md (php-tst)

| Rule ID | Status | Notes |
|---------|--------|-------|
| php-tst-001 through php-tst-055 | evaluated | All 55 rules evaluated |

#### analysis.md (php-ana)

| Rule ID | Status | Notes |
|---------|--------|-------|
| php-ana-001 through php-ana-010 | evaluated | All 10 rules evaluated |

#### documentation.md (php-doc)

| Rule ID | Status | Notes |
|---------|--------|-------|
| php-doc-001 through php-doc-039 | evaluated | All 39 rules evaluated |

#### Complexity

N/A -- assigned rule source has no rule identifiers.

#### Code Quality

N/A -- assigned rule source has no rule identifiers.

### Attestation Summary

| File | Manifest Count | Evaluated | Not Evaluated | Coverage |
|------|----------------|-----------|---------------|----------|
| naming.md | 40 | 40 | 0 | 100% |
| styling.md | 17 | 17 | 0 | 100% |
| structure.md | 9 | 9 | 0 | 100% |
| testing.md | 55 | 55 | 0 | 100% |
| analysis.md | 10 | 10 | 0 | 100% |
| documentation.md | 39 | 39 | 0 | 100% |
| **Total** | **170** | **170** | **0** | **100%** |

---

## Metadata

| Metric               | Value                            |
|----------------------|----------------------------------|
| Pipeline invocation  | Standalone re-scan               |
| Pack                 | php v0.3.0                       |
| Files scanned        | 6                                |
| Categories evaluated | 8                                |
| Tool checks run      | 1 (linter)                       |
| Total findings       | 21                               |
| Quality gate         | pass                             |

---

## References

- Pack: `/Users/ben/.claude/plugins/cache/sinemacula/build/0.2.11/packs/php/pack.toml`
- Scanner instructions: `/Users/ben/.claude/plugins/cache/sinemacula/build/0.2.11/skills/enforce/scanner.instructions.md`
- Scanner template: `/Users/ben/.claude/plugins/cache/sinemacula/build/0.2.11/skills/enforce/scanner.template.md`
- Traces to: sse-stream-extraction (standalone re-scan)
