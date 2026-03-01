# PRD: 01 ApiResource Decomposition

Decompose the monolithic ApiResource class into focused, single-responsibility collaborators so that package maintainers, contributors, and consumers can understand, test, and evolve each concern independently -- without changing the external query parameter or JSON response contracts.

---

## Governance

| Field     | Value                                                                                                       |
|-----------|-------------------------------------------------------------------------------------------------------------|
| Created   | 2026-02-27                                                                                                  |
| Status    | approved                                                                                                    |
| Owned by  | Ben                                                                                                         |
| Traces to | [Prioritization](.blueprint/workflows/apiresource-decomposition/prioritization.md) -- Ranks 1-9 (all P0 problems) |

---

## Overview

The ApiResource class is a 976-line, 38+ method monolith that owns five distinct responsibilities: schema compilation, eager-load planning, field resolution, value resolution, and guard evaluation. This concentration forces every developer who touches the resource layer to navigate and reason about unrelated concerns, makes it impossible to test any single behavior in isolation, and couples the repository layer directly to resource-layer static methods. The compiled schema -- the central shared data structure consumed by four of five responsibility groups -- is an untyped associative array with no compile-time safety, and nine protected methods with zero overrides create an ambiguous backward-compatibility surface.

This PRD specifies a unified refactor that decomposes ApiResource into focused collaborators, introduces typed contracts for shared data, decouples the repository layer from resource internals, and enables isolated testing of each concern. The refactor preserves the existing query parameter input format and JSON response output format -- no consuming application should observe any behavioral change. All internal structure, class hierarchies, composition patterns, and protected method surfaces are in scope for change.

The refactor is warranted now because the monolithic structure creates compounding maintenance costs: each new feature or bug fix in the resource layer requires understanding the entire class, and the cross-layer coupling between resources and repository criteria blocks independent evolution of both layers. The nine P0 problems identified through three research spikes share a common root cause (the monolithic class structure) and are most effectively solved together as a unified refactor rather than as incremental patches.

---

## Target Users

| Persona              | Description                                                                          | Key Need                                                                        |
|----------------------|--------------------------------------------------------------------------------------|---------------------------------------------------------------------------------|
| Package Maintainer   | Developer responsible for evolving and maintaining the laravel-api-toolkit codebase   | Modify one concern without risk of breaking unrelated concerns                  |
| Package Contributor  | Developer submitting PRs to add features or fix bugs in the toolkit                  | Understand and test a single concern without navigating a 976-line class         |
| Package Consumer     | Developer who extends ApiResource in their own application to define API resources   | Define resources with the same minimal contract (schema, resource type, defaults) and have confidence that the package's internal refactoring does not break their code |

**Primary user:** Package Maintainer

---

## Goals

- Each concern within the resource layer can be understood, modified, and tested without knowledge of unrelated concerns
- The repository layer and the resource serialization layer can evolve independently, connected only through explicit contracts
- Shared data structures between components have compile-time type safety enforced by static analysis
- The subclass contract for package consumers remains minimal and stable
- All existing tests continue to pass, confirming behavioral equivalence with the pre-refactor implementation

## Non-Goals

- Changing the query parameter input format accepted from frontends
- Changing the JSON response output format delivered to frontends
- Adding new serialization features (new field types, new filter operators, new query capabilities)
- Optimizing runtime performance beyond the current baseline (performance must not regress, but active optimization is out of scope)
- Expanding the resource schema system with new definition types
- Redesigning the API query parsing middleware or its output format

---

## Problem

**User problem:** A maintainer or contributor who needs to modify how eager-load maps are built must open a 976-line class, navigate past schema caching, field resolution, value resolution, and guard evaluation code, and reason about cross-cutting dependencies between all five concerns. A test author who wants to verify guard evaluation behavior must scaffold a complete resource with a model, schema, resource type constant, and potentially related models -- there is no way to test the guard concern in isolation. A contributor debugging unexpected eager-load behavior must understand that the eager-load planner reaches back into the field resolver on child resources during recursion, an implicit coupling that is not visible from any interface or contract.

**Business problem:** The monolithic structure creates compounding maintenance costs for an open-source package where code quality directly affects adoption and contributor retention. Each change to the resource layer carries elevated risk because of the interleaved responsibilities and implicit contracts. The cross-layer coupling between the resource and repository layers prevents the team from evolving either layer independently. The untyped schema hub means that changes to schema structure can only be validated at runtime, which is at odds with the package's PHPStan level 8 standard.

