# PRD: 04 Configurable Middleware Registration

Provide consumers with config-driven control over the toolkit's middleware registrations while hardening the service
provider against internal Laravel API changes.

---

## Governance

| Field     | Value                                                                                                                            |
|-----------|----------------------------------------------------------------------------------------------------------------------------------|
| Created   | 2026-03-16                                                                                                                       |
| Status    | approved                                                                                                                         |
| Owned by  | Ben Carey                                                                                                                        |
| Traces to | [Prioritization](.sinemacula/blueprint/workflows/fragile-middleware-manipulation/prioritization.md) — Ranks 1-3: All P0 problems |

---

## Overview
 
The toolkit's service provider registers three middleware behaviors without consumer control: it swaps Laravel's
maintenance mode middleware using non-contracted Kernel APIs, pushes `JsonPrettyPrint` globally, and unconditionally
overrides the `throttle` middleware alias. Consumers cannot opt out of, configure, or scope any of these registrations.

This PRD consolidates the three P0 problems (consumer configurability, internal API hardening, throttle alias override)
and the related P1 problem (FQCN assumption fragility) into a single deliverable. The approach introduces config-driven
middleware registration that defaults to the current behavior (global registration, automatic maintenance mode swap,
toolkit throttle) while allowing consumers to disable, customise, or re-scope each registration independently.

The primary use case for the toolkit is pure API applications where global middleware registration is correct and
desired. The configurability is for consumers with mixed applications or custom middleware needs. This is proactive
hardening -- the internal APIs work today, but relying on non-contracted methods creates unnecessary upgrade risk.

---

## Target Users

| Persona                  | Description                                                                                        | Key Need                                                                     |
|--------------------------|----------------------------------------------------------------------------------------------------|------------------------------------------------------------------------------|
| Toolkit Consumer (API)   | Developer using the toolkit in a pure API application (no frontend); relies on sensible defaults   | Middleware works out of the box with no configuration required               |
| Toolkit Consumer (Mixed) | Developer using the toolkit in an application with both API and frontend routes                    | Ability to scope toolkit middleware to API routes only                       |
| Custom Middleware User   | Developer who has their own throttle or maintenance mode middleware and needs to prevent conflicts | Ability to disable specific toolkit middleware registrations via config      |
| Package Maintainer       | Maintainer of the toolkit responsible for cross-version Laravel compatibility                      | Middleware registration that does not depend on non-contracted internal APIs |

**Primary user:** Toolkit Consumer (API)

---

## Goals

- Consumers can control each middleware registration (maintenance mode swap, JsonPrettyPrint, throttle alias)
  independently via the published config file
- Consumers can choose between global and route-group-scoped middleware registration where applicable
- The service provider no longer uses `getGlobalMiddleware()` / `setGlobalMiddleware()` for the maintenance mode
  middleware swap
- Existing consumers experience no behavior change without explicit configuration changes (backward compatible defaults)

## Non-Goals

- Migrating to the Laravel 11+ `withMiddleware` application-level configuration API (this is application-level, not
  available to packages)
- Eliminating all use of concrete Kernel methods (no official package-facing alternative exists; first-party packages
  like Sanctum use the same pattern)
- Providing a generic middleware registration framework for consumers (the toolkit configures its own middleware only)
- Documenting or changing middleware registration for `ParseApiQuery` (already has its own config flag
  `parser.register_middleware`)

---

## Problem

**User problem:** Consumers cannot opt out of or configure the middleware changes the toolkit makes to their
application. Consumers with custom throttle middleware are silently overridden, causing hard-to-diagnose behavior.
Consumers with mixed API + frontend applications cannot scope toolkit middleware to API routes only. The maintenance
mode middleware swap relies on internal Kernel APIs that are not part of any interface contract, creating upgrade risk.

**Business problem:** An open-source API toolkit that silently overrides consumer middleware and relies on internal
framework APIs undermines its core value proposition of stability and configurability. Consumers who encounter conflicts
or upgrade breakage lose trust in the package.

**Current state:** Consumers must fork the package or override the service provider entirely to change middleware
behavior. The maintenance mode swap uses `getGlobalMiddleware()` / `setGlobalMiddleware()` (non-contracted Kernel
methods). The throttle alias is overridden unconditionally. `JsonPrettyPrint` is pushed globally with no scope option.

**Evidence:**

- Problem Map: Cluster "Consumer Configurability" > Problem 5; Cluster "Upgrade Fragility" > Problems 1, 2; Cluster "
  Middleware Scope Inflexibility" > Problem 4
