# Scanner Output: Styling

Scanned 6 files (3 source, 2 test, 1 fixture) against 17 casing rules from the PHP language pack.

---

## Governance

| Field     | Value |
|-----------|-------|
| Created   | 2026-03-10 |
| Category  | Styling |
| Owned by  | Scanner |
| Traces to | Scope: SSE Stream Extraction changed files |

---

## Findings

| # | File | Line | Current Name | Expected Name | Rule ID | Severity | Fix-Risk Tier |
|---|------|------|--------------|---------------|---------|----------|---------------|
| 1 | src/Sse/EventStream.php | 33 | `$heartbeat_interval` | `$heartbeatInterval` | php-sty-004, php-sty-011 | Medium | auto-fix |
| 2 | src/Sse/EventStream.php | 59 | `$accepts_emitter` | `$acceptsEmitter` | php-sty-012 | Medium | auto-fix |
| 3 | src/Sse/EventStream.php | 131 | `$heartbeat_timestamp` | `$heartbeatTimestamp` | php-sty-012 | Medium | auto-fix |
| 4 | tests/Unit/Sse/EmitterTest.php | 144 | `$flush_called` | `$flushCalled` | php-sty-012 | Medium | auto-fix |
| 5 | tests/Unit/Sse/EventStreamTest.php | 71 | `$cache_control` | `$cacheControl` | php-sty-012 | Medium | auto-fix |
| 6 | tests/Unit/Sse/EventStreamTest.php | 119 | `$abort_count` | `$abortCount` | php-sty-012 | Medium | auto-fix |
| 7 | tests/Unit/Sse/EventStreamTest.php | 125 | `$callback_ran` | `$callbackRan` | php-sty-012 | Medium | auto-fix |
| 8 | tests/Unit/Sse/EventStreamTest.php | 180 | `$comment_count` | `$commentCount` | php-sty-012 | Medium | auto-fix |
| 9 | tests/Unit/Sse/EventStreamTest.php | 225 | `$call_count` | `$callCount` | php-sty-012 | Medium | auto-fix |
| 10 | tests/Unit/Sse/EventStreamTest.php | 254 | `$received_emitter` | `$receivedEmitter` | php-sty-012 | Medium | auto-fix |
| 11 | tests/Unit/Sse/EventStreamTest.php | 281 | `$args_received` | `$argsReceived` | php-sty-012 | Medium | auto-fix |

---

## Summary

| Metric | Count |
|--------|-------|
| Total findings | 11 |
| High severity | 0 |
| Medium severity | 11 |
| Low severity | 0 |
| Auto-Fix | 11 |
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
| 1 | php-sty-001 | evaluated | |
| 2 | php-sty-002 | evaluated | |
| 3 | php-sty-003 | evaluated | |
| 4 | php-sty-004 | evaluated | |
| 5 | php-sty-005 | evaluated | |
| 6 | php-sty-006 | evaluated | |
| 7 | php-sty-007 | evaluated | |
| 8 | php-sty-008 | evaluated | |
| 9 | php-sty-009 | evaluated | |
| 10 | php-sty-010 | evaluated | |
| 11 | php-sty-011 | evaluated | |
| 12 | php-sty-012 | evaluated | |
| 13 | php-sty-013 | evaluated | |
| 14 | php-sty-014 | evaluated | |
| 15 | php-sty-015 | evaluated | |
| 16 | php-sty-016 | evaluated | |
| 17 | php-sty-017 | evaluated | |

| Metric | Count |
|--------|-------|
| Total rules in manifest | 17 |
| Evaluated | 17 |
| Not evaluated | 0 |

---

## References

- Category: Styling
- Rule source: `/Users/ben/.claude/plugins/cache/sinemacula/build/0.2.11/packs/php/styling.md`
- Pack config: `/Users/ben/.claude/plugins/cache/sinemacula/build/0.2.11/packs/php/pack.toml`
- Traces to: Scope -- SSE Stream Extraction changed files
