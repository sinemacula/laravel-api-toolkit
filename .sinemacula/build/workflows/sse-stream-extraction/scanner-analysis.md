# Scanner Output: Analysis

Scanned 6 files against PHP static analysis rules.

---

## Governance

| Field     | Value |
|-----------|-------|
| Created   | 2026-03-10 |
| Category  | Analysis |
| Owned by  | Scanner |
| Traces to | Scope: SSE Stream Extraction changed files |

---

## Findings

No analysis issues found.

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
| 1 | php-ana-001 | evaluated | All files scanned; static analysis compliance is a project-level gate |
| 2 | php-ana-002 | evaluated | No violations found to evaluate resolution requirement |
| 3 | php-ana-003 | evaluated | No violations to self-remediate |
| 4 | php-ana-004 | evaluated | #[\Override] used correctly on setUp() in EmitterTest (line 29) and EventStreamTest (line 32); handleStreamError and onStreamStart/onStreamEnd overrides in anonymous test classes are in test fixtures and not subject to attribute enrichment on task-touched production files |
| 5 | php-ana-005 | evaluated | No sensitive parameters in any scoped file |
| 6 | php-ana-006 | evaluated | No @deprecated docblocks or deprecation policy in scope |
| 7 | php-ana-007 | evaluated | No #[\AllowDynamicProperties] usage |
| 8 | php-ana-008 | evaluated | Attributes placed correctly above declarations |
| 9 | php-ana-009 | evaluated | Attributes do not replace docblocks in any scoped file |
| 10 | php-ana-010 | evaluated | Attribute additions limited to task-touched files |

| Metric | Count |
|--------|-------|
| Total rules in manifest | 10 |
| Evaluated | 10 |
| Not evaluated | 0 |
