# Scanner Output: Code Quality

Scanned 6 files for code quality issues against references/code-quality.md.

---

## Governance

| Field     | Value                                                        |
|-----------|--------------------------------------------------------------|
| Created   | 2026-03-11                                                   |
| Category  | Code Quality                                                 |
| Owned by  | Scanner                                                      |
| Traces to | Scope: SSE extraction -- Emitter, EventStream, Controller, tests, function overrides |

---

## Findings

| # | File | Line | Rule ID | Issue | Severity | Fix-Risk Tier |
|---|------|------|---------|-------|----------|---------------|
| 1 | tests/Unit/Sse/EmitterTest.php | 21 | What NOT to Document -- Repeating type hints | `@var \SineMacula\ApiToolkit\Sse\Emitter` docblock restates the type already declared on the typed property `private Emitter $emitter`; the variable name and type are self-explanatory with no additional description provided | Medium | detect-only |
| 2 | src/Sse/Emitter.php | 6 | AI Slop Patterns -- Inflated docblock descriptions | Class docblock contains approximately 5 sentences across 3 paragraphs; the third paragraph (lines 12-16) discusses namespace-scoped flush resolution and PHP-FPM output buffering configuration, which are implementation and deployment details rather than a purpose statement; guideline is 1-3 sentences | Medium | detect-only |
| 3 | src/Sse/EventStream.php | 9 | AI Slop Patterns -- Inflated docblock descriptions | Class docblock contains approximately 5 sentences across 3 paragraphs; the third paragraph (lines 15-18) discusses SAPI-specific behavior for PHP-FPM, CLI, and Octane, which are runtime environment details rather than a purpose statement; guideline is 1-3 sentences | Medium | detect-only |

---

## Summary

| Metric          | Count |
|-----------------|-------|
| Total findings  | 3     |
| High severity   | 0     |
| Medium severity | 3     |
| Low severity    | 0     |
| Auto-Fix        | 0     |
| Guided-Fix      | 0     |
| Detect-Only     | 3     |

---

## Quality Gate

| # | Gate                 | Result |
|---|----------------------|--------|
| 1 | All files scanned    | Pass   |
| 2 | Issue location       | Pass   |
| 3 | Rule reference       | Pass   |
| 4 | Severity assigned    | Pass   |
| 5 | Tier annotated       | Pass   |
| 6 | Category scoped      | Pass   |
| 7 | Template followed    | Pass   |
| 8 | No placeholders      | Pass   |
| 9 | Attestation valid    | N/A -- assigned rule source has no rule identifiers |

---

## Coverage Attestation

N/A -- assigned rule source has no rule identifiers.

---

## References

- Category: Code Quality
- Rule source: `/Users/ben/.claude/plugins/cache/sinemacula/build/0.2.11/references/code-quality.md`
- Pack config: `/Users/ben/.claude/plugins/cache/sinemacula/build/0.2.11/packs/php/pack.toml`
- Traces to: Scope -- SSE extraction (Emitter, EventStream, Controller, tests, function overrides)
