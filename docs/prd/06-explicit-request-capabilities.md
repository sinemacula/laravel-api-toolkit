# PRD: 06 Explicit Request Capabilities

Replace the toolkit's 7 implicit Request macros with explicit, type-safe, statically analysable request capability
methods that support both global and route-group-scoped registration.

---

## Governance

| Field     | Value                                                                                                                                              |
|-----------|-----------------------------------------------------------------------------------------------------------------------------------------------------|
| Created   | 2026-03-17                                                                                                                                          |
| Status    | approved                                                                                                                                            |
| Owned by  | Ben Carey                                                                                                                                           |
| Traces to | [Prioritization](.sinemacula/blueprint/workflows/request-macro-explicit-api-surface/prioritization.md) — Ranks 1-5: All P0 problems (coordinated) |

---

## Overview

The toolkit's service provider registers 7 Request macros (`includeTrashed`, `onlyTrashed`, `expectsExport`,
`expectsCsv`, `expectsXml`, `expectsPdf`, `expectsStream`) that are globally available on every `Request` instance.
These macros are invisible to PHPStan at level 8, absent from IDE autocomplete, risk silent naming collisions with
consumer macros, and cannot be scoped to specific route groups.

This PRD consolidates the five P0 problems (PHPStan invisibility, silent macro overwrite risk, IDE discoverability gap,
route-group scoping, and analysis workaround degradation) into a single deliverable. The approach introduces an explicit,
typed request capability API that provides the same functionality as the current macros through statically analysable
methods. The existing macros are deprecated but retained for backward compatibility, delegating to the new API
internally.

The primary use case is pure API applications where global registration is correct and desired. The capability API also
supports route-group-scoped registration for consumers with mixed API + web applications. This complements PRD 04
(Configurable Middleware Registration), which addresses middleware registration configurability — this PRD addresses the
request capability detection surface that middleware and controllers consume.

---

## Target Users

| Persona                    | Description                                                                                    | Key Need                                                                            |
|----------------------------|------------------------------------------------------------------------------------------------|-------------------------------------------------------------------------------------|
| Toolkit Consumer (API)     | Developer using the toolkit in a pure API application; relies on sensible defaults             | Request capabilities work out of the box, are visible in IDE, and pass PHPStan      |
| Toolkit Consumer (Mixed)   | Developer using the toolkit in an application with both API and frontend routes                | Ability to scope request capabilities to API routes only                            |
| Package Integrator         | Developer integrating the toolkit alongside other packages that may define their own macros    | No silent conflicts between toolkit capabilities and other package macros           |
| New Adopter                | Developer evaluating or onboarding to the toolkit for the first time                           | Discoverable API surface that appears in autocomplete and has clear import paths    |

**Primary user:** Toolkit Consumer (API)

---

## Goals

- All request capability methods are visible to PHPStan at level 8 without custom extensions, stubs, or ignore patterns
- All request capability methods appear in IDE autocomplete (PhpStorm, VS Code with Intelephense) without helper files
- Request capability methods live in an explicit namespace, eliminating silent naming collision risk with consumer macros
- Consumers can register request capabilities globally (API-only apps) or scoped to route groups (mixed apps)
- Existing macro-based code continues to work with deprecation notices guiding migration
- The new API is no more verbose or cumbersome than the current macro calling convention in typical usage

## Non-Goals

- Removing macros in this release (removal is a future major version change; this release deprecates only)
- Providing a generic request extension framework for arbitrary consumer capabilities (the toolkit manages its own
  capabilities)
- Changing what the capabilities detect (the Accept header parsing, query parameter reading, and config checks remain
  the same)
- Addressing Laravel Octane persistent worker compatibility (deferred; see Assumptions)
- Changing how middleware is registered (covered by PRD 04)

---

## Problem

**User problem:** Developers using the toolkit cannot verify the correctness of request capability calls at analysis
time. PHPStan level 8 — the project standard — treats macro calls as undefined methods or returns `mixed` types,
meaning type errors are caught only at runtime. IDEs do not show the 7 capability methods in autocomplete, forcing
developers to discover them by reading source code or documentation. When another package or the application itself
registers a macro with the same name, the toolkit's version is silently overwritten with no warning, causing
hard-to-diagnose production bugs. Consumers with mixed API + web applications cannot restrict these capabilities to API
routes only — the macros are registered globally on every Request instance with no scoping mechanism.

