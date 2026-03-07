# PRD: 03 Schema Validation at Boot Time

Resource schemas are validated at application boot with clear, actionable error messages that name the offending resource, field, and defect.

---

## Governance

| Field     | Value                                                                                                 |
|-----------|-------------------------------------------------------------------------------------------------------|
| Created   | 2026-03-06                                                                                            |
| Status    | approved                                                                                              |
| Owned by  | Ben                                                                                                   |
| Traces to | [Prioritization](.sinemacula/blueprint/workflows/schema-validation-at-boot/prioritization.md) -- Ranks 1-4 |

---

## Overview

Developers building APIs with this toolkit define resource schemas that drive field resolution, eager-load planning, and response output. Today, these schemas are unvalidated arrays. Defects -- from mistyped class names to invalid guard callables -- are only discoverable by hitting the affected endpoint at request time, where they produce either cryptic 500 errors or, worse, silent incorrect behaviour such as security guards that fail open.

This PRD addresses the four highest-priority problems by introducing boot-time schema validation: a validation step that runs when the application starts, checks every registered resource schema for known defect classes, and throws clear exceptions that name the resource, the field, and the specific defect. The result is a short feedback loop where developers learn about schema errors immediately on application boot rather than through runtime investigation.

The expected impact is a significant improvement in developer experience and API reliability. Schema errors that currently require minutes of stack-trace debugging will be caught and explained before the first request is served.

---

## Target Users

| Persona              | Description                                                                     | Key Need                                                                |
|----------------------|---------------------------------------------------------------------------------|-------------------------------------------------------------------------|
| Package Consumer     | A developer building a REST API using this toolkit, defining resource schemas    | Immediate, clear feedback when a schema definition contains an error    |
| Team Lead / Reviewer | A developer reviewing PRs that modify resource schemas                          | Confidence that schema errors are caught before code reaches production |

**Primary user:** Package Consumer

---

## Goals

- Every registered resource schema is validated at application boot, catching defects before the first request
- Every validation error message names the resource class, the field key, and the specific defect
- Zero false positives: valid schemas pass validation without warnings or errors
- Developers discover schema errors within the boot-time feedback loop, not at request time

## Non-Goals

- Validating runtime-dependent conditions (e.g., guards that depend on authenticated user state)
- Validating schemas for resource classes that are not registered in the resource map
- Providing auto-fix or auto-correction for invalid schemas
- Replacing runtime defensive checks (is_callable, instanceof) -- validation is additive
- Optimising schema compilation performance beyond current levels

---

## Problem

**User problem:** Developers defining resource schemas have no way to know their schema is valid until they hit the affected endpoint. When errors do surface, they manifest as either cryptic 500 errors (non-existent resource class, non-existent relation) or silent incorrect behaviour (guards that fail open, exposing restricted data). Debugging requires tracing stack traces through field resolution or eager-load planning with no indication of which resource or schema entry is at fault.

**Business problem:** For an open-source package, developer experience directly drives adoption and retention. Cryptic errors and silent failures erode trust in the toolkit and increase the support burden. Schema validation is a foundational concern that underpins the reliability of the entire resource layer.

**Current state:** Resource schemas are compiled lazily on first request via `SchemaCompiler::compile()`. The compiler performs zero validation -- it trusts every value in the raw array and directly constructs typed objects. The codebase uses defensive programming (`is_callable()`, `instanceof Closure`, `MissingValue` sentinels) which prevents crashes for some defect types but converts them into silent incorrect behaviour. Developers discover errors only through manual endpoint testing and stack-trace analysis.

**Evidence:**

- Spike: Schema Structure & Defect Inventory -- Finding 3 identified 10 distinct defect classes, 6 of which produce silent incorrect behaviour
- Spike: Finding 4 -- Defensive checks mask defects rather than surfacing them
- Spike: Finding 5 -- No boot-time validation exists; compilation is lazy and trust-based
- Problem Map: Silent Schema Defects cluster (5 problems), Cryptic Runtime Crashes cluster (3 problems), Late Error Discovery cluster (2 problems)

---

## Proposed Solution

When a developer starts their application, the toolkit automatically validates every registered resource schema during the boot phase. If any schema contains a defect, the developer sees a clear exception message before the application serves its first request.

### User Journey

