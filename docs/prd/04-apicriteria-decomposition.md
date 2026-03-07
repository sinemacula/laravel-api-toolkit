# PRD: 04 ApiCriteria Decomposition

Decompose ApiCriteria from a single 666-line god class into independent, single-responsibility components for filtering, ordering, eager loading, and limiting — each testable and evolvable in isolation.

---

## Governance

| Field     | Value                                                                                                                           |
|-----------|---------------------------------------------------------------------------------------------------------------------------------|
| Created   | 2026-03-06                                                                                                                      |
| Status    | approved                                                                                                                        |
| Owned by  | Ben                                                                                                                             |
| Traces to | [Prioritization](.sinemacula/blueprint/workflows/repository-criteria-decomposition/prioritization.md) — Rank 2: P1 |

---

## Overview

ApiCriteria is a 666-line class that handles five distinct concerns — filtering (66% of methods), schema introspection, eager loading, ordering, and limiting — in a single file with private methods throughout. Maintainers cannot test, modify, or reason about any single concern without reading and risking all the others. Contributors cannot work on filtering and ordering in parallel because they share the same class.

This PRD defines the decomposition of ApiCriteria into independent, focused components — one for each operational concern — wired together by a thin orchestrator. Each component implements the existing CriteriaInterface (or is composed behind it), can be unit-tested in isolation, and can evolve without risk to its siblings.

This work depends on the Schema Introspection Service (PRD 03), which extracts the shared column validation and relation detection logic that filtering and ordering both need. It must complete before this decomposition can begin. This PRD also folds in P5 (implicit context threading), formalising the filter recursion context into an explicit, structured representation so that nested filter logic is easier to understand, debug, and extend.

---

## Target Users

| Persona              | Description                                                                     | Key Need                                                                          |
|----------------------|---------------------------------------------------------------------------------|-----------------------------------------------------------------------------------|
| Package Maintainer   | Developer maintaining and evolving laravel-api-toolkit                          | Work on one concern (e.g. filtering) without reading or risking the other four     |
| Package Contributor  | Developer contributing bug fixes or enhancements to criteria-related code       | Understand a single concern by reading a small, focused component                  |
| Package Consumer     | Developer using laravel-api-toolkit who may extend or override criteria behaviour | Replace or extend individual criteria components without replacing the entire class |

**Primary user:** Package Maintainer

---

## Goals

- Each operational concern (filtering, ordering, eager loading, limiting) can be tested in isolation with no dependency on the other concerns
- A maintainer can modify filtering behaviour without reading or risking ordering, eager loading, or limiting code
- The recursive filter dispatch context is explicit and inspectable, making nested filter behaviour easier to debug
- The decomposed architecture preserves full behavioural equivalence with the current monolith

## Non-Goals

- Changing the public query parameter format or JSON response format
- Modifying the base Repository class or CriteriaInterface from sinemacula/laravel-repositories
- Adding new filter operators or operator extensibility (that is P4, which depends on this work)
- Changing the caching strategy or cache invalidation approach
- Decomposing ApiRepository (that is P2, which can proceed in parallel)
- Optimising query performance beyond preserving current behaviour

---

## Problem

**User problem:** Maintainers cannot work on any single concern within ApiCriteria without navigating a 666-line class that interleaves five responsibilities. Fixing a filtering bug requires reading past ordering, eager loading, and limiting code. Modifying eager loading risks regressions in filter behaviour because all concerns share private methods and state in the same class. Contributors cannot work on separate concerns in parallel because all changes touch the same file.

**Business problem:** The monolithic structure slows development velocity and increases regression risk for every criteria-related change. The upcoming operator extensibility work (P4) cannot be designed cleanly into the current monolith — it needs a focused filtering component to build upon.

**Current state:** All five concerns live in `ApiCriteria.php`. Filtering accounts for 66% of methods (17 of 27). Ordering and eager loading each account for ~4%. All internal methods are private, preventing selective extension or override. The `apply()` method orchestrates all concerns in sequence. Schema introspection is the shared dependency between filtering and ordering (to be extracted by PRD 03).

**Evidence:**

- Spike 1 (Responsibility Mapping) — F1: five distinct concern clusters identified with method percentages
- Spike 1 — F9: dependency graph shows filtering, ordering, eager loading, and limit as separable concerns
- Spike 1 — F2: recursive dispatch tree with hardcoded operators fully traced
- Spike 1 — F8: eager loading delegates cleanly to ResourceMetadataProvider
- Spike 1 — F6: CriteriaInterface + capability interfaces support decomposition natively
- Spike 3 (Filter Operator Patterns) — F3: implicit context threading through recursion
- Problem Map — Cluster: Concern Isolation & Testability, Problem 1

