# PRD: Service Result Value Object

Replace the bare `bool` return from `ServiceInterface::run()` and the ambiguous `?bool` from `getStatus()` with an
immutable `ServiceResult` value object carrying status, result data, and exception context -- giving callers a single,
self-describing return value.

---

## Governance

| Field     | Value                                                                                                          |
|-----------|----------------------------------------------------------------------------------------------------------------|
| Created   | 2026-03-17                                                                                                     |
| Status    | approved                                                                                                       |
| Owned by  | Product Analyst                                                                                                |
| Traces to | [Prioritization](../../.sinemacula/blueprint/workflows/service-result-value-object/prioritization.md)          |
| Problems  | P1 (no structured result data), P4 (tri-state ?bool undocumented), P2 (lost exception context), P5 (static analysis can't distinguish states) |

---

## Background

`ServiceInterface` defines two methods: `run(): bool` and `getStatus(): ?bool`. The concrete `Service` class
orchestrates a concern pipeline around a core lifecycle (`prepare` → `handle` → `success`/`failed`), and the pipeline
returns `bool` throughout -- from `handle()` through each `ServiceConcern::execute()` back to `run()`.

This creates three problems for developers consuming services:

- **Opaque outcomes:** `run()` returns `true` or `false` with no structured data. If a service creates a resource,
  computes a value, or transforms a dataset, callers must reach into the service's internals (public properties, payload
  mutation, or external exception catching) to retrieve the result. This couples callers to concrete service classes
  rather than the interface.
- **Ambiguous status:** `getStatus()` returns `?bool` encoding three states: `null` (not yet run), `true` (success),
  `false` (failure). This semantic is undocumented on the interface and only discoverable by reading the concrete class.
  PHPStan cannot enforce exhaustive handling of the three states.
- **Lost exception context:** When the service catches an exception, `failed()` receives the `Throwable` but it is not
  stored or exposed. Callers who need to inspect what went wrong must catch exceptions externally, bypassing the
  service's own failure handling.

The concern pipeline (`ServiceConcern::execute()`) also returns `bool`. Changing the `run()` return type does not
require changing the concern contract if the `ServiceResult` is constructed at the `run()` level after the pipeline
completes.

This is a v2 change. Breaking changes to `ServiceInterface` are acceptable.

---

## User Capabilities

### UC-1: Service execution returns a structured result object

When a developer calls `run()` on a service, they receive a structured result object instead of a bare `bool`, allowing
them to inspect the outcome without reaching into service internals.

**Acceptance criteria:**

- `ServiceInterface::run()` returns a `ServiceResult` value object instead of `bool`
- `ServiceResult` is immutable -- all properties are set at construction time and cannot be modified
- `ServiceResult` carries a status field using a dedicated enum (see UC-2)
- `ServiceResult` carries an optional data field for output produced by the service (e.g., a created model, a computed
  value)
- `ServiceResult` carries an optional exception field for failure context (see UC-3)
- The data field is accessible to callers via the result object without needing to know the concrete service class
- Services that produce no output return a `ServiceResult` with a `null` data field -- the result object is always
  returned, never `null` itself
- `getStatus()` is removed from `ServiceInterface` -- status is accessed via `ServiceResult::status`

### UC-2: Service status uses an explicit enum instead of nullable bool

When a developer checks the outcome of a service execution, the status is represented by an enum with self-documenting
cases, enabling exhaustive matching and static analysis verification.

**Acceptance criteria:**

- A `ServiceStatus` enum provides explicit cases for the service lifecycle states, replacing the undocumented `?bool`
  tri-state (`null`/`true`/`false`)
- PHPStan level 8 can verify exhaustive handling of all status cases in `match` expressions
- The enum cases are self-documenting -- a developer reading the code can understand each state without consulting
  external documentation
- `ServiceResult` provides convenience methods for quick status checks (e.g., checking success or failure) so callers
  can branch without a full `match` when only one outcome matters

### UC-3: Failed service results capture exception context

When a service execution fails, the exception that caused the failure is captured in the result object, allowing callers
to inspect error details without catching exceptions externally.

**Acceptance criteria:**

- When the core lifecycle (`prepare()` or `handle()`) throws, the exception is captured in the `ServiceResult`
- Callers can access the captured exception via the result object
- The `failed()` lifecycle hook continues to receive the exception as before -- capturing the exception in the result
  does not change the hook's behavior
- Exception propagation behavior is a design decision for the implementation phase: the PRD does not prescribe whether
  `run()` still rethrows after capturing, or returns a failed result without rethrowing. Both are acceptable as long as
  the contract is explicit and documented
- When the service succeeds, the exception field is `null`

### UC-4: Concern pipeline remains unchanged

When a developer implements a `ServiceConcern`, the concern contract and pipeline behavior are unaffected by the
introduction of `ServiceResult`.

**Acceptance criteria:**

- `ServiceConcern::execute()` continues to return `bool` -- the concern pipeline is not modified
- `handle()` continues to return `bool` -- subclass implementations are not modified
- `ServiceResult` is constructed at the `run()` level, after the concern pipeline completes
- Existing `ServiceConcern` implementations require no changes
- The concern pipeline's ability to short-circuit (return `bool` directly without calling `$next()`) is preserved

