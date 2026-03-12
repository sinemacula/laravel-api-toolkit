# Scanner Output: Naming

Scanned 6 files (3 source, 2 test, 1 fixture) against 40 naming rules from the PHP language pack.

---

## Governance

| Field     | Value                                                          |
|-----------|----------------------------------------------------------------|
| Created   | 2026-03-11                                                     |
| Category  | Naming                                                         |
| Owned by  | Scanner                                                        |
| Traces to | Scope: SSE extraction -- Emitter, EventStream, Controller, tests, function overrides |

---

## Findings

| # | File | Line | Current Name | Expected Name | Rule ID | Severity | Fix-Risk Tier |
|---|------|------|--------------|---------------|---------|----------|---------------|
| 1 | src/Sse/EventStream.php | 146 | `$e` | `$exception` | php-nam-037 | Medium | auto-fix |

---

## Summary

| Metric          | Count |
|-----------------|-------|
| Total findings  | 1     |
| High severity   | 0     |
| Medium severity | 1     |
| Low severity    | 0     |
| Auto-Fix        | 1     |
| Guided-Fix      | 0     |
| Detect-Only     | 0     |

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
| 9 | Attestation valid    | Pass   |

---

## Coverage Attestation

| # | Rule ID | Status | Notes |
|---|---------|--------|-------|
| 1 | php-nam-001 | evaluated | |
| 2 | php-nam-002 | evaluated | |
| 3 | php-nam-003 | evaluated | |
| 4 | php-nam-004 | evaluated | |
| 5 | php-nam-005 | evaluated | |
| 6 | php-nam-006 | evaluated | |
| 7 | php-nam-007 | evaluated | |
| 8 | php-nam-008 | evaluated | |
| 9 | php-nam-009 | evaluated | |
| 10 | php-nam-010 | evaluated | |
| 11 | php-nam-011 | evaluated | |
| 12 | php-nam-012 | evaluated | |
| 13 | php-nam-013 | evaluated | |
| 14 | php-nam-014 | evaluated | |
| 15 | php-nam-015 | evaluated | |
| 16 | php-nam-016 | evaluated | |
| 17 | php-nam-017 | evaluated | |
| 18 | php-nam-018 | evaluated | |
| 19 | php-nam-019 | evaluated | |
| 20 | php-nam-020 | evaluated | |
| 21 | php-nam-021 | evaluated | |
| 22 | php-nam-022 | evaluated | |
| 23 | php-nam-023 | evaluated | |
| 24 | php-nam-024 | evaluated | |
| 25 | php-nam-025 | evaluated | |
| 26 | php-nam-026 | evaluated | |
| 27 | php-nam-027 | evaluated | |
| 28 | php-nam-028 | evaluated | |
| 29 | php-nam-029 | evaluated | |
| 30 | php-nam-030 | evaluated | |
| 31 | php-nam-031 | evaluated | |
| 32 | php-nam-032 | evaluated | |
| 33 | php-nam-033 | evaluated | |
| 34 | php-nam-034 | evaluated | |
| 35 | php-nam-035 | evaluated | |
| 36 | php-nam-036 | evaluated | |
| 37 | php-nam-037 | evaluated | |
| 38 | php-nam-038 | evaluated | |
| 39 | php-nam-039 | evaluated | |
| 40 | php-nam-040 | evaluated | |

| Metric                  | Count |
|-------------------------|-------|
| Total rules in manifest | 40    |
| Evaluated               | 40    |
| Not evaluated           | 0     |

---

## References

- Category: Naming
- Rule source: `/Users/ben/.claude/plugins/cache/sinemacula/build/0.2.11/packs/php/naming.md`
- Pack config: `/Users/ben/.claude/plugins/cache/sinemacula/build/0.2.11/packs/php/pack.toml`
- Traces to: Scope -- SSE extraction file set (6 files)