**Business problem:** An open-source API toolkit that fails its own static analysis standard (PHPStan level 8) on a
core part of its API surface undermines developer trust. Poor IDE discoverability increases the onboarding barrier for
new consumers. Silent macro collisions create unpredictable behavior that erodes confidence in the package's
reliability. The inability to scope capabilities to route groups limits adoption in mixed-use applications.

**Current state:** Consumers use the 7 macros directly on the Request instance (e.g., `$request->expectsExport()`).
Some consumers add `_ide_helper.php` files or PHPStan stubs to work around discoverability and type-checking gaps, but
this is manual, fragile, and not officially supported. Internally, the macros are consumed by the `RespondsWithExport`
trait (`expectsCsv()`, `expectsXml()`) and available for consumer use in controllers, middleware, and form requests.
The `includeTrashed()` and `onlyTrashed()` macros are not used internally — they exist solely for consumer use.

**Evidence:**

- Problem Map: Cluster "Static Analysis Blindspot" > Problems 1, 2; Cluster "Discoverability Friction" > Problem 3;
  Cluster "Integration Safety" > Problem 5; Cluster "Deployment Flexibility" > Problem 7
- Spike Finding 5: PHPStan core issue #12570 confirms dynamically-resolved methods always receive `mixed` return types.
  Larastan issues #1746 and #1591 document undefined method errors and incorrect return types on macro calls
- Spike Finding 6 (comparison matrix): Macros are the only pattern with "High" naming conflict risk and "Always global"
  scoping — all other patterns provide explicit namespacing and flexible scoping
- Spike Finding 4: Middleware-injected request attributes naturally support both global and route-group scoping
- Spike Finding 2: Traits on request classes provide full native PHPStan and IDE support without extensions

---

## Proposed Solution

When a developer installs or upgrades the toolkit, the request capabilities continue to work exactly as before. The
existing macros remain available but are marked as deprecated.

A developer writing new code discovers the capability methods through IDE autocomplete. They import a typed class and
call methods that return typed values. PHPStan validates these calls at level 8 with no special configuration. The
developer's `use` statement makes the dependency on the toolkit explicit and traceable.

A developer building a pure API application registers the capabilities globally in their service provider or config.
Every request in the application has access to the capabilities. This is the default behavior and requires no
additional configuration.

A developer building a mixed API + web application scopes the capabilities to their API route group. Requests to web
routes do not carry the API capabilities. Requests to API routes have full access. The scoping mechanism uses the same
middleware group patterns that Laravel developers already use for route groups.

A developer using another package that happens to define a macro named `expectsCsv()` experiences no conflict. The
toolkit's capability methods live in their own namespace and are accessed through an explicit typed interface, not
through the shared macro namespace on the Request class.

When a developer encounters a deprecated macro call in existing code, the deprecation notice tells them exactly what to
use instead and how to migrate. The migration is mechanical — a direct method-for-method replacement with a different
calling convention.

### Key Capabilities

- Developer can access all 7 request capabilities through typed, statically analysable methods
- Developer can discover all available capabilities through IDE autocomplete without helper files
- Developer can register capabilities globally (API-only) or scoped to route groups (mixed apps)
- Developer can use the new API alongside deprecated macros during migration
- Developer can trace the toolkit dependency through explicit imports in their code
- Developer can extend the capabilities in consuming applications without risk of naming collisions

---

## Requirements

### Must Have (P0)

- **Typed request capability API:** Developer can access all 7 current capabilities (`includeTrashed`, `onlyTrashed`,
  `expectsExport`, `expectsCsv`, `expectsXml`, `expectsPdf`, `expectsStream`) through typed methods that return
  explicit `bool` values.
    - **Acceptance criteria:** PHPStan level 8 validates all capability method calls without errors, custom extensions,
      stubs, or `ignoreErrors` patterns. Each method has an explicit `bool` return type declaration.

- **IDE discoverability:** Developer can discover all available capability methods through standard IDE autocomplete
  when working with the typed API.
    - **Acceptance criteria:** All capability methods appear in PhpStorm autocomplete without `_ide_helper.php` or
      PHPDoc `@mixin` annotations. Methods are accessible through standard PHP class/trait mechanisms that IDEs
      understand natively.

- **Namespace isolation:** The typed capability API lives in the toolkit's PHP namespace, eliminating the possibility
  of silent naming collisions with consumer-defined macros or methods from other packages.
    - **Acceptance criteria:** A consuming application that defines a Request macro named `expectsCsv()` does not
      affect the toolkit's capability detection. The toolkit's capabilities and the consumer's macro coexist without
      interference.