**Current state:** All five responsibilities live in a single class. Developers must read and understand the entire 976-line file to work on any single concern. The compiled schema is an untyped associative array with 12+ well-known but undocumented keys. The repository layer calls five static methods directly on resource classes. Nine protected methods are neither clearly internal nor clearly extension points. The schema cache can only be cleared in tests via reflection. The constructor orchestrates three responsibilities in a 50-line block that is the densest entanglement point in the class.

**Evidence:**

- [Spike: Responsibility Mapping](.blueprint/workflows/apiresource-decomposition/spikes/spike-responsibility-mapping.md) -- Finding 7: line budget distribution across five groups; Findings 1-5: individual group mappings
- [Spike: Dependency Analysis](.blueprint/workflows/apiresource-decomposition/spikes/spike-dependency-analysis.md) -- Finding 2: compiled schema as central untyped hub; Finding 8: no circular dependencies (DAG); Finding 10: implicit schema contract
- [Spike: Public API Surface](.blueprint/workflows/apiresource-decomposition/spikes/spike-public-api-surface.md) -- Finding 4: nine protected methods with zero overrides; Finding 8: five static method calls from ApiCriteria into ApiResource
- [Problem Map](.blueprint/workflows/apiresource-decomposition/problem-map.md) -- all six clusters

---

## Proposed Solution

After the refactor, a package maintainer working on eager-load planning opens a focused module that contains only the traversal logic and its helper methods. The module's inputs and outputs are defined by typed contracts -- the maintainer can see exactly what data it receives (typed schema definitions, a field list) and what it produces (eager-load arrays). The maintainer modifies the traversal logic, writes a unit test that exercises only the eager-load concern with a lightweight test setup, and verifies the change without touching or understanding the value resolution, guard evaluation, or schema compilation code.

A contributor debugging why a specific field is missing from the response can trace the issue through discrete steps: first checking whether the field resolver includes the field in the active field list, then whether the guard evaluator permits it, then whether the value resolver produces the expected value. Each step can be examined and tested independently. The contributor does not need to understand the eager-load planner or the schema compiler to debug a field resolution issue.

A package consumer who extends ApiResource in their application continues to define a resource type, a default field list, and a schema method -- the same minimal contract as before. The consumer's existing resource classes continue to work without modification. The JSON responses their application produces are identical to the pre-refactor output.

A maintainer working on the repository criteria layer can modify how criteria are applied without knowing the internal structure of the resource layer. The criteria layer depends on an explicit contract for obtaining resource metadata (field lists, eager-load maps) rather than reaching directly into resource class static methods.

### Key Capabilities

- A maintainer can work on any single resource concern (schema compilation, eager-load planning, field resolution, value resolution, or guard evaluation) without navigating or understanding the others
- A test author can write unit tests for any single concern with a lightweight setup that does not require scaffolding a complete resource, model, and relation graph
- A contributor can trace the data flow between concerns through typed contracts rather than reverse-engineering untyped associative arrays
- A maintainer can modify the repository criteria layer independently of the resource serialization layer
- A package consumer's existing resource subclasses continue to work without modification
- A test author can clear schema caches between test cases without reflection hacks
- A maintainer can determine which methods are stable extension points versus internal implementation details by examining the public API surface, which no longer includes ambiguous protected methods on the monolithic class
- A maintainer can modify how child resources participate in eager-load planning without implicitly depending on the field resolution concern's internal API

---

## Requirements

### Must Have (P0)

- **Single-concern modules:** A maintainer can open any single concern (schema compilation, eager-load planning, field resolution, value resolution, guard evaluation) and find only the code relevant to that concern, without unrelated responsibilities interleaved in the same scope.
  - **Acceptance criteria:** No single class or module in the resource layer exceeds 300 lines of code (excluding docblocks and blank lines). Each concern's code is physically separated from the others. A developer navigating to any responsibility does not encounter methods belonging to a different responsibility in the same file.

- **Typed schema contracts:** A contributor working with the compiled schema receives typed definitions rather than untyped associative arrays. Static analysis catches schema contract violations at analysis time, not at runtime.
  - **Acceptance criteria:** PHPStan level 8 analysis passes with zero errors. The compiled schema definitions are represented by typed constructs (not plain arrays) that enforce the presence and types of all expected fields (accessor, compute, relation, resource, fields, constraint, extras, guards, transformers, metric, key, default). Any consumer that accesses a non-existent or incorrectly-typed schema field fails static analysis.

