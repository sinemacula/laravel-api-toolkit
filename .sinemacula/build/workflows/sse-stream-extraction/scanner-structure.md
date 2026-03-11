# Scanner Output: Structure

Scanned 6 files (3 source, 2 test, 1 fixture) against 9 structural rules from the PHP language pack.

---

## Governance

| Field     | Value |
|-----------|-------|
| Created   | 2026-03-10 |
| Category  | Structure |
| Owned by  | Scanner |
| Traces to | Scope: SSE Stream Extraction changed files |

---

## Findings

| # | File | Issue | Anti-Pattern | Recommended Fix | Rule ID | Severity | Fix-Risk Tier |
|---|------|-------|--------------|-----------------|---------|----------|---------------|
| 1 | src/Sse/EventStream.php:50 | `toResponse` signature is 141 chars (threshold: 120) and is on a single line with no promoted properties | Single-line signature exceeding max_signature_length | Wrap signature to multi-line | php-str-003 | Medium | guided-fix |
| 2 | src/Http/Routing/Controller.php:49 | `respondWithItem` signature is 130 chars (threshold: 120) and is on a single line with no promoted properties | Single-line signature exceeding max_signature_length | Wrap signature to multi-line | php-str-003 | Medium | guided-fix |
| 3 | src/Http/Routing/Controller.php:62 | `respondWithCollection` signature is 144 chars (threshold: 120) and is on a single line with no promoted properties | Single-line signature exceeding max_signature_length | Wrap signature to multi-line | php-str-003 | Medium | guided-fix |
| 4 | src/Http/Routing/Controller.php:79 | `respondWithEventStream` signature is 156 chars (threshold: 120) and is on a single line with no promoted properties | Single-line signature exceeding max_signature_length | Wrap signature to multi-line | php-str-003 | Medium | guided-fix |
| 5 | src/Sse/EventStream.php:141 | `catch (\Throwable $e)` block contains 2 statements (if-block + continue) but has no blank line after opening brace | Multi-line control block without blank line after opening brace | Add blank line after opening brace of catch block | php-str-008 | Low | auto-fix |
| 6 | tests/Fixtures/Overrides/functions.php:29 | `if ($override !== null)` block (ob_flush in Http\Concerns) contains 2 statements but has no blank line after opening brace | Multi-line control block without blank line after opening brace | Add blank line after opening brace | php-str-008 | Low | auto-fix |
| 7 | tests/Fixtures/Overrides/functions.php:47 | `if ($override !== null)` block (flush in Http\Concerns) contains 2 statements but has no blank line after opening brace | Multi-line control block without blank line after opening brace | Add blank line after opening brace | php-str-008 | Low | auto-fix |
| 8 | tests/Fixtures/Overrides/functions.php:110 | `if ($override !== null)` block (ob_flush in Http\Routing) contains 2 statements but has no blank line after opening brace | Multi-line control block without blank line after opening brace | Add blank line after opening brace | php-str-008 | Low | auto-fix |
| 9 | tests/Fixtures/Overrides/functions.php:129 | `if ($override !== null)` block (flush in Http\Routing) contains 2 statements but has no blank line after opening brace | Multi-line control block without blank line after opening brace | Add blank line after opening brace | php-str-008 | Low | auto-fix |
| 10 | tests/Fixtures/Overrides/functions.php:191 | `if ($override !== null)` block (flush in Sse) contains 2 statements but has no blank line after opening brace | Multi-line control block without blank line after opening brace | Add blank line after opening brace | php-str-008 | Low | auto-fix |
| 11 | tests/Fixtures/Overrides/functions.php:211 | `if ($override !== null)` block (ob_flush in Sse) contains 2 statements but has no blank line after opening brace | Multi-line control block without blank line after opening brace | Add blank line after opening brace | php-str-008 | Low | auto-fix |

---

## Summary

| Metric | Count |
|--------|-------|
| Total findings | 11 |
| High severity | 0 |
| Medium severity | 4 |
| Low severity | 7 |
| Auto-Fix | 7 |
| Guided-Fix | 4 |
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
| 1 | php-str-001 | evaluated | |
| 2 | php-str-002 | evaluated | |
| 3 | php-str-003 | evaluated | |
| 4 | php-str-004 | evaluated | |
| 5 | php-str-005 | evaluated | |
| 6 | php-str-006 | evaluated | |
| 7 | php-str-007 | evaluated | |
| 8 | php-str-008 | evaluated | |
| 9 | php-str-009 | evaluated | |

| Metric | Count |
|--------|-------|
| Total rules in manifest | 9 |
| Evaluated | 9 |
| Not evaluated | 0 |

---

## References

- Category: Structure
- Rule source: `/Users/ben/.claude/plugins/cache/sinemacula/build/0.2.11/packs/php/structure.md`
- Pack config: `/Users/ben/.claude/plugins/cache/sinemacula/build/0.2.11/packs/php/pack.toml`
- Traces to: Scope -- SSE Stream Extraction changed files