- **Global registration:** Developer can register request capabilities globally so that they are available on every
  request in the application.
    - **Acceptance criteria:** A pure API application with global registration can access all 7 capabilities from any
      controller, middleware, or form request without per-route configuration. This is the default behavior.

- **Route-group scoped registration:** Developer can register request capabilities for specific route groups only.
    - **Acceptance criteria:** A mixed API + web application can scope capabilities to the `api` middleware group.
      Requests to API routes can access all capabilities. Requests to non-API routes cannot access the capabilities
      (methods return defaults or the typed accessor is not available).

- **Macro deprecation with delegation:** The existing 7 macros are deprecated but continue to work, internally
  delegating to the new typed API.
    - **Acceptance criteria:** Calling a deprecated macro (e.g., `$request->expectsExport()`) produces a PHP
      deprecation notice and returns the same value as the equivalent typed API call. All existing tests that use
      macros continue to pass.

- **Backward compatible defaults:** A fresh install or upgrade with no configuration changes produces identical
  behavior to the current version.
    - **Acceptance criteria:** All existing tests pass without modification. The macros work as before. The default
      registration mode is global.

### Should Have (P1)

- **Migration guidance in deprecation notices:** Each deprecated macro's deprecation notice includes a clear reference
  to the replacement method and calling convention.
    - **Acceptance criteria:** The deprecation notice text includes the replacement class/method name and a brief
      usage example or reference.

- **Ergonomic calling convention:** The typed API calling convention is no more verbose than the current macro
  convention for the most common use case (checking a single capability in a controller or middleware).
    - **Acceptance criteria:** The most common usage pattern (e.g., checking whether a request expects an export)
      requires no more than one additional line of code compared to the current macro approach, excluding the `use`
      import statement.

- **Internal migration:** The toolkit's own internal usages of macros (in `RespondsWithExport` trait) are migrated
  to the new typed API.
    - **Acceptance criteria:** No internal code in the toolkit calls the deprecated macros. All internal capability
      checks use the new typed API. `RespondsWithExport` works identically after migration.

### Nice to Have (P2)

- **Consumer extensibility guidance:** Documentation or an example showing how consumers can add custom capabilities
  (e.g., `expectsParquet()`) using the same pattern the toolkit uses, without modifying the package source.

- **Configuration consolidation:** The capability-related config (e.g., `exports.enabled`, `exports.supported_formats`)
  is organised in a way that makes the relationship between configuration and capability detection clear.

---

## Success Criteria

| Metric                                    | Baseline                                                              | Target                                                                | How Measured                                                                          |
|-------------------------------------------|-----------------------------------------------------------------------|-----------------------------------------------------------------------|---------------------------------------------------------------------------------------|
| PHPStan level 8 errors on capability calls | Unknown (macros produce `mixed` or undefined method errors)          | 0 errors on all capability method calls                               | Run `composer check` with PHPStan level 8; grep results for capability method names   |
| IDE autocomplete coverage                 | 0 of 7 capabilities visible in autocomplete (macros not shown)       | 7 of 7 capabilities visible in PhpStorm autocomplete without helpers  | Manual verification in PhpStorm with no `_ide_helper.php` installed                   |
| Naming collision risk                     | 7 macros in shared global namespace (high risk)                       | 0 methods in shared namespace (capabilities in toolkit namespace)     | Review source for `Request::macro()` calls; verify new API uses own namespace         |
| Registration scope options                | 1 (global only)                                                       | 2 (global and route-group scoped)                                     | Review config/registration mechanism for both modes; test both in integration tests   |
| Backward compatibility                    | N/A — new capability                                                  | 100% of existing tests pass without modification                      | Run `composer test` with default config and no code changes to tests                  |
| Static analysis compliance                | Passes at PHPStan level 8                                             | Continues to pass at PHPStan level 8                                  | Run `composer check`                                                                  |

---

## Dependencies

- **PRD 04 (Configurable Middleware Registration):** PRD 04 introduces config-driven middleware registration including
  route-group scoping for middleware. The scoped registration mechanism in this PRD should align with PRD 04's config
  patterns and middleware group conventions to provide a consistent consumer experience. This PRD does not depend on
  PRD 04 being delivered first, but the two should use compatible configuration approaches.

---

## Assumptions

- Consumers primarily use the macros in controllers, middleware, and form request classes. The new typed API must be
  equally accessible in these three contexts.
- The existing macros are used by consuming applications. The deprecation period spans at least one minor version
  before removal in the next major version.
