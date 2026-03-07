# PRD: 04 Operator Extensibility

Consumers can register, override, and remove filter operators without modifying package source.

---

## Governance

| Field     | Value                                                                                                     |
|-----------|-----------------------------------------------------------------------------------------------------------|
| Created   | 2026-03-06                                                                                                |
| Status    | approved                                                                                                  |
| Owned by  | Ben                                                                                                       |
| Traces to | [Prioritization](../../.sinemacula/blueprint/workflows/repository-criteria-decomposition/prioritization.md) — Rank 3: No extension mechanism for operators |

---

## Overview

Consumers of laravel-api-toolkit who need domain-specific filter operators (e.g. spatial queries, full-text search, date range shortcuts) cannot add them without forking or replacing the entire criteria class. Fifteen built-in operators are hardcoded in private property arrays and dispatched via a closed `match` expression with five specific extension barriers preventing consumer customisation.

This PRD defines an extensible operator system where consumers can register custom operators, override or remove built-in ones, and where each operator owns its own value transformation. The built-in operator set continues to work unchanged — behavioural equivalence is preserved.

This work depends on the prior decomposition of ApiCriteria (PRD-03 schema introspection extraction and the ApiCriteria decomposition) which provides the decomposed filtering component that the extensible operator mechanism will be designed into. The extensibility should be a first-class feature of the new filtering architecture, not a retrofit onto the monolith.

---

## Target Users

| Persona              | Description                                                                                       | Key Need                                                        |
|----------------------|---------------------------------------------------------------------------------------------------|-----------------------------------------------------------------|
| Package Consumer     | Laravel developer using laravel-api-toolkit to build REST APIs with domain-specific filtering needs | Register custom filter operators without forking the package    |
| Package Maintainer   | Maintainer of laravel-api-toolkit evolving the built-in operator set                               | Add, modify, or deprecate operators without touching dispatch logic |

**Primary user:** Package Consumer

---

## Goals

- Consumers can extend the filter operator set without modifying package source code
- Each operator is a self-contained unit that owns its own value handling
- The built-in operator set works identically to today — no behavioural regressions

## Non-Goals

