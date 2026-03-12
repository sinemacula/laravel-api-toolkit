# Scanner Output: Analysis

Scanned 6 files for analysis rule compliance (PHP native attributes, static analysis directives).

---

## Governance

| Field     | Value                                                         |
|-----------|---------------------------------------------------------------|
| Created   | 2026-03-11                                                    |
| Category  | Analysis                                                      |
| Owned by  | Scanner                                                       |
| Traces to | Scope: SSE stream extraction -- Emitter, EventStream, Controller, tests, function overrides |

---

## Findings

| # | File | Line | Rule ID | Issue | Severity | Fix-Risk Tier |
|---|------|------|---------|-------|----------|---------------|
| 1 | tests/Unit/Sse/EventStreamTest.php | 384 | php-ana-004 | Method `handleStreamError` in anonymous class overrides `EventStream::handleStreamError` but is missing the `#[\Override]` attribute | Medium | detect-only |
| 2 | tests/Unit/Sse/EventStreamTest.php | 415 | php-ana-004 | Method `onStreamStart` in anonymous class overrides `EventStream::onStreamStart` but is missing the `#[\Override]` attribute | Medium | detect-only |
| 3 | tests/Unit/Sse/EventStreamTest.php | 445 | php-ana-004 | Method `onStreamEnd` in anonymous class overrides `EventStream::onStreamEnd` but is missing the `#[\Override]` attribute | Medium | detect-only |

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
| 1 | All files scanned    | pass   |
| 2 | Issue location       | pass   |
| 3 | Rule reference       | pass   |
| 4 | Severity assigned    | pass   |
| 5 | Tier annotated       | pass   |
| 6 | Category scoped      | pass   |
| 7 | Template followed    | pass   |
| 8 | No placeholders      | pass   |
| 9 | Attestation valid    | pass   |

---

## Coverage Attestation

| # | Rule ID | Status | Notes |
|---|---------|--------|-------|
| 1 | php-ana-001 | evaluated | Process-level rule (static analysis mandatory); no per-file violations found |
| 2 | php-ana-002 | evaluated | Process-level rule (violations are defects); no per-file violations found |
| 3 | php-ana-003 | evaluated | Process-level rule (self-remediation first); no per-file violations found |
| 4 | php-ana-004 | evaluated | 3 violations found in EventStreamTest.php anonymous classes missing `#[\Override]` |
| 5 | php-ana-005 | evaluated | No parameters representing secrets found in any scanned file |
| 6 | php-ana-006 | evaluated | No `@deprecated` docblocks found in any scanned file |
| 7 | php-ana-007 | evaluated | No dynamic property usage found in any scanned file |
| 8 | php-ana-008 | evaluated | Existing attributes in EmitterTest.php and EventStreamTest.php are correctly placed |
| 9 | php-ana-009 | evaluated | No attributes replace or remove existing docblocks in any scanned file |
| 10 | php-ana-010 | evaluated | All scanned files are task-touched; no out-of-scope attribute additions found |

| Metric                  | Count |
|-------------------------|-------|
| Total rules in manifest | 10    |
| Evaluated               | 10    |
| Not evaluated           | 0     |

---

## References

- Category: Analysis
- Rule source: `/Users/ben/.claude/plugins/cache/sinemacula/build/0.2.11/packs/php/analysis.md`
- Pack config: `/Users/ben/.claude/plugins/cache/sinemacula/build/0.2.11/packs/php/pack.toml`
- Traces to: Scope -- SSE stream extraction workflow, 6 files scanned
