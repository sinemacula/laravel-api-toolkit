# PRD: 05 ApiRepository Separation

Separate ApiRepository's two independent concern groups — API query orchestration and attribute casting/setting — into
single-responsibility collaborators so that each can be tested, evolved, and understood independently.

---

## Governance

| Field     | Value                                                                                                                                            |
|-----------|--------------------------------------------------------------------------------------------------------------------------------------------------|
| Created   | 2026-03-06                                                                                                                                       |
| Status    | approved                                                                                                                                         |
| Owned by  | Ben                                                                                                                                              |
| Traces to | [Prioritization](.sinemacula/blueprint/workflows/repository-criteria-decomposition/prioritization.md) — Rank 4: P2: ApiRepository mixes concerns |

---

## Overview

ApiRepository is a 477-line class that houses two independent concern groups: API query orchestration (~64 lines
bridging API requests to the repository pattern) and attribute casting/setting (~358 lines handling reflection-based
cast resolution and relation-aware attribute persistence). These groups share no methods or state except the `$casts`
property, yet live in the same class.

This PRD defines the separation of these concerns into focused collaborators. The casting/setting system is a legitimate
orchestration layer — it determines HOW to persist values (direct set vs. associate vs. sync) based on reflection-based
relation detection and a configurable cast map. However, it has no reason to be coupled with pagination, criteria
injection, and resource resolution.

As part of this separation, five scalar setter methods that add marginal value over Laravel's native `setAttribute()`
will be simplified, and the `setEnumAttribute` pass-through that bypasses Laravel's enum validation will be corrected.

---

## Target Users

| Persona             | Description                                                       | Key Need                                                                                 |
|---------------------|-------------------------------------------------------------------|------------------------------------------------------------------------------------------|
| Package maintainer  | Developer maintaining and evolving sinemacula/laravel-api-toolkit | Modify casting or query orchestration without understanding or risking the other concern |
| Package contributor | External developer contributing fixes or features                 | Understand a focused class quickly; write targeted tests                                 |
| Package consumer    | Developer using the toolkit in their Laravel application          | Extend or override attribute-setting behaviour without subclassing the entire repository |

**Primary user:** Package maintainer

---

## Goals

- Each concern group (query orchestration, attribute casting/setting) is independently testable in isolation
- Reduced cognitive load: no single class exceeds ~200 lines
- Scalar setters that duplicate Laravel native behaviour are simplified or removed
- The `setAttributes()` lifecycle (cast resolution → set → save → sync) is preserved without behavioural change

## Non-Goals

- Changing the public API of ApiRepository that consumers depend on (method signatures on ApiRepository itself remain
  stable)
- Modifying the base `Repository` class or `CriteriaInterface` from sinemacula/laravel-repositories
- Changing how the cast map configuration works
- Addressing filter operator extensibility (separate PRD: 04-operator-extensibility)
- Addressing ApiCriteria decomposition (separate PRD: 04-apicriteria-decomposition)

---

## Problem

**User problem:** Maintainers working on query orchestration must read and understand 358 lines of casting logic that is
irrelevant to their task. Maintainers working on casting must navigate past pagination and criteria injection code.
Testing either concern requires instantiating infrastructure for both. Five scalar setter methods add maintenance
surface without proportional value, and `setEnumAttribute` actively bypasses Laravel's enum validation.

**Business problem:** The coupled structure slows down maintenance velocity and increases the risk of regressions when
modifying either concern. The marginal-value setters inflate the code surface that must be maintained and tested.

**Current state:** Both concerns live in a single 477-line class. Maintainers must read the entire class to work on
either concern. Tests for casting cannot run without repository query infrastructure, and vice versa. The two groups
don't interfere at runtime but impose unnecessary cognitive load.

**Evidence:**

- [Spike: Responsibility Mapping](./spikes/spike-responsibility-mapping.md) — F10: two separable groups with no shared
  method calls between Group A (query orchestration) and Group B (attribute casting/setting)