- Spike Finding 1: Kernel contract defines only 4 lifecycle methods; middleware methods are concrete-only
- Spike Finding 3: `getGlobalMiddleware()` / `setGlobalMiddleware()` are implementation details with no interface
  backing
- Spike Finding 5: First-party packages (Sanctum 4.x) use concrete Kernel methods, establishing the de facto pattern
- Spike Finding 6: `aliasMiddleware()` is stable, but unconditional override of built-in aliases creates conflict risk

---

## Proposed Solution

When a consumer installs the toolkit, all middleware registrations work exactly as they do today by default. No
configuration changes are required. The consumer's pure API application gets global `JsonPrettyPrint`, the maintenance
mode middleware swap, and the toolkit's throttle alias -- all automatically.

When a consumer needs to customise, they publish the config file and adjust the middleware section. Each registration
can be independently enabled, disabled, or scoped. A consumer with a mixed application changes `JsonPrettyPrint` scope
from `global` to `api`. A consumer with their own throttle middleware disables the throttle alias override. A consumer
who prefers to manage maintenance mode middleware themselves disables the automatic swap.

The maintenance mode middleware swap no longer iterates the global middleware array using internal APIs. Instead, it
uses a more resilient approach that aligns with patterns used by first-party Laravel packages.

### Key Capabilities

- Consumer can enable or disable each middleware registration (maintenance mode swap, JsonPrettyPrint, throttle alias)
  independently via config
- Consumer can choose the scope of `JsonPrettyPrint` registration (global or API route group)
- Consumer can specify a custom throttle middleware class via config instead of accepting the toolkit's default
- Consumer can disable the maintenance mode middleware swap and handle it in their own `bootstrap/app.php` if preferred
- Existing consumers experience identical behavior with no config changes

---

## Requirements

### Must Have (P0)

- **Configurable maintenance mode middleware swap:** Consumer can enable or disable the automatic replacement of
  Laravel's `PreventRequestsDuringMaintenance` middleware with the toolkit's version via a config flag.
    - **Acceptance criteria:** When the config flag is set to `false`, the toolkit does not attempt to swap the
      maintenance mode middleware. When set to `true` (default), the swap occurs as it does today.

- **Elimination of non-contracted Kernel APIs for maintenance mode swap:** The service provider no longer calls
  `getGlobalMiddleware()` or `setGlobalMiddleware()` for the maintenance mode middleware replacement.
    - **Acceptance criteria:** The `registerMiddleware()` method does not call `getGlobalMiddleware()` or
      `setGlobalMiddleware()`. The maintenance mode middleware swap uses only methods that are widely used by
      first-party Laravel packages (e.g., `pushMiddleware()`-style patterns) or delegates the swap to consumer
      configuration.

- **Configurable JsonPrettyPrint scope:** Consumer can choose whether `JsonPrettyPrint` is registered globally or scoped
  to the API route group via config.
    - **Acceptance criteria:** When scope is set to `global` (default), `JsonPrettyPrint` is pushed to the global
      middleware stack. When set to `api`, it is appended to the `api` middleware group. When set to `false` / disabled,
      it is not registered at all.

- **Configurable throttle alias override:** Consumer can enable or disable the throttle middleware alias override, and
  optionally specify a custom throttle middleware class.
    - **Acceptance criteria:** When the config flag is set to `false`, the toolkit does not override the `throttle`
      alias. When set to `true` (default), the toolkit registers its throttle middleware as today. When a custom class
      is provided, that class is used instead of the toolkit's default.

- **Backward compatible defaults:** All config options default to the current behavior so that existing consumers
  experience no change.
    - **Acceptance criteria:** A fresh install with no config modifications produces identical middleware behavior to
      the current version.

### Should Have (P1)

- **FQCN assumption resilience:** The maintenance mode middleware swap does not rely on string comparison against a
  fully-qualified class name for detection.
    - **Acceptance criteria:** If Laravel changes how it registers the maintenance mode middleware internally (e.g.,
      aliased), the swap either still works or gracefully falls back with a logged warning.

- **Config section documentation:** The published `api-toolkit.php` config file includes inline documentation for all
  new middleware config options, following the existing documentation style.
    - **Acceptance criteria:** Each new config key has a descriptive comment block explaining the option, its valid
      values, and its default.

### Nice to Have (P2)

- **Consumer guidance documentation:** Consumers who disable automatic middleware registration can follow documented
  guidance on how to configure middleware in their `bootstrap/app.php` using Laravel 11+ patterns.

---

## Success Criteria