1. Developer defines or modifies a resource schema using the existing `Field`, `Relation`, and `Count` builders
2. Developer starts their application (e.g., `php artisan serve`, test runner, or queue worker)
3. During boot, the toolkit compiles and validates all schemas for resources listed in the resource map
4. **If schemas are valid:** Application boots normally with no additional output
5. **If a schema has a defect:** An exception is thrown with a message like:
   > "Schema validation failed for UserResource: field 'organization' references resource class 'App\Http\Resources\OrgResource' which does not exist."
6. Developer reads the error message, identifies the exact resource and field, and fixes the defect
7. Developer restarts the application; validation passes

### Key Capabilities

- Automatic validation of all registered resource schemas during application boot
- Clear, actionable error messages that identify the resource, field, and defect
- Detection of invalid guard callables that would otherwise silently fail open
- Detection of non-existent resource classes that would otherwise cause fatal errors
- Detection of non-existent Eloquent relations that would otherwise cause fatal errors
- Ability to toggle validation by environment for production performance

---

## Requirements

### Must Have (P0)

- **Boot-time validation:** Developer's application validates all registered resource schemas during the boot phase, before serving any requests.
  - **Acceptance criteria:** When the application boots with at least one registered resource in the resource map, every registered resource's `schema()` is compiled and validated. If validation passes, the boot completes normally with no additional overhead at request time for those resources.

- **Guard callable validation:** Developer is alerted when a schema entry contains a guard that is not a valid callable.
  - **Acceptance criteria:** A schema with a non-callable guard value causes a validation exception at boot. The exception message names the resource class, the field key, and states that the guard is not callable. A schema with valid callable guards passes validation without error.

- **Resource class existence validation:** Developer is alerted when a relation references a resource class that does not exist.
  - **Acceptance criteria:** A schema with a relation pointing to a non-existent class causes a validation exception at boot. The exception message names the resource class, the field key, and the non-existent target class. A schema with valid resource class references passes validation without error.

- **Relation existence validation:** Developer is alerted when a schema references an Eloquent relation that does not exist on the corresponding model.
  - **Acceptance criteria:** A schema with a relation name that does not exist as a method on the associated model causes a validation exception at boot. The exception message names the resource class, the field key, and the missing relation name. A schema with valid relation references passes validation without error.

- **Actionable error messages:** Every validation error message identifies the resource class, the field key, and the specific defect.
  - **Acceptance criteria:** Each validation error message contains all three elements: (1) the fully qualified resource class name, (2) the schema field key, and (3) a human-readable description of the defect. No validation error produces a message that lacks any of these three elements.

- **Environment-aware toggle:** Developer can disable boot-time validation in production for performance.
  - **Acceptance criteria:** A configuration option controls whether boot-time validation runs. When disabled, no schema validation occurs during boot and there is zero performance impact. When enabled (the default for non-production environments), validation runs as specified.

### Should Have (P1)

- **Transformer callable validation:** Developer is alerted when a schema entry contains a transformer that is not a valid callable.
  - **Acceptance criteria:** A schema with a non-callable transformer value causes a validation exception at boot. The exception message names the resource class, the field key, and states that the transformer is not callable.

- **Resource interface validation:** Developer is alerted when a relation references a class that exists but does not implement the required resource interface.
  - **Acceptance criteria:** A schema with a relation pointing to a class that does not implement `ApiResourceInterface` causes a validation exception. The message names both the target class and the expected interface.

- **Computed field callable validation:** Developer is alerted when a computed field references a non-existent method or non-callable value.
  - **Acceptance criteria:** A schema with a computed field whose `compute` value is neither a valid callable nor an existing method on the resource class causes a validation exception at boot.

- **Duplicate field key detection:** Developer is alerted when a schema contains duplicate field keys.
  - **Acceptance criteria:** When `Field::set()` receives multiple definitions with the same key, a validation exception is thrown naming the duplicate key and the resource class.

- **Proactive validation command:** Developer can validate all schemas on demand without starting the application.
  - **Acceptance criteria:** A command validates all registered resource schemas and reports results. It exits with a non-zero code if any schema fails validation, making it suitable for CI pipelines.

### Nice to Have (P2)

- **Constraint type validation:** Developer is alerted when a schema entry contains a constraint that is not a Closure.
  - **Acceptance criteria:** A schema with a non-Closure constraint causes a validation warning or exception naming the field and stating that constraints must be Closures.

