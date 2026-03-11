# Scanner Output: Complexity

Scanned 6 files for complexity threshold violations against pack.toml thresholds.

---

## Governance

| Field     | Value                                                          |
|-----------|----------------------------------------------------------------|
| Created   | 2026-03-10                                                     |
| Category  | Complexity                                                     |
| Owned by  | Scanner                                                        |
| Traces to | Scope: SSE extraction -- Emitter, EventStream, Controller, tests, fixture overrides |

---

## Findings

| # | File | Method / Class | Metric | Current Value | Threshold | Severity | Fix-Risk Tier |
|---|------|----------------|--------|---------------|-----------|----------|---------------|
| 1 | src/Sse/EventStream.php | `toResponse()` | Signature length | 143 | 120 | Medium | guided-fix |
| 2 | src/Http/Routing/Controller.php | `respondWithItem()` | Signature length | 127 | 120 | Medium | guided-fix |
| 3 | src/Http/Routing/Controller.php | `respondWithCollection()` | Signature length | 143 | 120 | Medium | guided-fix |
| 4 | src/Http/Routing/Controller.php | `respondWithEventStream()` | Signature length | 155 | 120 | Medium | guided-fix |

---

## Summary

| Metric          | Count |
|-----------------|-------|
| Total findings  | 4     |
| High severity   | 0     |
| Medium severity | 4     |
| Low severity    | 0     |
| Auto-Fix        | 0     |
| Guided-Fix      | 4     |
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
| 9 | Attestation valid    | N/A -- assigned rule source has no rule identifiers |

---

## Coverage Attestation

N/A -- assigned rule source has no rule identifiers.

---

## References

- Category: Complexity
- Rule source: `/Users/ben/.claude/plugins/cache/sinemacula/build/0.2.11/packs/php/pack.toml` section `[thresholds]`
- Pack config: `/Users/ben/.claude/plugins/cache/sinemacula/build/0.2.11/packs/php/pack.toml`
- Traces to: Scope -- SSE extraction files (Emitter, EventStream, Controller, tests, fixture overrides)