| Metric                                | Baseline                                                                    | Target                                                     | How Measured                                                          |
|---------------------------------------|-----------------------------------------------------------------------------|------------------------------------------------------------|-----------------------------------------------------------------------|
| Non-contracted Kernel API calls       | 2 (`getGlobalMiddleware`, `setGlobalMiddleware`)                            | 0                                                          | Grep source for `getGlobalMiddleware` and `setGlobalMiddleware` calls |
| Configurable middleware registrations | 0 of 3 registrations (maintenance, pretty-print, throttle) are configurable | 3 of 3 registrations are configurable via published config | Review config file for middleware section with enable/disable flags   |
| Backward compatibility                | N/A — new capability                                                        | 100% of existing tests pass without config changes         | Run `composer test` with default config                               |
| Static analysis compliance            | Passes at PHPStan level 8                                                   | Continues to pass at PHPStan level 8                       | Run `composer check`                                                  |

---

## Dependencies

- None. All changes are internal to the toolkit's service provider and config file. No external package changes
  required.

---

## Assumptions

- The concrete Kernel methods (`pushMiddleware()`, `appendMiddlewareToGroup()`, `prependMiddlewareToGroup()`) will
  remain available in Laravel 11+ for the foreseeable future, consistent with their use in first-party packages like
  Sanctum 4.x.
- Consumers who disable automatic middleware registration are capable of configuring middleware in their
  `bootstrap/app.php` without hand-holding from the toolkit.
- The primary use case for the toolkit remains pure API applications, so global middleware registration is the correct
  default.

---

## Risks

| Risk                                                                                    | Impact                                                        | Likelihood | Mitigation                                                                                                                                                          |
|-----------------------------------------------------------------------------------------|---------------------------------------------------------------|------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Maintenance mode swap replacement approach may not fully replicate current behavior     | Consumers in maintenance mode may see different behavior      | Low        | Comprehensive test coverage for maintenance mode middleware with and without the config flag                                                                        |
| Consumers unaware of new config options continue with defaults                          | No risk -- this is by design; defaults match current behavior | High       | Defaults are backward compatible; config comments guide consumers who need customisation                                                                            |
| Laravel removes or changes concrete Kernel middleware methods in a future major version | Middleware registration breaks on upgrade                     | Low        | The toolkit now uses only the most widely-used concrete methods (same as Sanctum); if Laravel changes them, the entire ecosystem is affected, not just this toolkit |
| Config surface area increases complexity                                                | More config options to document, test, and maintain           | Medium     | Keep config structure simple and flat; follow existing config patterns in the file                                                                                  |

---

## Out of Scope

- Changes to the `ParseApiQuery` middleware registration (already has its own config flag `parser.register_middleware`)
- Migration to the Laravel 11+ `withMiddleware` application-level configuration API (not available to packages)
- Changes to the middleware classes themselves (only the registration mechanism is in scope)
- Request macros (ISSUE-12 is a separate concern)
- Any changes to the `NotificationListener`, `WritePool`, or other service provider registrations

---

## Release Criteria

- All existing tests pass without modification (backward compatibility)
- New tests cover each config option: enabled, disabled, and scope variations
- `composer check` passes (PHPStan level 8, code style)
- `composer test` passes
- No calls to `getGlobalMiddleware()` or `setGlobalMiddleware()` remain in the source
- Published config file includes inline documentation for all new middleware options

---

## Traceability

| Artifact             | Path                                                                                                                                              |
|----------------------|---------------------------------------------------------------------------------------------------------------------------------------------------|
| Intake Brief         | `.sinemacula/blueprint/workflows/fragile-middleware-manipulation/intake-brief.md`                                                                 |
| Relevant Spikes      | `.sinemacula/blueprint/workflows/fragile-middleware-manipulation/spikes/spike-laravel-11-middleware-api.md`                                       |
| Problem Map Entry    | Cluster "Consumer Configurability" > Problem 5; Cluster "Upgrade Fragility" > Problems 1, 2; Cluster "Middleware Scope Inflexibility" > Problem 4 |
| Prioritization Entry | Ranks 1-3 (all P0): No Consumer Control, Maintenance Mode Internal APIs, Throttle Alias Override; Rank 4 (P1): FQCN Assumption                    |

---

## References

- Traces to: [Prioritization](.sinemacula/blueprint/workflows/fragile-middleware-manipulation/prioritization.md) — Ranks
  1-3
- Intake Brief: `.sinemacula/blueprint/workflows/fragile-middleware-manipulation/intake-brief.md`