- Laravel Octane persistent worker compatibility is not in scope for the initial delivery. Request attribute cleanup
  between worker requests is assumed to be handled by Laravel's existing request lifecycle. If Octane introduces issues,
  they will be addressed in a follow-up.
- The chosen calling convention will be at most marginally more verbose than `$request->method()`. If the implementation
  pattern requires significantly more boilerplate, the P1 ergonomics requirement has not been met and the pattern should
  be reconsidered.
- Most current consumers are pure API applications where global registration is the correct default. Route-group scoping
  serves a smaller but valid segment of consumers with mixed applications.

---

## Risks

| Risk                                                                                                  | Impact                                                                                                        | Likelihood | Mitigation                                                                                                                                                            |
|-------------------------------------------------------------------------------------------------------|---------------------------------------------------------------------------------------------------------------|------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Ergonomics regression: the new API is more verbose than macros, discouraging adoption                 | Consumers continue using deprecated macros indefinitely; the toolkit carries dual API surfaces long-term       | Medium     | P1 requirement constrains verbosity. If the implementation pattern is too verbose, reconsider the pattern before shipping. Measure against the "one additional line" bar |
| Migration burden on consumers is too high for the benefit                                             | Consumers defer migration; deprecation notices become noise                                                   | Medium     | Provide clear, mechanical migration path. Deprecation notices include direct replacement references. Consider a codemod or rector rule if adoption is slow            |
| The typed API pattern does not fully support all 3 contexts (controller, middleware, form request)    | Consumers must use different patterns in different contexts, increasing cognitive load                         | Low        | Verify the chosen pattern works in all three contexts during design. The spike identified patterns that work across contexts                                           |
| Consumers who depend on macro-based mocking in tests experience test breakage                          | Test suites in consuming applications fail after upgrade even though runtime behavior is unchanged              | Medium     | Deprecated macros delegate to the new API, so macro-based mocking continues to work. Document the new testing approach alongside migration guidance                    |
| Route-group scoping interacts unexpectedly with PRD 04's middleware config                             | Conflicting or redundant configuration between middleware registration and capability scoping                  | Low        | Align config structure and naming conventions with PRD 04. Review both PRDs together during implementation design                                                      |

---

## Out of Scope

- Removing macros (this release deprecates only; removal is a future major version change)
- Changing what the capabilities detect (Accept header parsing, query parameter reading, and config checks remain
  functionally identical)
- Laravel Octane persistent worker compatibility (deferred; see Assumptions)
- Changes to middleware registration (covered by PRD 04)
- A generic request extension framework for arbitrary consumer capabilities
- Changes to the `ParseApiQuery` middleware, `SchemaIntrospector`, `WritePool`, `NotificationListener`, or other
  toolkit components
- IDE helper file generation (the new API should not require one)

---

## Release Criteria

- All existing tests pass without modification (backward compatibility verified)
- New tests cover: typed capability API methods, global registration, route-group scoped registration, macro
  deprecation delegation, namespace isolation from consumer macros
- `composer check` passes (PHPStan level 8, code style)
- `composer test` passes
- All 7 capability methods are callable through the typed API with explicit `bool` return types
- Deprecated macros produce PHP deprecation notices and delegate to the typed API
- No new `_ide_helper.php` or PHPStan stubs are required for the typed API to pass analysis and appear in autocomplete

---

## Traceability

| Artifact             | Path                                                                                                                                                                                   |
|----------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Intake Brief         | `.sinemacula/blueprint/workflows/request-macro-explicit-api-surface/intake-brief.md`                                                                                                   |
| Relevant Spikes      | `.sinemacula/blueprint/workflows/request-macro-explicit-api-surface/spikes/spike-request-extension-patterns.md`                                                                        |
| Problem Map Entry    | Cluster "Static Analysis Blindspot" > Problems 1, 2; Cluster "Discoverability Friction" > Problem 3; Cluster "Integration Safety" > Problem 5; Cluster "Deployment Flexibility" > Problem 7 |
| Prioritization Entry | Ranks 1-5 (all P0): PHPStan Invisible, Silent Overwrite, IDE Absent, No Route-Group Scoping, Workarounds Undermine Analysis                                                          |

---

## References

- Traces to: [Prioritization](.sinemacula/blueprint/workflows/request-macro-explicit-api-surface/prioritization.md) —
  Ranks 1-5
- Intake Brief: `.sinemacula/blueprint/workflows/request-macro-explicit-api-surface/intake-brief.md`
- Related: [PRD 04 — Configurable Middleware Registration](04-configurable-middleware-registration.md)