---

## Out of Scope

- **Execution metadata:** Duration, record counts, and diagnostic messages (P3) are deferred to a follow-up release
  after the core `ServiceResult` pattern has proven itself. The result object's design should not preclude adding
  metadata later, but metadata fields are not included in this PRD.
- **Generic type parameter for data:** Whether `ServiceResult` carries a `@template T` for the data field (enabling
  `ServiceResult<User>`) vs `mixed` is an implementation decision. This PRD requires that data is accessible; it does
  not prescribe the type system approach.
- **Result monad API:** Methods like `map()`, `flatMap()`, or `match()` that enable monadic composition are not
  included. `ServiceResult` is a value object, not a monad. Services execute once and return; there is no chaining.
- **Concern pipeline modification:** The `ServiceConcern` interface is explicitly out of scope. If future requirements
  need concerns to inspect or modify the result object, that is a separate PRD.
- **Migration tooling:** Automated migration of consuming applications from `bool` to `ServiceResult` is not provided.
  A migration guide in the changelog is sufficient.

---

## Success Criteria

| Metric | Baseline | Target | How Measured |
|--------|----------|--------|--------------|
| Workaround patterns for result access | Public properties, payload mutation, external catch (per consuming code) | Zero -- callers access data via `ServiceResult` | Code inspection: no public result properties on concrete services |
| Status representation ambiguity | `?bool` with undocumented tri-state | Enum with self-documenting cases | Code inspection: `getStatus()` removed, `ServiceStatus` enum in use |
| Exception context availability | Lost after `failed()` hook | Captured in `ServiceResult` | Test: failed result carries the original `Throwable` |
| Concern pipeline changes | N/A | Zero changes to `ServiceConcern` interface | Code inspection: `ServiceConcern::execute()` signature unchanged |
| PHPStan exhaustive matching | Not possible with `?bool` | Enum enables exhaustive `match` verification | PHPStan level 8 reports non-exhaustive match on `ServiceStatus` |

---

## Assumptions

- The core service lifecycle (`prepare` → `handle` → `success`/`failed`) is stable and does not need structural changes
  -- only the return type from `run()` changes.
- A value object is the right level of abstraction -- not a full Result monad or Either type.
- The concern pipeline can remain `bool`-based because `ServiceResult` is constructed after the pipeline completes, not
  within it.
- Consuming applications can migrate incrementally by updating their `run()` call sites to use `ServiceResult` rather
  than `bool`.

---

## Risks

| Risk | Impact | Likelihood | Mitigation |
|------|--------|------------|------------|
| Adoption friction from novel pattern | Developers unfamiliar with result objects may find the wrapper unnecessary compared to bare `bool` | Medium | Provide clear documentation, migration guide, and code examples in the changelog. Keep the API minimal (no monad methods). |
| Exception propagation contract confusion | Callers may be unsure whether to check `ServiceResult` for failure or catch exceptions | Medium | The implementation must choose one contract (rethrow or return-only) and document it explicitly. Both are valid; ambiguity is not. |
| Future metadata requirements force redesign | Deferring metadata (P3) may require breaking the result object's API later | Low | Design the value object to be extensible (e.g., constructor can accept additional fields in future) without breaking existing callers. |
| Double interface break if concerns need results later | If a future PRD changes `ServiceConcern` to work with `ServiceResult`, all concern implementations break simultaneously with service implementations | Low | UC-4 explicitly preserves the concern pipeline. A future concern-result PRD would be a separate breaking change with its own migration path. |

---

## Release Criteria

- All P0 user capabilities (UC-1 through UC-4) pass their acceptance criteria
- PHPStan level 8 passes with zero errors
- 100% test coverage on `ServiceResult`, `ServiceStatus`, and modified `Service` class
- `composer check` and `composer test` pass
- Migration guide included in changelog documenting the `bool` → `ServiceResult` transition
- Existing `ServiceConcern` implementations compile and pass tests without modification

---

## Traceability

| Artifact | Path |
|----------|------|
| Intake Brief | .sinemacula/blueprint/workflows/service-result-value-object/intake-brief.md |
| Spike: Result Object Patterns | .sinemacula/blueprint/workflows/service-result-value-object/spikes/spike-result-object-patterns.md |
| Problem Map | .sinemacula/blueprint/workflows/service-result-value-object/problem-map.md |
| Prioritization | .sinemacula/blueprint/workflows/service-result-value-object/prioritization.md |
| Source Issue | ISSUES.md (ISSUE-18) |

---

## References

- Prioritization: .sinemacula/blueprint/workflows/service-result-value-object/prioritization.md
- Problem Map: .sinemacula/blueprint/workflows/service-result-value-object/problem-map.md
- Spike: .sinemacula/blueprint/workflows/service-result-value-object/spikes/spike-result-object-patterns.md
- Intake Brief: .sinemacula/blueprint/workflows/service-result-value-object/intake-brief.md
- Source: ISSUES.md (ISSUE-18)