- [Spike: Casting & Cache](./spikes/spike-casting-and-cache.md) — F1: casting is a translation layer (not duplication),
  F3: five of eight setters add marginal value, F4: sync deferral lifecycle is correct and necessary

---

## Proposed Solution

When a maintainer needs to work on how API queries are orchestrated (pagination, criteria injection, resource
resolution), they work with a focused repository class that delegates attribute handling to a collaborator. When they
need to work on how attributes are cast and set on models (including relation-aware associate/sync operations), they
work with the casting collaborator directly — without needing to understand query orchestration.

The attribute casting collaborator encapsulates the full `setAttributes()` lifecycle: resolving cast types via
reflection and config, setting scalar attributes on the model, saving the model, and deferring sync operations for
many-to-many relations until after save. Scalar setters that merely duplicate Laravel's native `setAttribute()` are
replaced by direct delegation to the model.

### Key Capabilities

- Maintainer can test attribute casting logic without instantiating repository query infrastructure
- Maintainer can test query orchestration logic without instantiating casting infrastructure
- Maintainer can understand each class in isolation — each has a single, well-defined responsibility
- Consumer can replace or extend the casting collaborator without subclassing ApiRepository
- The `setAttributes()` → save → sync lifecycle continues to work identically for all existing use cases

---

## Requirements

### Must Have (P0)

- **Casting collaborator extraction:** Maintainer can work with attribute casting/setting as an independent, injectable
  collaborator that encapsulates cast resolution, attribute setting, model save, and deferred sync operations
  - **Acceptance criteria:** All casting-related methods (cast resolution cascade, relation detection via reflection,
      type-specific setters, cache interactions for cast maps) live in the collaborator, not in ApiRepository.
      ApiRepository delegates to the collaborator for attribute operations.

- **Lifecycle preservation:** The three-phase `setAttributes()` lifecycle (set non-sync attributes → save → sync
  deferred relations) continues to produce identical outcomes for all attribute types
  - **Acceptance criteria:** All existing integration tests for `setAttributes()` pass without modification to test
      assertions. BelongsTo/MorphTo attributes are associated before save. BelongsToMany/MorphToMany attributes are
      synced after save.

- **Scalar setter simplification:** Scalar setters that add marginal value over Laravel's native `setAttribute()` are
  simplified to delegate to the model directly
  - **Acceptance criteria:** `setBooleanAttribute`, `setStringAttribute`, `setIntegerAttribute`, `setArrayAttribute`,
      and `setObjectAttribute` are replaced by direct delegation to `$model->setAttribute()` (or equivalent).
      Null-handling behaviour is preserved where it differs from Laravel's default. `setEnumAttribute` delegates to
      Laravel's native `setAttribute()`, which performs enum validation.

- **Behavioural equivalence:** All existing tests pass without modification to test assertions
  - **Acceptance criteria:** `composer test` passes. No test assertion changes. No new test failures.

### Should Have (P1)

- **Independent testability:** Each collaborator can be unit-tested in complete isolation from the other
  - **Acceptance criteria:** Casting collaborator tests do not require repository query infrastructure. Repository
      query orchestration tests do not require casting infrastructure.

- **Class size reduction:** No single class in the decomposed structure exceeds ~200 lines
  - **Acceptance criteria:** ApiRepository and the casting collaborator each have fewer than 250 lines (allowing
      reasonable margin).

### Nice to Have (P2)

- **Dead cache key cleanup:** Unused `MODEL_EAGER_LOADS` and `MODEL_RELATION_INSTANCES` enum cases in `CacheKeys` are
  removed
  - **Acceptance criteria:** The two unused enum cases are deleted. No references exist in the codebase.

---

## Success Criteria