- **Decoupled repository layer:** A maintainer can modify how resource metadata (field lists, eager-load maps, count maps) is provided to the repository criteria layer without modifying resource class internals, and vice versa.
  - **Acceptance criteria:** The repository criteria layer does not call any method directly on a resource class. The criteria layer depends on an explicit contract (interface or service) for obtaining resource metadata. A developer can substitute a different implementation of this contract without modifying the resource layer.

- **Isolated testability:** A test author can write unit tests for any single concern (schema compilation, eager-load planning, field resolution, value resolution, guard evaluation) with a setup that exercises only that concern.
  - **Acceptance criteria:** Each concern has at least one unit test that does not instantiate a full ApiResource subclass. Test setup for any single concern does not require constructing models, loading relations, or defining a complete resource schema unless that concern directly operates on those objects.

- **Constructor clarity:** A maintainer can understand the resource initialization sequence without navigating a dense 50-line orchestration block that interleaves three responsibilities.
  - **Acceptance criteria:** The resource initialization path has a clear, linear sequence where each step corresponds to a single concern. No single method in the initialization path coordinates more than two collaborators. The initialization sequence is documented by its structure (method/collaborator names reveal the steps) rather than requiring line-by-line reading of a monolithic block.

- **Schema cache testability:** A test author can reset the schema cache between test cases using a documented, public mechanism rather than reflection.
  - **Acceptance criteria:** Tests can clear the schema cache without using `ReflectionProperty` or any reflection API. The cache reset mechanism is available as a public method or through a test-support utility that does not require knowledge of internal property names.

- **Consolidated field resolution:** A developer working on field resolution finds all relevant state (active fields, excluded fields, fixed fields, all-fields flag) and logic (resolution, defaults, includes/excludes) in a single location rather than split across two class hierarchies.
  - **Acceptance criteria:** Field resolution state and logic are co-located. A developer does not need to navigate to a separate parent class to find field state properties. The mutation methods (for setting fields, excluding fields, enabling all-fields mode) and the resolution logic (determining which fields appear in the response) are accessible from the same module or clearly connected through a typed contract.

- **Decoupled eager-load planning:** A maintainer can modify how eager-load maps are built for child resources without depending on the field resolution concern's internal methods.
  - **Acceptance criteria:** The eager-load planning concern does not call field resolution methods (such as default field lookups or all-field lookups) directly on child resource classes. Instead, it receives the information it needs through a typed contract or data parameter. A developer can modify how child fields are resolved without risk of breaking the eager-load traversal logic.

- **Explicit extension surface:** A package consumer or downstream maintainer can determine which methods and properties are intended as stable extension points versus internal implementation details, without relying on the ambiguity of protected visibility on a large class.
  - **Acceptance criteria:** The refactored resource layer has a documented subclass contract consisting of at most five extension points (resource type, default fields, schema definition, and at most two others). Methods that were previously protected with zero overrides are no longer exposed as protected on the primary resource class. The subclass contract is enforceable by the type system or by abstract declarations.

### Should Have (P1)

- **Interface completeness:** The public contract (interface) for resource classes accurately reflects all methods that external consumers depend on, so that alternative implementations and type-safe dependency injection are possible.
  - **Acceptance criteria:** Every method called by external consumers (repository criteria, resource collections, polymorphic resources, controllers) is declared on an interface. A developer can type-hint against the interface rather than the concrete class for all cross-layer interactions.

- **Dead code removal:** Unused methods that are remnants of prior implementations are removed, reducing cognitive noise.
  - **Acceptance criteria:** No method in the resource layer is annotated with `@codeCoverageIgnore` due to being unreachable. Every method has at least one test exercising it or is reachable from a tested code path.

- **Clear shared-predicate ownership:** Methods that serve multiple concerns (such as count-inclusion predicates) have a clear, single owner rather than ambiguous dual ownership.
  - **Acceptance criteria:** No method in the resource layer is called from two different concern modules without being part of an explicit shared contract. Each method belongs to exactly one concern or to a well-defined shared utility.

- **Type-safe resource type enforcement:** The requirement for resource subclasses to define a resource type identifier is enforced at the type system level rather than only at runtime.
  - **Acceptance criteria:** A resource subclass that omits the resource type identifier fails static analysis (PHPStan level 8) rather than throwing a runtime exception. The enforcement mechanism does not require developers to remember a runtime convention.

### Nice to Have (P2)

- **Size balance:** The decomposed concerns are individually sized proportional to their complexity, with the largest concern (eager-load planning) no longer dominating the resource layer's code surface.

- **Constructor signature alignment:** The resource constructor signature is closer to Laravel's standard JsonResource constructor convention, reducing surprise for developers familiar with the framework.