---

## Proposed Solution

After this change, a maintainer working on filter behaviour opens a focused filtering component rather than a 666-line class. They read only filtering logic, write tests that exercise only filtering, and submit changes that cannot regress ordering or eager loading.

**Filtering journey:** A maintainer debugging a `$between` filter opens the filtering component and sees only filter-related methods. The recursive dispatch, operator handling, and value transformation are all contained within this component. The filter context — which logical operator is active, whether the filter is inside a relation, and the nesting depth — is represented as a structured object that the maintainer can inspect during debugging.

**Ordering journey:** A maintainer adding a new ordering feature opens the ordering component, which contains only column validation (via the schema introspection service) and Eloquent `orderBy` application. No filtering code is visible.

**Eager loading journey:** A maintainer modifying eager load resolution opens the eager loading component, which delegates to the ResourceMetadataProvider. No filtering or ordering code is visible.

**Testing journey:** A contributor writing a test for ordering can instantiate just the ordering component, mock the schema introspection service, and verify ordering behaviour without setting up filters, eager loads, or limits.

**Orchestration journey:** The decomposed components are wired together by a thin orchestrator that applies each in sequence — maintaining the same execution order as today. From the repository's perspective, the criteria still implement CriteriaInterface as before.

### Key Capabilities

- Work on filtering independently from ordering, eager loading, and limiting
- Work on ordering independently from filtering, eager loading, and limiting
- Work on eager loading independently from filtering, ordering, and limiting
- Test each concern in isolation with mocked dependencies
- Inspect filter context during debugging to understand nesting, logical operators, and relation scope

---

## Requirements

### Must Have (P0)

- **Independent filtering component:** Maintainer can test, modify, and reason about filtering logic in a dedicated component that contains no ordering, eager loading, or limiting code.
  - **Acceptance criteria:** A test file exists that exercises filter application (simple filters, nested logical operators, relation filters, all built-in operators) without instantiating or depending on ordering, eager loading, or limiting logic. The filtering component's source file contains only filtering-related methods.

- **Independent ordering component:** Maintainer can test and modify ordering logic in a dedicated component that contains no filtering, eager loading, or limiting code.
  - **Acceptance criteria:** A test file exists that exercises ordering application (single column, multiple columns, with and without searchable column validation) without instantiating or depending on filtering, eager loading, or limiting logic.

- **Independent eager loading component:** Maintainer can test and modify eager loading logic in a dedicated component that contains no filtering, ordering, or limiting code.
  - **Acceptance criteria:** A test file exists that exercises eager load resolution (field-scoped eager loads, eager load counts) without instantiating or depending on filtering, ordering, or limiting logic.

- **Independent limiting component:** Maintainer can test and modify limit application in a dedicated component that contains no filtering, ordering, or eager loading code.
  - **Acceptance criteria:** A test file exists that exercises limit application (with and without a configured limit) without instantiating or depending on filtering, ordering, or eager loading logic.

- **Structured filter context:** Maintainer can inspect the logical operator state, relation scope, and nesting depth at any point in the recursive filter dispatch.
  - **Acceptance criteria:** During filter application, each handler receives a context object that reports: (a) the current logical operator (`$and` / `$or` / none), (b) whether the handler is operating inside a relation scope, and (c) the nesting depth. Tests verify that context values are correct at each level of a nested filter structure.

- **Orchestrated composition:** The decomposed components are wired together so that the repository layer applies criteria through the same interface as today.
  - **Acceptance criteria:** The repository's criteria application calls produce the same query builder state as the current monolithic ApiCriteria for all existing test scenarios.

- **Behavioural equivalence:** All existing unit and integration tests pass without modification to test assertions.
  - **Acceptance criteria:** `composer test` passes with no changes to existing test assertions. The full test suite exercises the same scenarios as before the decomposition.

### Should Have (P1)

- **Reduced class sizes:** Each decomposed component is significantly smaller and more focused than the original 666-line monolith.
  - **Acceptance criteria:** No single decomposed component exceeds 200 lines.

- **Parallel development enablement:** Contributors can work on separate concerns without merge conflicts.
  - **Acceptance criteria:** Each concern lives in a separate file. Changes to filtering do not touch the same file as changes to ordering.

### Nice to Have (P2)

