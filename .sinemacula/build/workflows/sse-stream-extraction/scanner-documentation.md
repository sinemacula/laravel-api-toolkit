# Scanner Output: Documentation

Scanned 6 files against PHP documentation rules.

---

## Governance

| Field     | Value |
|-----------|-------|
| Created   | 2026-03-10 |
| Category  | Documentation |
| Owned by  | Scanner |
| Traces to | Scope: SSE Stream Extraction changed files |

---

## Findings

| # | File | Line | Rule ID | Issue | Severity | Fix-Risk Tier |
|---|------|------|---------|-------|----------|---------------|
| 1 | src/Http/Routing/Controller.php | 25 | php-doc-011, php-doc-016 | Constant `HEARTBEAT_INTERVAL` docblock is `/** The SSE heartbeat interval in seconds. */` but is missing the required `@var int` tag; constants must use `/** @var Type description */` format | Medium | auto-fix |
| 2 | src/Sse/Emitter.php | 19 | php-doc-031 | Class docblock includes `@copyright` tag but `[project].copyright` is not configured in `.sinemacula/config.toml`; when only `@author` is available, include `@author` only | Low | auto-fix |
| 3 | src/Sse/EventStream.php | 21 | php-doc-031 | Class docblock includes `@copyright` tag but `[project].copyright` is not configured in `.sinemacula/config.toml`; when only `@author` is available, include `@author` only | Low | auto-fix |
| 4 | src/Http/Routing/Controller.php | 19 | php-doc-031 | Class docblock includes `@copyright` tag but `[project].copyright` is not configured in `.sinemacula/config.toml`; when only `@author` is available, include `@author` only | Low | auto-fix |
| 5 | tests/Unit/Sse/EmitterTest.php | 14 | php-doc-031 | Class docblock includes `@copyright` tag but `[project].copyright` is not configured in `.sinemacula/config.toml`; when only `@author` is available, include `@author` only | Low | auto-fix |
| 6 | tests/Unit/Sse/EventStreamTest.php | 16 | php-doc-031 | Class docblock includes `@copyright` tag but `[project].copyright` is not configured in `.sinemacula/config.toml`; when only `@author` is available, include `@author` only | Low | auto-fix |

---

## Summary

| Metric | Count |
|--------|-------|
| Total findings | 6 |
| High severity | 0 |
| Medium severity | 1 |
| Low severity | 5 |
| Auto-Fix | 6 |
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
| 1 | php-doc-001 | evaluated | All public classes and methods have docblocks |
| 2 | php-doc-002 | evaluated | All docblocks follow PHPDoc format |
| 3 | php-doc-003 | evaluated | Docblocks document intent, not mechanics |
| 4 | php-doc-004 | evaluated | @author and @copyright tags present; @copyright flagged as config mismatch |
| 5 | php-doc-005 | evaluated | All types in docblocks are fully qualified |
| 6 | php-doc-006 | evaluated | No @inheritDoc usage found |
| 7 | php-doc-007 | evaluated | Doc comment line lengths checked; no violations |
| 8 | php-doc-008 | evaluated | @param/@return lines under 120 characters; no @formatter directives needed |
| 9 | php-doc-009 | evaluated | All classes have concise descriptions; @author/@copyright tag presence checked |
| 10 | php-doc-010 | evaluated | All methods have @param, @return, and @throws tags as needed |
| 11 | php-doc-011 | evaluated | Constant HEARTBEAT_INTERVAL missing @var tag; finding #1 |
| 12 | php-doc-012 | evaluated | Constructor-promoted property heartbeat_interval has single-line doc block |
| 13 | php-doc-013 | evaluated | No facades in scope |
| 14 | php-doc-014 | evaluated | Properties and constants checked for single-line @var format |
| 15 | php-doc-015 | evaluated | No multiline property/constant docblocks found |
| 16 | php-doc-016 | evaluated | @var tag mandatory; HEARTBEAT_INTERVAL constant missing it; finding #1 |
| 17 | php-doc-017 | evaluated | No unnecessary multiline property/constant docblocks |
| 18 | php-doc-018 | evaluated | Promoted property has purpose-describing doc block |
| 19 | php-doc-019 | evaluated | Blank line after opening parenthesis in EventStream constructor |
| 20 | php-doc-020 | evaluated | Only one promoted property; spacing rule satisfied |
| 21 | php-doc-021 | evaluated | Blank line before closing parenthesis in EventStream constructor |
| 22 | php-doc-022 | evaluated | No mixed promoted/regular parameters in scope |
| 23 | php-doc-023 | evaluated | No configuration section banners in scope |
| 24 | php-doc-024 | evaluated | No configuration section banners in scope |
| 25 | php-doc-025 | evaluated | No configuration section banners in scope |
| 26 | php-doc-026 | evaluated | No enums in scope |
| 27 | php-doc-027 | evaluated | No enums in scope |
| 28 | php-doc-028 | evaluated | @author values match config.local.toml |
| 29 | php-doc-029 | evaluated | @copyright config not present in config.toml; findings #2-6 |
| 30 | php-doc-030 | evaluated | Not applicable; copyright not available |
| 31 | php-doc-031 | evaluated | Copyright unavailable but included in 5 class docblocks; findings #2-6 |
| 32 | php-doc-032 | evaluated | Not applicable; author is available |
| 33 | php-doc-033 | evaluated | Not applicable; author is available |
| 34 | php-doc-034 | evaluated | Copyright field absent from config.toml treated as unavailable |
| 35 | php-doc-035 | evaluated | @author/@copyright tags only on class-level docblocks |
| 36 | php-doc-036 | evaluated | Tags added to task-touched files only |
| 37 | php-doc-037 | evaluated | No # comments found; all inline comments use // format |
| 38 | php-doc-038 | evaluated | Inline comments are rare and purposeful |
| 39 | php-doc-039 | evaluated | No narrating comments found |

| Metric | Count |
|--------|-------|
| Total rules in manifest | 39 |
| Evaluated | 39 |
| Not evaluated | 0 |