- **Accessor path validation:** Developer is alerted when an accessor references a path that cannot be resolved.
  - **Acceptance criteria:** Static analysis of accessor paths detects obviously invalid paths (e.g., empty strings, null values) and reports them at boot.

---

## Success Criteria

| Metric                                    | Baseline                                | Target                                       | How Measured                                                                  |
|-------------------------------------------|-----------------------------------------|----------------------------------------------|-------------------------------------------------------------------------------|
| Schema defects caught at boot             | 0 (no boot-time validation exists)      | 100% of P0 defect types caught at boot       | Test suite covering each P0 defect type triggers validation exception at boot |
| False positive rate                       | N/A -- new capability                   | 0 false positives across all fixture schemas  | All existing valid test fixture schemas pass validation without error          |
| Error message completeness                | 0% (no structured error messages exist) | 100% of messages contain resource + field + defect | Automated test asserts each error message contains all three elements          |
| Time to identify schema defect            | Minutes (manual stack-trace debugging)  | Seconds (read boot-time error message)       | Qualitative: error message directly names the defect location                 |

---

## Dependencies

- The `resource_map` config (`config('api-toolkit.resources.resource_map')`) must list all resource classes that should be validated. Resources not in this map are outside validation scope.
- Relation existence validation depends on knowing which Eloquent model corresponds to each resource. This mapping must be available at boot time via the resource map.

---

## Assumptions

- The `schema()` return structure from the fluent builders (`Field`, `Relation`, `Count`) is stable and can be validated against known rules.
- The `resource_map` config contains all resource classes that consumers expect to be validated. Resource classes referenced only within schema relation entries (not in the map) may need recursive discovery.
- Boot-time validation adds negligible latency for applications with typical resource counts (under 50 resources).
- Consumers prefer fail-fast behaviour (exception) over warnings for schema defects.

---

## Risks

| Risk                                             | Impact                                                          | Likelihood | Mitigation                                                                                   |
|--------------------------------------------------|-----------------------------------------------------------------|------------|----------------------------------------------------------------------------------------------|
| Boot-time validation adds unacceptable latency   | Production cold-start time increases noticeably                 | Low        | Environment-aware toggle (P0 requirement) allows disabling in production                     |
| Relation validation requires model instantiation | May trigger side effects or database connections during boot    | Medium     | Use reflection-based relation discovery rather than model instantiation                       |
| Recursive resource discovery misses classes      | Some resource classes are not validated despite being in use    | Medium     | Document that only resource_map entries are validated; provide clear guidance on registration |
| Existing valid schemas fail validation            | Breaking change for consumers upgrading the package             | Low        | Zero false positives is a success criterion; extensive testing against fixture schemas        |

---

## Out of Scope

- Runtime validation of guard or transformer execution results (these depend on request context)
- Auto-discovery of resource classes outside the resource map
- Schema validation for third-party packages that extend the toolkit
- Performance profiling or optimisation of schema compilation itself
- Migration tooling for consumers upgrading from unvalidated to validated schemas

---

## Release Criteria

- All P0 requirements pass acceptance criteria with automated tests
- All existing test fixture schemas pass validation without false positives
- Package test suite passes (`composer test`) with no regressions
- Static analysis passes (`composer check`) at PHPStan level 8
- Configuration option documented in the published config file with sensible defaults
- Boot-time validation is enabled by default in non-production environments

---

## Traceability

| Artifact             | Path                                                                                    |
|----------------------|-----------------------------------------------------------------------------------------|
| Intake Brief         | `.sinemacula/blueprint/workflows/schema-validation-at-boot/intake-brief.md`             |
| Relevant Spikes      | `.sinemacula/blueprint/workflows/schema-validation-at-boot/spikes/spike-schema-defect-inventory.md` |
| Problem Map Entry    | Silent Schema Defects > Guards fail open; Cryptic Runtime Crashes > Non-existent resource class, Non-existent relation; Late Error Discovery > No boot-time validation |
| Prioritization Entry | Rank 1: No boot-time validation, Rank 2: Guards fail open, Rank 3: Non-existent resource class crashes, Rank 4: Non-existent relation crashes |

---

## References

- Traces to: [Prioritization](.sinemacula/blueprint/workflows/schema-validation-at-boot/prioritization.md) -- Ranks 1-4
- Intake Brief: [.sinemacula/blueprint/workflows/schema-validation-at-boot/intake-brief.md](.sinemacula/blueprint/workflows/schema-validation-at-boot/intake-brief.md)