- **Component replaceability:** A consumer can replace an individual component (e.g. swap the ordering implementation) without replacing the entire criteria system.

---

## Success Criteria

| Metric | Baseline | Target | How Measured |
|--------|----------|--------|--------------|
| Concern-isolated test files | 0 — all criteria tests exercise the monolith | >= 4 dedicated test files (filtering, ordering, eager loading, limiting) | Test file inventory |
| Largest criteria-related class | 666 lines (ApiCriteria) | <= 200 lines per component | `wc -l` on each component file |
| Cross-concern coupling | 5 concerns in 1 file — any change risks all 5 | Each concern in a separate file with no direct dependency on sibling concerns | Code review: no imports between sibling components |
| Existing test pass rate | 100% | 100% | `composer test` exit code |

---

## Dependencies

- **PRD 03 — Schema Introspection Service** must be completed first. Filtering and ordering both depend on column validation and relation detection, which PRD 03 extracts into a shared injectable service.
- `sinemacula/laravel-repositories` — the base Repository class and CriteriaInterface are fixed; no changes permitted.
- Laravel 12+ — required framework version.

---

## Assumptions

- The Schema Introspection Service (PRD 03) will be available as an injectable service before this work begins
- The CriteriaInterface contract from the sibling package is sufficient for the decomposed architecture (confirmed in Spike 1, F6 — capability interfaces support composition)
- The current execution order of concerns in `apply()` (filters, eager loading, limit, order) must be preserved for behavioural equivalence
- Breaking changes to internal APIs are acceptable; only the public query parameter format and response format are fixed contracts

---

## Risks

| Risk | Impact | Likelihood | Mitigation |
|------|--------|------------|------------|
| Decomposition subtly changes query builder state due to execution order or scope differences | Incorrect query results for edge-case filter/order combinations | Medium | Run full integration test suite against decomposed implementation; add before/after query comparison tests for complex nested filters |
| Formalising filter context introduces overhead in the recursive dispatch hot path | Measurable performance regression on deeply nested filter trees | Low | Context object is a lightweight value object; profile with realistic filter payloads before and after |
| Consumers who extend or override ApiCriteria via subclassing break on the new architecture | Consumer applications fail after upgrade | Low | ApiCriteria's private visibility already prevents meaningful subclassing; document the change in release notes with migration guidance |

---

## Out of Scope

- Adding new filter operators or an operator extension mechanism (that is P4, which depends on this work)
- Decomposing ApiRepository into query orchestration and attribute casting components (that is P2)
- Changing the caching strategy or invalidation approach
- Modifying the ResourceMetadataProvider interface or the resource/schema layer
- Changing the query parameter format or response format
- Extracting schema introspection (already covered by PRD 03)
- Removing unused CacheKeys enum constants (trivial cleanup, not PRD-worthy)

---

## Release Criteria

- All existing unit and integration tests pass (`composer test`)
- Static analysis passes at PHPStan level 8 (`composer check`)
- Each decomposed concern has a dedicated unit test file
- No component exceeds 200 lines
- The structured filter context is tested at multiple nesting levels
- ApiCriteria (or its successor orchestrator) produces identical query builder state for all existing test scenarios

---

## Traceability

| Artifact             | Path                                                                                                    |
|----------------------|---------------------------------------------------------------------------------------------------------|
| Intake Brief         | `.sinemacula/blueprint/workflows/repository-criteria-decomposition/intake-brief.md`                     |
| Relevant Spikes      | `.sinemacula/blueprint/workflows/repository-criteria-decomposition/spikes/spike-responsibility-mapping.md` (F1, F2, F6, F8, F9), `.sinemacula/blueprint/workflows/repository-criteria-decomposition/spikes/spike-filter-operator-patterns.md` (F3) |
| Problem Map Entry    | Concern Isolation & Testability > Problem 1: ApiCriteria conflates five concerns in one class; also folds in Filter Extensibility > Problem 5: Implicit context threading through filter recursion |
| Prioritization Entry | Rank 2: P1 — ApiCriteria conflates five concerns (P0, Total 8)                                         |

---

## References

- Traces to: [Prioritization](.sinemacula/blueprint/workflows/repository-criteria-decomposition/prioritization.md) — Rank 2
- Intake Brief: `.sinemacula/blueprint/workflows/repository-criteria-decomposition/intake-brief.md`
- Prerequisite: [PRD 03 — Schema Introspection Service](docs/prd/03-schema-introspection-service.md)
