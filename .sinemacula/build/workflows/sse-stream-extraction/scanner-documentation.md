# Scanner Output: Documentation

Scanned 6 files for documentation rule compliance against the PHP documentation language pack rules.

---

## Governance

| Field     | Value                                                        |
|-----------|--------------------------------------------------------------|
| Created   | 2026-03-11                                                   |
| Category  | Documentation                                                |
| Owned by  | Scanner                                                      |
| Traces to | Scope: SSE stream extraction -- Emitter, EventStream, Controller, tests, function overrides |

---

## Findings

| # | File | Line | Rule ID | Issue | Severity | Fix-Risk Tier |
|---|------|------|---------|-------|----------|---------------|
| 1 | src/Http/Routing/Controller.php | 26 | php-doc-011, php-doc-016 | Constant `HEARTBEAT_INTERVAL` doc block is `/** The SSE heartbeat interval in seconds. */` but is missing the required `@var int` tag; constants must use `/** @var int The SSE heartbeat interval in seconds. */` format | Medium | auto-fix |

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
| 1 | php-doc-001 | evaluated | All public classes and methods have docblocks |
| 2 | php-doc-002 | evaluated | All docblocks follow PHPDoc format |
| 3 | php-doc-003 | evaluated | Docblocks describe intent, not mechanics |
| 4 | php-doc-004 | evaluated | @author and @copyright tags present on all class docblocks |
| 5 | php-doc-005 | evaluated | All types in docblocks are fully qualified |
| 6 | php-doc-006 | evaluated | No @inheritDoc usage found |
| 7 | php-doc-007 | evaluated | All doc comment lines within 80-character wrap |
| 8 | php-doc-008 | evaluated | No @param/@return/@throws lines exceed 120 characters |
| 9 | php-doc-009 | evaluated | All named classes have concise title, @author, @copyright |
| 10 | php-doc-010 | evaluated | All methods and functions have @param, @return, @throws as needed |
| 11 | php-doc-011 | evaluated | Constant HEARTBEAT_INTERVAL missing single-line @var format; finding #1 |
| 12 | php-doc-012 | evaluated | Constructor-promoted property in EventStream has single-line doc block |
| 13 | php-doc-013 | evaluated | No facades or magic APIs in scoped files |
| 14 | php-doc-014 | evaluated | All properties and constants have doc blocks present |
| 15 | php-doc-015 | evaluated | No unwarranted multiline property/constant doc blocks |
| 16 | php-doc-016 | evaluated | Constant HEARTBEAT_INTERVAL missing @var tag; finding #1 |
| 17 | php-doc-017 | evaluated | Single-line format used as default for all properties/constants |
| 18 | php-doc-018 | evaluated | Promoted property has purpose-describing single-line doc block |
| 19 | php-doc-019 | evaluated | Blank line after opening ( in EventStream constructor |
| 20 | php-doc-020 | evaluated | Only one promoted property; spacing between pairs not triggered |
| 21 | php-doc-021 | evaluated | Blank line before closing ) in EventStream constructor |
| 22 | php-doc-022 | evaluated | No mixed promoted/regular constructor parameters in scope |
| 23 | php-doc-023 | evaluated | No configuration section banners in scoped files |
| 24 | php-doc-024 | evaluated | No configuration section banners in scoped files |
| 25 | php-doc-025 | evaluated | No configuration section banners in scoped files |
| 26 | php-doc-026 | evaluated | No enums in scoped files |
| 27 | php-doc-027 | evaluated | No enums in scoped files |
| 28 | php-doc-028 | evaluated | @author tags match provided author value |
| 29 | php-doc-029 | evaluated | @copyright tags match provided copyright value |
| 30 | php-doc-030 | evaluated | Both @author and @copyright available; both included on all class docblocks |
| 31 | php-doc-031 | evaluated | Both available; conditional rule not triggered |
| 32 | php-doc-032 | evaluated | Both available; conditional rule not triggered |
| 33 | php-doc-033 | evaluated | Both available; conditional rule not triggered |
| 34 | php-doc-034 | evaluated | Both config values are non-empty |
| 35 | php-doc-035 | evaluated | @author/@copyright only on class-level docblocks, not methods |
| 36 | php-doc-036 | evaluated | Tags present on modified classes within task scope |
| 37 | php-doc-037 | evaluated | All inline comments use // format; no # comments found |
| 38 | php-doc-038 | evaluated | Inline comments are rare and contextually justified |
| 39 | php-doc-039 | evaluated | No narration of obvious code behaviour |

| Metric                  | Count |
|-------------------------|-------|
| Total rules in manifest | 39    |
| Evaluated               | 39    |
| Not evaluated           | 0     |

---

## References

- Category: Documentation
- Rule source: `/Users/ben/.claude/plugins/cache/sinemacula/build/0.2.11/packs/php/documentation.md`
- Pack config: `/Users/ben/.claude/plugins/cache/sinemacula/build/0.2.11/packs/php/pack.toml`
- Traces to: Scope -- SSE stream extraction (Emitter, EventStream, Controller, tests, function overrides)