---

## Success Criteria

| Metric | Baseline | Target | How Measured |
|--------|----------|--------|--------------|
| Largest single class in resource layer (lines of code, excluding docblocks and blank lines) | 976 lines (ApiResource) | Under 300 lines | Count non-blank, non-docblock lines per class using static analysis or `phploc` |
| Number of responsibility groups in the largest resource-layer class | 5 (all in ApiResource) | 1 | Code review: verify each class addresses a single concern |
| PHPStan level 8 errors | 0 | 0 | Run `composer check` (includes PHPStan level 8) |
| Test suite pass rate | 100% | 100% | Run `composer test` -- all existing tests pass |
| Direct static method calls from repository criteria to resource classes | 5 (in ApiCriteria) | 0 | Search repository layer source for direct resource class method calls |
| Reflection API usage in test cache cleanup | 2+ instances (ReflectionProperty on schemaCache) | 0 | Search test files for `ReflectionProperty` or `ReflectionClass` targeting resource-layer cache properties |
| Protected methods with zero overrides on the primary resource class | 9 (plus 3 from OrdersFields trait) | 0 -- all remaining protected methods are intentional extension points with documented purpose | Search for protected methods on the primary resource class; verify each has a documented rationale or is overridden by at least one subclass |
| Files a developer must open to understand field resolution | 2 (BaseResource + ApiResource) | 1 | Code review: verify field resolution state and logic are co-located |

---

## Dependencies

- The refactored code must continue to be compatible with PHP ^8.3
- The refactored code must continue to pass PHPStan level 8 static analysis and all `composer check` quality gates
- The `sinemacula/laravel-repositories` package provides the base repository pattern that `ApiCriteria` extends; changes to the criteria layer must remain compatible with the base repository contract
- Existing test fixtures (UserResource, PostResource, TagResource, OrganizationResource) define the behavioral contract that the refactor must preserve

---

## Assumptions

- The refactor has full autonomy to restructure all internal classes, methods, inheritance hierarchies, and composition patterns within the resource and criteria layers
- The only fixed contracts are the query parameter input format (from frontend) and the JSON response output format (to frontend)
- Existing tests define the expected behavioral contract; tests may be reorganized or supplemented but the behaviors they verify must continue to pass
- No external consuming application will be broken by changes to internal (non-public) APIs, because the package has not yet made a 1.0 semver commitment on protected method stability
- The `BaseResource` class, `OrdersFields` trait, `ApiCriteria` class, `ApiResourceCollection`, and `PolymorphicResource` are all within scope for modification
- Performance of the `resolve()` path must not regress measurably, but active performance optimization is not a goal of this refactor

---

## Risks

| Risk | Impact | Likelihood | Mitigation |
|------|--------|------------|------------|
| Unknown downstream consumers override protected methods that the refactor removes or reclassifies | Downstream consuming applications break silently after upgrading the package | Medium | Document all visibility changes in the upgrade guide. Publish the refactor as a new major version or a clearly communicated breaking change. Provide a migration guide for any reclassified methods. |
| Performance regression in the resolve path due to additional object allocations for collaborators | API response latency increases for high-traffic applications serving large collections | Low | Establish a baseline benchmark for `resolve()` throughput before the refactor. Measure post-refactor performance against the baseline. Design collaborator instantiation strategies (per-class singletons, shared instances) to minimize per-item allocation if benchmarks show regression. |
| Behavioral drift during refactor -- subtle differences in edge-case behavior between the monolithic and decomposed implementations | Consuming applications observe unexpected changes in JSON output for edge cases (null relations, empty field lists, guarded counts) | Medium | Run the full existing test suite as the primary regression gate. Supplement with additional edge-case tests before beginning the refactor. Use the existing test fixtures as golden-output validators. |
| Refactor scope exceeds estimate due to undiscovered coupling between concerns | The refactor takes significantly longer than planned, blocking other work | Low | The dependency analysis spike confirmed the dependency graph is a DAG with no circular dependencies and catalogued all cross-concern data flows. Begin with the lowest-coupling extractions (guard evaluation, schema compilation) to build confidence before tackling the more coupled concerns (eager-load planning, value resolution). |

---

## Out of Scope

