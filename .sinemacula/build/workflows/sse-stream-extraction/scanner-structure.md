# Scanner Output: Structure

Scanned 6 files for structure rule compliance against the PHP language pack structural rules.

---

## Governance

| Field     | Value                                                          |
|-----------|----------------------------------------------------------------|
| Created   | 2026-03-11                                                     |
| Category  | Structure                                                      |
| Owned by  | Scanner                                                        |
| Traces to | Scope: SSE stream extraction -- Emitter, EventStream, Controller, tests, function overrides |

---

## Findings

| # | File | Issue | Anti-Pattern | Recommended Fix | Rule ID | Severity | Fix-Risk Tier |
|---|------|-------|--------------|-----------------|---------|----------|---------------|
| 1 | `src/Sse/EventStream.php` (line 56) | Blank line after opening brace of `toResponse` method body on line 55; method and function bodies must not have a blank line after the opening brace | Method body padded with blank line after opening brace | Remove the blank line on line 56 | php-str-006 | Medium | guided-fix |
| 2 | `src/Sse/EventStream.php` (line 146) | Multi-line `catch (\Throwable $e)` block on line 146 has no blank line after opening brace; body contains an `if` block and a `continue` statement (4 lines of content) | Multi-line control block missing blank-line padding after opening brace | Add blank line after the opening brace of the `catch` block on line 146 | php-str-008 | Medium | guided-fix |
| 3 | `src/Sse/EventStream.php` (line 156) | Multi-line `if` block on line 156 has no blank line after opening brace; body contains 2 statements (`$emitter->comment()` and `$heartbeatTimestamp = now()`) | Multi-line control block missing blank-line padding after opening brace | Add blank line after the opening brace of the `if` block on line 156 | php-str-008 | Medium | guided-fix |
| 4 | `tests/Fixtures/Overrides/functions.php` (line 29) | Multi-line `if ($override !== null)` block in `ob_flush` (Http\Concerns namespace) has no blank line after opening brace; body contains 2 statements (`$override()` and `return`) | Multi-line control block missing blank-line padding after opening brace | Add blank line after the opening brace on line 29 | php-str-008 | Low | guided-fix |
| 5 | `tests/Fixtures/Overrides/functions.php` (line 46) | Multi-line `if ($override !== null)` block in `flush` (Http\Concerns namespace) has no blank line after opening brace; body contains 2 statements (`$override()` and `return`) | Multi-line control block missing blank-line padding after opening brace | Add blank line after the opening brace on line 46 | php-str-008 | Low | guided-fix |
| 6 | `tests/Fixtures/Overrides/functions.php` (line 110) | Multi-line `if ($override !== null)` block in `ob_flush` (Http\Routing namespace) has no blank line after opening brace; body contains 2 statements (`$override()` and `return`) | Multi-line control block missing blank-line padding after opening brace | Add blank line after the opening brace on line 110 | php-str-008 | Low | guided-fix |
| 7 | `tests/Fixtures/Overrides/functions.php` (line 129) | Multi-line `if ($override !== null)` block in `flush` (Http\Routing namespace) has no blank line after opening brace; body contains 2 statements (`$override()` and `return`) | Multi-line control block missing blank-line padding after opening brace | Add blank line after the opening brace on line 129 | php-str-008 | Low | guided-fix |
| 8 | `tests/Fixtures/Overrides/functions.php` (line 192) | Multi-line `if ($override !== null)` block in `flush` (Sse namespace) has no blank line after opening brace; body contains 2 statements (`$override()` and `return`) | Multi-line control block missing blank-line padding after opening brace | Add blank line after the opening brace on line 192 | php-str-008 | Low | guided-fix |
| 9 | `tests/Fixtures/Overrides/functions.php` (line 212) | Multi-line `if ($override !== null)` block in `ob_flush` (Sse namespace) has no blank line after opening brace; body contains 2 statements (`$override()` and `return`) | Multi-line control block missing blank-line padding after opening brace | Add blank line after the opening brace on line 212 | php-str-008 | Low | guided-fix |

---

## Summary

| Metric          | Count |
|-----------------|-------|
| Total findings  | 9     |
| High severity   | 0     |
| Medium severity | 3     |
| Low severity    | 6     |
| Auto-Fix        | 0     |
| Guided-Fix      | 9     |
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

All 9 gates pass. All 6 files were scanned against all 9 structure rules. Every finding has file path, line number, rule ID, severity, and fix-risk tier. All findings are scoped to the structure category. The template structure is followed with no placeholder text. Coverage attestation lists all 9 rule identifiers matching the manifest count of 9.

---

## Coverage Attestation

| # | Rule ID     | Status    | Notes |
|---|-------------|-----------|-------|
| 1 | php-str-001 | evaluated | All method and function signatures checked; all single-line signatures are the default |
| 2 | php-str-002 | evaluated | Constructor with promoted property in `EventStream.php` line 30 is correctly multi-line |
| 3 | php-str-003 | evaluated | Multi-line `toResponse` signature (145 chars with short names) exceeds 120-char threshold; permitted. Controller signatures exceed threshold but remain single-line per default (php-str-001); no rule mandates multi-line for long signatures |
| 4 | php-str-004 | evaluated | No signatures wrapped for visual preference or alignment without promoted properties |
| 5 | php-str-005 | evaluated | All signatures within the 120-char threshold are single-line |
| 6 | php-str-006 | evaluated | 1 violation found: `EventStream.php` `toResponse` method body has blank line after opening brace |
| 7 | php-str-007 | evaluated | All single-statement control blocks have no blank line after opening brace |
| 8 | php-str-008 | evaluated | 8 violations found: 2 in `EventStream.php` (catch block, if block), 6 in `functions.php` (if blocks with 2 statements) |
| 9 | php-str-009 | evaluated | Statement grouping is consistent across all files; no violations found |

| Metric                  | Count |
|-------------------------|-------|
| Total rules in manifest | 9     |
| Evaluated               | 9     |
| Not evaluated           | 0     |

---

## References

- Category: Structure
- Rule source: `/Users/ben/.claude/plugins/cache/sinemacula/build/0.2.11/packs/php/structure.md`
- Pack config: `/Users/ben/.claude/plugins/cache/sinemacula/build/0.2.11/packs/php/pack.toml`
- Traces to: Scope -- SSE stream extraction task files (Emitter, EventStream, Controller, tests, function overrides)