- Changing the frontend query parameter format (this is a fixed consumer contract)
- Providing a library of additional operators beyond the current 15 built-in ones
- Supporting consumer-defined logical operators (`$and`/`$or`) or structural filter extensions
- Redesigning the recursive filter dispatch architecture itself (that is P1's scope)

---

## Problem

**User problem:** Consumers building APIs with domain-specific needs (e.g. geospatial proximity, full-text relevance, date range shortcuts) must fork the package, bypass the filter system entirely, or apply Eloquent scopes manually — because there is no way to register a custom operator.

**Business problem:** The closed operator system limits the package's applicability. Consumers who outgrow the built-in operator set face a fork-or-abandon decision, reducing adoption and increasing maintenance burden for both the consumer and maintainer.

**Current state:** Five specific barriers prevent extension: (1) private operator maps, (2) hardcoded match expression, (3) private handler methods, (4) coupled column validation in the dispatch path, and (5) classification gating that only recognises known operator tokens. Additionally, each built-in operator handles value transformation differently with no unified contract — `$like` wraps with wildcards via a shared method, `$in` casts to array inline, `$between` validates a 2-element array, `$contains` has three paths, and `$null`/`$notNull` ignore the value entirely.

**Evidence:**

- [Spike: Filter Operator Patterns](../../.sinemacula/blueprint/workflows/repository-criteria-decomposition/spikes/spike-filter-operator-patterns.md) — F1 (four-way classification with hardcoded match), F2 (common handler input shape), F7 (five specific extension barriers), F8 (inconsistent value transformation)
- [Problem Map](../../.sinemacula/blueprint/workflows/repository-criteria-decomposition/problem-map.md) — Cluster: Filter Operator Extensibility, Problems P4 and P6

---

## Proposed Solution

A consumer who needs a custom filter operator (e.g. `$startsWith`) registers it during application bootstrap. The registration associates an operator token with a handler that defines how to apply the operator to a query and how to transform incoming values.

When a filter request arrives with the registered operator, the filtering system recognises the token, resolves the handler, and delegates to it — just as it does for built-in operators. The consumer's handler receives the query builder, the column, the value, and the filtering context, and applies the appropriate constraint.

Built-in operators are pre-registered and work identically to today. A consumer can override a built-in operator by registering a handler for the same token, or remove a built-in operator entirely.

### Key Capabilities

- Consumer registers a custom filter operator and it is available in API filter queries
- Consumer overrides a built-in operator's behaviour for their application
- Consumer removes a built-in operator they do not want exposed
- Each operator defines its own value validation and transformation
- Built-in operators produce identical query results to the current implementation

---

## Requirements

### Must Have (P0)

- **Custom operator registration:** Consumer can register a custom filter operator by associating an operator token with a handler
  - **Acceptance criteria:** A registered custom operator (e.g. `$startsWith`) is recognised in filter queries and applies the correct query constraint; an unregistered operator token is rejected or ignored consistently

- **Built-in operator equivalence:** All 15 built-in operators produce identical query behaviour after the extensibility mechanism is introduced
  - **Acceptance criteria:** All existing filter tests pass without modification to test assertions; query output for every built-in operator is unchanged

- **Operator override:** Consumer can replace the behaviour of a built-in operator by registering a handler for the same token
  - **Acceptance criteria:** When a consumer registers a handler for `$eq`, that handler is invoked instead of the built-in `$eq` behaviour

- **Handler-owned value transformation:** Each operator defines its own value validation and transformation rather than relying on a shared function
  - **Acceptance criteria:** A custom operator can validate and transform its input value (e.g. parse a date string, split a comma-separated list) without modifying any shared transformation logic (subsumes P6: inconsistent value transformation)

### Should Have (P1)

- **Operator removal:** Consumer can remove a built-in operator so that it is not available in filter queries
  - **Acceptance criteria:** After removal, a filter query using the removed operator token is rejected or treated as a column name (consistent with current behaviour for unrecognised tokens)

- **Logical context availability:** Custom operator handlers receive sufficient context to apply logical grouping correctly (i.e. whether the operator is within an `$and` or `$or` group)
  - **Acceptance criteria:** A custom operator within an `$or` group produces an `orWhere`-style constraint; within an `$and` or top-level group produces a `where`-style constraint

### Nice to Have (P2)

- **Closure-based registration:** Consumer can register a simple operator using a closure instead of a dedicated handler class
  - **Acceptance criteria:** A closure-registered operator behaves identically to a class-registered operator for the same logic

---

## Success Criteria

| Metric                          | Baseline                               | Target                                        | How Measured                                            |
|---------------------------------|----------------------------------------|-----------------------------------------------|---------------------------------------------------------|
| Extension barriers              | 5 barriers preventing operator extension | 0 barriers — consumers can register operators | Spike F7 barrier checklist verified as resolved         |
| Built-in operator test pass rate | 100% (current test suite)              | 100% (no regressions)                         | `composer test` — all existing filter tests pass        |
| Custom operator registration    | N/A — new capability                   | Consumer can register and use a custom operator in < 10 lines of code | Documentation example + integration test demonstrates registration |
| Value transformation consistency | 6 different transformation patterns across 15 operators | Each operator owns its own transformation via a unified contract | Code review confirms no shared transformation function with operator-specific branches |

---

## Dependencies

- **P3 — Schema Introspection Service** (PRD-03): Schema introspection must be extracted as a standalone service before the filtering component can be decomposed. The operator extensibility mechanism depends on the decomposed filtering component.
- **P1 — ApiCriteria Decomposition**: The filtering concern must be extracted from the ApiCriteria monolith before operator extensibility is designed in. Operator extensibility should be a first-class feature of the new filtering component, not retrofitted onto the existing 666-line class.

---

## Assumptions

- **Consumer pain is real but unverified:** The intake brief cites forking and manual scopes as workarounds, but no consumer codebases were analysed and no consumer interviews were conducted. The P4 impact score was reduced from 3 to 2 to reflect this evidence gap. If consumers do not actually need custom operators, this work has lower value than scored.
- **The built-in operator set is sufficient for most consumers:** The extensibility mechanism is for consumers with domain-specific needs, not a signal that the built-in set is inadequate.
- **The common handler input shape identified in Spike F2 (builder, column, value, logical context) is sufficient for custom operators:** If custom operators need additional context (e.g. model class, request metadata), the handler contract may need expansion.
- **Operator tokens follow the `$` prefix convention:** Custom operators use the same `$`-prefixed token format as built-in operators (e.g. `$startsWith`), preserving consistency with the query parameter format.

---

## Risks

| Risk                                              | Impact                                                          | Likelihood | Mitigation                                                                                          |
|---------------------------------------------------|-----------------------------------------------------------------|------------|-----------------------------------------------------------------------------------------------------|
| Over-engineered extension API without consumer input | Extensibility mechanism doesn't match real consumer needs       | Medium     | Keep the handler contract minimal; gather consumer feedback before adding advanced features          |
| Custom operators bypass column validation          | Security risk — operators could query non-searchable columns    | Medium     | Column validation must run before operator dispatch, not within individual handlers                  |
| Handler contract too narrow for future operators   | Consumers need context not available in the handler signature   | Low        | Design the context object to be extensible; start minimal, expand based on real needs               |
| Behavioural regression in built-in operators       | Existing API consumers experience broken filtering              | Low        | All existing tests must pass; built-in operators are the first consumers of the extensibility mechanism |

---

## Out of Scope

- New built-in operators beyond the current 15 — the mechanism enables them, but adding specific new operators is separate work
- Consumer-defined logical operators (`$and`/`$or`) or structural filter extensions
- Changes to the frontend query parameter format or JSON response format
- Changes to the `CriteriaInterface` contract from `sinemacula/laravel-repositories`
- Redesigning the recursive filter dispatch architecture (P1 scope)
- Relation filter extensibility (extending `$has`/`$hasnt` behaviour)

---

## Release Criteria

- All existing filter tests pass without modification to test assertions
- At least one integration test demonstrates registering and using a custom operator
- At least one integration test demonstrates overriding a built-in operator
- Static analysis passes (`composer check`) at PHPStan level 8
- No single class in the filtering component exceeds ~200 lines

---

## Traceability

| Artifact             | Path                                                                                                           |
|----------------------|----------------------------------------------------------------------------------------------------------------|
| Intake Brief         | `.sinemacula/blueprint/workflows/repository-criteria-decomposition/intake-brief.md`                            |
| Relevant Spikes      | `.sinemacula/blueprint/workflows/repository-criteria-decomposition/spikes/spike-filter-operator-patterns.md`, `.sinemacula/blueprint/workflows/repository-criteria-decomposition/spikes/spike-responsibility-mapping.md` |
| Problem Map Entry    | Filter Operator Extensibility > P4: No extension mechanism for filter operators, P6: Inconsistent value transformation |
| Prioritization Entry | Rank 3: P4 — No extension mechanism for operators (Total 7, P0); P6 folded in per P1 Tier Folding             |

---

## References

- Traces to: [Prioritization](../../.sinemacula/blueprint/workflows/repository-criteria-decomposition/prioritization.md) — Rank 3
- Intake Brief: `.sinemacula/blueprint/workflows/repository-criteria-decomposition/intake-brief.md`