- Changes to the query parameter format accepted by the `ParseApiQuery` middleware
- Changes to the JSON response format produced by resource serialization
- New features in the schema definition system (new field types, new definition options)
- Performance optimization beyond maintaining the current baseline
- Changes to the logging, exception handling, or service layers of the toolkit
- Redesign of the API query parser or its singleton binding
- Changes to sibling packages (`sinemacula/laravel-repositories`, `sinemacula/laravel-resource-exporter`) beyond what is necessary to decouple the criteria layer
- Adding support for new PHP versions or dropping support for PHP 8.3
- Redesigning the export functionality or notification logging

---

## Open Questions

None. All questions from the intake brief have been resolved through the three research spikes:

- Responsibility boundaries are validated (Spike: Responsibility Mapping)
- Hidden dependencies are mapped and confirmed to be a DAG (Spike: Dependency Analysis)
- Public API surface and override usage are catalogued (Spike: Public API Surface)
- Stakeholder confirmed full internal restructuring autonomy (Intake Brief, Freedoms section)
- Performance risk is acknowledged as a risk with mitigation rather than an open question, since the spike confirmed object allocation costs are the primary concern and benchmarking before/after is the accepted mitigation strategy

---

## Release Criteria

- All existing tests pass without modification to their assertions (test organization and setup may change, but verified behaviors must remain identical)
- `composer check` passes with zero errors (PHPStan level 8, PHP-CS-Fixer, CodeSniffer)
- `composer test` passes with zero failures
- No single class in the resource layer exceeds 300 lines (excluding docblocks and blank lines)
- Each class in the resource layer addresses a single responsibility, verified by code review
- The repository criteria layer contains zero direct static method calls to resource classes
- The test suite contains zero `ReflectionProperty` or `ReflectionClass` calls targeting resource-layer cache properties
- The compiled schema definitions are represented by typed constructs that pass PHPStan level 8 analysis
- A resolve-path benchmark shows no measurable regression compared to the pre-refactor baseline (measured as mean response time for a 100-item collection serialization)

---

## Traceability

| Artifact             | Path                                                                                                                |
|----------------------|---------------------------------------------------------------------------------------------------------------------|
| Intake Brief         | `.blueprint/workflows/apiresource-decomposition/intake-brief.md`                                                   |
| Spike: Responsibility Mapping | `.blueprint/workflows/apiresource-decomposition/spikes/spike-responsibility-mapping.md`                  |
| Spike: Dependency Analysis    | `.blueprint/workflows/apiresource-decomposition/spikes/spike-dependency-analysis.md`                     |
| Spike: Public API Surface     | `.blueprint/workflows/apiresource-decomposition/spikes/spike-public-api-surface.md`                      |
| Problem Map          | `.blueprint/workflows/apiresource-decomposition/problem-map.md`                                                    |
| Prioritization       | `.blueprint/workflows/apiresource-decomposition/prioritization.md`                                                 |

### Problem Map to PRD Mapping

| PRD Requirement | Problem Map Entry | Prioritization Rank |
|-----------------|-------------------|---------------------|
| Single-concern modules | Cluster: Single Responsibility Violations > P1: Monolithic class with five interleaved responsibilities | Rank 1 (P0, score 8) |
| Typed schema contracts | Cluster: Implicit Contracts and Missing Type Safety > P6: Compiled schema array is an untyped associative array | Rank 2 (P0, score 8) |
| Decoupled repository layer | Cluster: Cross-Layer Coupling > P4: Repository layer depends directly on resource class static methods | Rank 3 (P0, score 8) |
| Isolated testability | Cluster: Testability Friction > P15: Inability to test individual concerns in isolation | Rank 4 (P0, score 8) |
| Constructor clarity | Cluster: Single Responsibility Violations > P2: Constructor orchestrates three responsibilities in a 50-line block | Rank 5 (P0, score 8) |
| Schema cache testability | Cluster: Testability Friction > P14: Schema cache clearing requires reflection hacks in tests | Rank 6 (P0, score 8) |
| Consolidated field resolution | Cluster: Extensibility and Inheritance Design > P10: FieldResolver state split across BaseResource and ApiResource | Rank 7 (P0, score 7) |
| Decoupled eager-load planning | Cluster: Cross-Layer Coupling > P5: EagerLoadPlanner reaches into FieldResolver on child resources | Rank 8 (P0, score 7) |
| Explicit extension surface | Cluster: Extensibility and Inheritance Design > P9: Nine protected methods with zero overrides act as pseudo-public API | Rank 9 (P0, score 7) |

---

## References

- Traces to: [Prioritization](.blueprint/workflows/apiresource-decomposition/prioritization.md) -- Ranks 1-9
- Intake Brief: `.blueprint/workflows/apiresource-decomposition/intake-brief.md`