| Metric                     | Baseline                                       | Target                                            | How Measured                                         |
|----------------------------|------------------------------------------------|---------------------------------------------------|------------------------------------------------------|
| ApiRepository line count   | 477 lines (single class)                       | < 150 lines (query orchestration only)            | `wc -l` on the file                                  |
| Concern isolation          | 0 — concerns cannot be tested independently    | 2 — both concerns testable in isolation           | Count of concern groups with independent test suites |
| Scalar setter count        | 5 marginal-value setters                       | 0 — all delegate to Laravel native                | Count of custom scalar setter methods                |
| Enum validation regression | `setEnumAttribute` bypasses Laravel validation | Enum values validated by Laravel's `setAttribute` | Unit test confirming invalid enum is rejected        |
| Test pass rate             | All tests pass                                 | All tests pass                                    | `composer test` exit code                            |

---

## Dependencies

- `sinemacula/laravel-repositories` base `Repository` class and its public API must remain unchanged
- The `CriteriaInterface` contract is fixed
- Laravel 12+ `Cache::memo()` semantics for cast map caching
- The `cast_map` configuration in `config/api-toolkit.php`

---

## Assumptions

- The two concern groups in ApiRepository (query orchestration vs. casting/setting) genuinely share no methods or state
  beyond the `$casts` property, as confirmed by spike analysis
- Laravel's native `setAttribute()` correctly handles null values for scalar types in the way consumers expect (or the
  collaborator preserves existing null-safety where it differs)
- The `setAttributes()` → save → sync lifecycle ordering is the only coupling point between the two concerns
- The recent resource layer decomposition provides a proven collaborator-extraction pattern that can be adapted

---

## Risks

| Risk                                                             | Impact                                                                          | Likelihood | Mitigation                                                                                                                       |
|------------------------------------------------------------------|---------------------------------------------------------------------------------|------------|----------------------------------------------------------------------------------------------------------------------------------|
| Null-handling behavioural change when simplifying scalar setters | Existing consumer code that relies on null→value coercion breaks silently       | Medium     | Audit each setter's null-handling against Laravel native; preserve differing behaviour explicitly; verify with integration tests |
| Enum validation change breaks consumers passing raw values       | Consumers passing string values instead of enum instances get validation errors | Low        | Document the correction; enum validation is the correct Laravel behaviour                                                        |
| Cast cache key format changes during extraction                  | Cached cast maps from prior deployments become stale                            | Low        | Preserve the same cache key format and prefix in the collaborator; no key format change                                          |

---

## Out of Scope

- Changing the `cast_map` configuration format or defaults
- Modifying the base `Repository` class from sinemacula/laravel-repositories
- Extracting schema introspection into a shared service (covered by PRD 03-schema-introspection-service)
- Decomposing ApiCriteria (covered by PRD 04-apicriteria-decomposition)
- Adding new cast types or relation type support
- Changing the reflection-based relation detection approach (it's necessary — no Laravel native alternative exists)

---

## Release Criteria

- `composer test` passes with no assertion modifications
- `composer check` passes (PHPStan level 8, all linters)
- ApiRepository line count is below 150 lines
- Casting collaborator is independently instantiable and testable
- No public method signature changes on ApiRepository (delegation is internal)

---

## Traceability

| Artifact             | Path                                                                                                                                                                                                              |
|----------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Intake Brief         | `.sinemacula/blueprint/workflows/repository-criteria-decomposition/intake-brief.md`                                                                                                                               |
| Relevant Spikes      | `.sinemacula/blueprint/workflows/repository-criteria-decomposition/spikes/spike-responsibility-mapping.md`, `.sinemacula/blueprint/workflows/repository-criteria-decomposition/spikes/spike-casting-and-cache.md` |
| Problem Map Entry    | Concern Isolation & Testability > Problem 2: ApiRepository mixes query orchestration with attribute casting                                                                                                       |
| Prioritization Entry | Rank 4: P2 — ApiRepository mixes concerns (P0, Total 7)                                                                                                                                                           |

---

## References

- Traces to: [Prioritization](.sinemacula/blueprint/workflows/repository-criteria-decomposition/prioritization.md) —
  Rank 4
- Intake Brief: `.sinemacula/blueprint/workflows/repository-criteria-decomposition/intake-brief.md`
- P1 fold-in: P8 (Scalar setters marginal value) addressed in the Scalar setter simplification requirement
