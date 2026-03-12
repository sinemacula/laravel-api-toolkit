# Scanner Output: Styling

Scanned 6 files against PHP casing convention rules from the Styling category.

---

## Governance

| Field     | Value                                                  |
|-----------|--------------------------------------------------------|
| Created   | 2026-03-11                                             |
| Category  | Styling                                                |
| Owned by  | Scanner                                                |
| Traces to | Scope: SSE stream extraction -- 6 files in scan scope  |

---

## Findings

No styling issues found.

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

All 6 files were scanned: `src/Sse/Emitter.php`, `src/Sse/EventStream.php`, `src/Http/Routing/Controller.php`, `tests/Unit/Sse/EmitterTest.php`, `tests/Unit/Sse/EventStreamTest.php`, `tests/Fixtures/Overrides/functions.php`. Zero findings means gates 2-5 are vacuously satisfied. All output is category-scoped to Styling. Template structure matches scanner.template.md. No placeholder text remains. Coverage attestation contains 17 rows matching the 17 rule identifiers extracted from the rule source file and the manifest count in pack.toml.

---

## Coverage Attestation

| #  | Rule ID     | Status    | Notes |
|----|-------------|-----------|-------|
| 1  | php-sty-001 | evaluated |       |
| 2  | php-sty-002 | evaluated |       |
| 3  | php-sty-003 | evaluated |       |
| 4  | php-sty-004 | evaluated |       |
| 5  | php-sty-005 | evaluated |       |
| 6  | php-sty-006 | evaluated |       |
| 7  | php-sty-007 | evaluated |       |
| 8  | php-sty-008 | evaluated |       |
| 9  | php-sty-009 | evaluated |       |
| 10 | php-sty-010 | evaluated |       |
| 11 | php-sty-011 | evaluated |       |
| 12 | php-sty-012 | evaluated |       |
| 13 | php-sty-013 | evaluated |       |
| 14 | php-sty-014 | evaluated |       |
| 15 | php-sty-015 | evaluated |       |
| 16 | php-sty-016 | evaluated |       |
| 17 | php-sty-017 | evaluated |       |

| Metric                  | Count |
|-------------------------|-------|
| Total rules in manifest | 17    |
| Evaluated               | 17    |
| Not evaluated           | 0     |

---

## References

- Category: Styling
- Rule source: `/Users/ben/.claude/plugins/cache/sinemacula/build/0.2.11/packs/php/styling.md`
- Pack config: `/Users/ben/.claude/plugins/cache/sinemacula/build/0.2.11/packs/php/pack.toml`
- Traces to: Scope -- SSE stream extraction, 6 files scanned against 17 PHP casing convention rules
