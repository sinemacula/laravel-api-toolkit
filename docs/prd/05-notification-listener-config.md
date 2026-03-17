# PRD: Notification Listener Configuration

Fix the `NotificationListener` log levels (`debug` for sending, `info` for sent) and add a notification class exclusion
list, allowing consumers to filter out noise without losing the comprehensive audit-by-default behavior.

---

## Governance

| Field     | Value                                                                                                     |
|-----------|-----------------------------------------------------------------------------------------------------------|
| Created   | 2026-03-17                                                                                                |
| Status    | approved                                                                                                  |
| Owned by  | Product Analyst                                                                                           |
| Traces to | [Prioritization](../../.sinemacula/blueprint/workflows/notification-listener-config/prioritization.md)    |
| Problems  | P1 (log level correction), P2 (class exclusion)                                                           |

---

## Background

The `NotificationListener` is an audit-style logger that records every `NotificationSending` and `NotificationSent`
event. It writes to a `notifications` log channel and optionally to `cloudwatch-notifications`. Both events log at
`info` level with no class-based filtering.

The existing config at `api-toolkit.notifications` has one key: `enable_logging` (boolean toggle). The listener is a
65-line class with two public methods delegating to a shared private `log()` helper.

This is v2. The log level change for `NotificationSending` (from `info` to `debug`) is a minor behavioral change that
is acceptable.

---

## User Capabilities

### UC-1: Notification events log at semantically correct levels

The `NotificationSending` event logs at `debug` (diagnostic context -- the notification hasn't been delivered yet) and
`NotificationSent` logs at `info` (confirmed action). These levels are enforced, not configurable -- they represent the
correct semantic distinction.

**Acceptance criteria:**

- `NotificationSending` events log at `debug` level
- `NotificationSent` events log at `info` level
- Both the `notifications` and `cloudwatch-notifications` channels use these levels
- No config keys for log levels -- the levels are hardcoded as the correct semantics

### UC-2: Developer can exclude specific notification classes from logging

When a developer has high-frequency, low-value notifications that create log noise, they can exclude them from the
notification audit log.

**Acceptance criteria:**

- A new config key is added: `api-toolkit.notifications.excluded_classes` -- default: `[]` (empty array, log
  everything)
- The value is an array of fully-qualified notification class names
- When a notification's class is in the exclusion list, neither the `NotificationSending` nor `NotificationSent` event
  is logged for that notification
- The exclusion check happens before the log payload is built (no wasted work)
- The exclusion applies to both the `notifications` and `cloudwatch-notifications` channels
- An empty exclusion list means all notifications are logged (backward compatible default)

---

## Out of Scope

- **Configurable log levels:** The levels are the correct semantic choice and do not need to be configurable. Consumers
  who need to suppress `debug` entries can configure their logging channel's minimum level via Laravel's standard
  logging config.
- **Wildcard/pattern matching for exclusions:** Only exact fully-qualified class names are supported. Pattern matching
  adds complexity for minimal benefit.
- **Allowlist mode:** An "only log these classes" mode is the inverse of exclusion. The exclusion list covers the common
  case.

---

## Modified Classes

| Class                    | Change                                                                                                                          |
|--------------------------|---------------------------------------------------------------------------------------------------------------------------------|
| `NotificationListener`  | Change `sending()` to log at `debug`; keep `sent()` at `info`; add exclusion check in `log()` before building payload          |

---

## Configuration Changes

```php
'notifications' => [

    'enable_logging' => env('ENABLE_NOTIFICATION_LOGGING', true),

    'excluded_classes' => [
        // App\Notifications\HeartbeatPing::class,
        // App\Notifications\InternalHealthCheck::class,
    ],

],
```

---

## Success Metrics

| Metric | Baseline | Target | Measurement |
|---|---|---|---|
| NotificationSending log level | info | debug | Test: sending event logs at debug |
| NotificationSent log level | info | info (unchanged) | Test: sent event logs at info |
| Excluded class logging | All classes logged | Excluded classes produce no log entries | Test: excluded notification class is not logged |
| Default behavior (no config changes) | All logged at info | All logged (sending at debug, sent at info) | Test: empty exclusion list logs everything |

---

## Testing Strategy

- **Unit tests for log levels:**
  - `sending()` logs at `debug`
  - `sent()` logs at `info`
  - CloudWatch channel uses the same levels
- **Unit tests for class exclusion:**
  - Notification in exclusion list -> no log entry for sending or sent
  - Notification not in exclusion list -> logged normally
  - Empty exclusion list -> all notifications logged
- **Existing test updates:** Tests asserting `info` level for `sending()` events updated to assert `debug`

---

## References

- Prioritization: .sinemacula/blueprint/workflows/notification-listener-config/prioritization.md
- Problem Map: .sinemacula/blueprint/workflows/notification-listener-config/problem-map.md
- Spike: .sinemacula/blueprint/workflows/notification-listener-config/spikes/spike-listener-implementation.md
- Intake Brief: .sinemacula/blueprint/workflows/notification-listener-config/intake-brief.md
- Source: ISSUES.md (ISSUE-19)
