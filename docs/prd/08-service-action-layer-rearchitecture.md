# PRD: Service (Action) Layer Re-architecture

Re-architect the service layer (`SineMacula\ApiToolkit\Services\Service`) into a sophisticated, strongly
type-hinted action skeleton: one service per API action (create user, update user, delete user), with a
single self-validating typed input, an explicit queue-safe actor, correct all-or-nothing failure
semantics, a clean concern pipeline, transport-neutral locking, a total result object, and observability
by default. Derived from and traces to ADR 0005.

---

## Governance

| Field      | Value                                                                                                                                                                                                                                                                                                       |
|------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Created    | 2026-06-27                                                                                                                                                                                                                                                                                                  |
| Status     | approved                                                                                                                                                                                                                                                                                                    |
| Complexity | full                                                                                                                                                                                                                                                                                                        |
| Owned by   | Ben Carey                                                                                                                                                                                                                                                                                                   |
| Traces to  | [ADR 0005](../adr/0005-re-architect-the-service-action-layer.md)                                                                                                                                                                                                                                            |
| Problems   | P1 (false/throw atomicity footgun), P2 (lock coupled to HTTP 429 and swallowed), P3 (leaky success/failed lifecycle), P4 (concerns coupled to the concrete base; load-bearing order; dead lock guard), P5 (silent global lock), P6 (no queue-safe actor; ambient `app('auth')`), P7 (loose untyped payload) |

---

## Background

The `Service` base is the skeleton every API action extends (in the v2 `api` consumer: per-entity
`Create`/`Update`/`Destroy` services over a per-entity base, using a `prepare()`/`handle()` lifecycle,
repositories, and permission bindings). Two independent architecture reviews (an internal
architecture-reviewer pass and a codex pass) confirmed the skeleton is coherent but its failure,
transaction, locking, and input semantics are not sound enough for a base class, and it lacks a
queue-safe actor concept. ADR 0005 records the accepted design that this PRD implements.

Confirmed defects in the current layer:

- `handle()` returning `false` commits the database transaction (`DB::transaction` commits on any
  non-throwing return) while throwing rolls it back, yet both surface as `ServiceResult::FAILED` -
  "failed" can mean persisted or rolled back, indistinguishably.
- `Lockable` throws `TooManyRequestsException` (an HTTP-429 exception with `X-RateLimit-*` meta) from a
  generic concurrency primitive, and `run()` catches it into a failure result so the 429 never reaches
  the API handler.
- `success()` runs outside the `try/catch`, so its exceptions escape `run()` and bypass `failed()`.
- `ServiceConcern::execute(Service, Closure)` couples concerns to the concrete base; concern order is
  load-bearing but unenforced; `LockConcern`'s trait guard is dead code.
- The default `getLockId()` returns `''`, so a lock-enabled service silently shares one class-wide lock.
- The input is an untyped `array|Collection|\stdClass $payload` with no type safety.
- Attribution requires the actor, but `AuthenticatedService` resolves `app('auth')` ambiently, which is
  unsafe on a queue.

Breaking changes to the service layer are acceptable in v2.

---

## Target Users

| Persona                  | Key Need                                                                                                                                     |
|--------------------------|----------------------------------------------------------------------------------------------------------------------------------------------|
| API developer (consumer) | Define one action per use-case with strongly-typed input, no FormRequest/DTO duplication, and identical behaviour inline and on a queue.     |
| Toolkit maintainer       | A sound, atomic, observable service base that is safe to build many actions on and straightforward to test.                                  |
| Operator / on-call       | Visibility into who performed each action and its outcome (audit + observability), and correct HTTP semantics (e.g. 429 on lock contention). |

---

## Architecture Boundaries

Hexagonal boundaries this work establishes or preserves:

| Boundary                       | Decision                                                                                                                                                           |
|--------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Driving input port             | Action input enters as a validated `ServiceInput` value object; HTTP, queue, and console are interchangeable adapters that each produce it.                        |
| Actor port                     | The causer enters as an explicit `Actor`; no ambient `Auth`. HTTP supplies `EloquentActor::of(Auth::caller())`; queue/console supply a reference or `SystemActor`. |
| Persistence                    | Reached through repositories; the transaction boundary is owned by the runner (commit on clean return, rollback on throw).                                         |
| Locking (driven adapter)       | `Lockable` over the cache throws a transport-neutral `LockUnavailableException`; the HTTP adapter (`ApiExceptionHandler`) maps it to 429.                          |
| Observability (driven adapter) | Lifecycle events (`ServiceCompleted`/`ServiceFailed`) are the outbound port for audit, metrics, and logging subscribers.                                           |

---

## User Capabilities

### UC-1: One-action service skeleton with a clear lifecycle

A developer defines one service per API action by extending the base and implementing `handle()`, with
optional lifecycle hooks whose transactional context is legible by name.

**Acceptance criteria:**

- `abstract class Service` exposes overridable hooks `authorize()`, `validate()`, `prepare()`,
  `handle()`, `afterCommit()`, and `onFailure()`; all default to no-ops except `handle()`, which is
  abstract.
- The runner executes them in this order: `authorize` and `validate` pre-flight (no lock/transaction);
  then (if enabled) acquire lock, begin transaction, `prepare()`, `handle()`; commit; release lock;
  `afterCommit()`; on failure `onFailure()` runs after rollback and lock release.
- `handle()` returns the action's output (`TOutput`) and signals failure only by throwing; it has no
  boolean return.
- The base carries no ambient state and reads no `Auth`, `Request`, or session.

### UC-2: Single self-validating typed input (no FormRequest+DTO duplication)

A developer declares an action's input once, as a typed object that is both the shape and its validation,
usable from any entry point.

**Acceptance criteria:**

- A `ServiceInput` contract exists; `InputData` is an abstract base implementing it that concrete inputs
  extend with typed (promoted) properties.
- Validation is declared with toolkit-owned PHP attributes on the properties; a hand-rolled
  attribute-to-rules compiler converts them to Laravel validation rules. No dependency on
  `spatie/laravel-data` is introduced.
- A `rules(): array` override is available for cross-field or complex rules the attributes cannot
  express.
- `InputData::from(Request|array $source): static` validates `$source` (throwing Laravel's
  `ValidationException` on failure) then hydrates the typed instance; `new` with named arguments also
  works.
- The input is immutable and serialisable with no request/container/ambient dependency, so the same
  instance is valid inline, on a queue, in a console command, and in tests.
- `ArrayInput implements ServiceInput` provides typed accessors over a validated array as a no-class
  escape hatch; a `spatie/laravel-data` `Data` object also satisfies the `ServiceInput` contract.
- `handle()` reads typed properties (e.g. `$this->input->city`), never an untyped `get('city')`.

### UC-3: Explicit, queue-safe actor (causer)

A developer passes who initiated an action explicitly, and that identity survives serialisation onto a
queue; the service never reads ambient auth.

**Acceptance criteria:**

- An `Actor` contract exposes `actorIdentifier()`, `actorType()`, `actorLabel()`, and
  `toAuthenticatable()`.
- `EloquentActor` adapts an `Authenticatable`/model; `SystemActor` is a null-object for system/no-user
  contexts (`toAuthenticatable()` returns null); `AnonymousActor` represents unauthenticated callers.
- The actor is supplied at the call site (`->by($actor)` or constructor); the service exposes
  `$this->actor()` and never calls `Auth`.
- `EloquentActor` serialises as a re-resolvable reference (morph type + id) plus a snapshot of
  `actorLabel()`, so it re-resolves on a worker and retains attribution even if the underlying record
  changes.
- Authorisation uses the actor (`Gate::forUser($this->actor()->toAuthenticatable())`); `SystemActor`
  short-circuits.

### UC-4: Ambient-free execution context

Cross-cutting code receives an explicit, immutable context rather than the concrete service or ambient
state.

**Acceptance criteria:**

- A `ServiceContext` value object carries the actor, a correlation id (generated if absent), a source
  (`Http`/`Queue`/`Console`/`Internal`), and captured metadata.
- `ServiceContext` is immutable and queue-serialisable.
- Concerns receive the `ServiceContext`, not the concrete `Service`.

### UC-5: Correct, single-channel failure and atomicity

A failed action never leaves partial writes committed.

**Acceptance criteria:**

- A thrown exception anywhere in `prepare()`/`handle()` rolls back the transaction; a clean return
  commits.
- There is no code path by which a "failed" outcome leaves the transaction committed.
- `afterCommit()` runs only on success, outside the transaction and lock; an exception it throws is
  caught, recorded on the result as a side-effect error, and logged, leaving the persisted result
  intact.
- `onFailure()` runs after rollback and lock release; an exception it throws is caught and logged.
- Transaction wrapping is configurable (on by default) with a configurable retry count; lock is acquired
  outside the transaction.

### UC-6: Total, structured result

A caller receives one self-describing result and chooses whether to propagate failures.

**Acceptance criteria:**

- `run()` returns a `ServiceResult` carrying status (`Succeeded`/`Failed`), the typed output, the failure
  exception (if any), and any side-effect errors.
- `run()` does not throw for business failures (authorisation, validation, domain errors, lock
  contention); all are captured in the result.
- `ServiceResult` exposes `succeeded()`, `failed()`, `output()`, `outputOr($default)`, and `throw()`
  (which rethrows the captured exception when failed, else returns the result).
- `ServiceResult` is immutable.

### UC-7: Pluggable concerns with framework-owned ordering

A developer adds cross-cutting behaviour without coupling to the concrete base or managing ordering.

**Acceptance criteria:**

- `ServiceConcern` defines `handle(ServiceContext $context, Closure $next): mixed`; concerns do not
  type-hint the concrete `Service`.
- The runner composes lock and transaction in a fixed correct order (lock outermost, transaction inside,
  custom concerns within the transaction, core last); ordering is not delegated to a user-declared array.
- Custom concerns are declared via `concerns(): array` and resolved from the container.

### UC-8: Transport-neutral locking

Locking is a reusable primitive that does not depend on HTTP semantics.

**Acceptance criteria:**

- `Lockable` throws a transport-neutral `LockUnavailableException` on contention, not
  `TooManyRequestsException`.
- `ApiExceptionHandler` maps `LockUnavailableException` to an HTTP 429 response, preserving existing
  client behaviour.
- A lock-enabled service must provide a non-empty lock identity; an empty lock identity raises a clear
  configuration exception rather than sharing a class-wide lock.

### UC-9: Run inline or on a queue, identically

A developer runs an action synchronously or dispatches it to a queue with the same call shape.

**Acceptance criteria:**

- A service can be executed synchronously (`->run()`), returning a `ServiceResult`.
- A service can be dispatched to a queue (`->dispatch()`), serialising the input, the actor reference,
  and the context; on the worker it re-hydrates and runs identically with no `Auth`/`Request`
  dependency.

### UC-10: Observability and audit by default

Every action emits who-did-what-and-what-happened for audit and observability.

**Acceptance criteria:**

- The runner dispatches a `ServiceCompleted` event on success and a `ServiceFailed` event on failure,
  each carrying the actor, the service class, the outcome, and the duration.
- No failure is silently swallowed without a corresponding event.

---

## Constraints

- PHP `^8.3`, Laravel 12; namespace `SineMacula\ApiToolkit`; source in `src/`.
- Must pass `composer check` (qlty: PHPStan level 8, php-cs-fixer, CodeSniffer) and `composer test`.
- Mutation testing (`composer test:mutation`) must meet the project gate (covered MSI >= 90).
- `min_coverage = 100` per project config.
- No em-dashes or en-dashes in any output (code, comments, docs); hyphens only.
- Conventional Commits; no AI-tool mentions in commits or comments; `@author Ben Carey` /
  `@copyright 2026 Sine Macula Limited.` headers on new classes.
- No new runtime dependency (the attribute-to-rules compiler is hand-rolled); `spatie/laravel-data`
  remains optional/interoperable, never required.
- Traits use `#[CoversTrait]`, classes `#[CoversClass]` in tests.
- Do not change static-analysis or formatting configuration.

---

## Success Criteria

- The service layer matches the ADR 0005 design: action skeleton, typed self-validating input, explicit
  queue-safe actor, ambient-free context, single-channel failure with correct atomicity, total result,
  pluggable concerns with framework-owned ordering, transport-neutral locking, queueable execution, and
  observability events.
- All ten user capabilities pass their acceptance criteria with tests (unit and integration), including
  a real-path test proving a failed `handle()` leaves no committed writes and a test proving a queued run
  carries the actor without `Auth`.
- `composer check`, `composer test`, and the mutation gate all pass.

---

## Release Criteria

### Rollout Plan

- **Rollout shape:** ships within the v2 (`2.x`) major as two sequenced changes - first the foundational
  `Lockable` -> `LockUnavailableException` decouple, then the service rearchitecture. It is a base-class
  change, so there is no feature flag; UPGRADE.md documents the breaking migration for consumers.
- **Gating metrics:** `composer check` clean, `composer test` green, mutation covered-MSI >= 90, and the
  `min_coverage = 100` target met on new code, before each change merges.
- **Rollback criteria:** pre-tag, revert the offending PR(s); because the work is unreleased on `2.x`,
  rollback is a branch revert with no consumer impact. A regression surfacing post-tag would be addressed
  by a follow-up release rather than an in-place hotfix.

---

## File Manifest (indicative)

New under `src/Services/`: `ServiceRunner.php`, `ServiceContext.php`, `Enums/ServiceSource.php`,
`Contracts/{Actor,ServiceInput}.php`, `Input/{InputData,ArrayInput}.php` plus validation attribute
classes under `Input/Attributes/`, `Actors/{EloquentActor,SystemActor,AnonymousActor}.php`,
`Events/{ServiceCompleted,ServiceFailed}.php`, `Jobs/ServiceJob.php` (or a `Queueable` concern).

Modified: `src/Services/Service.php`, `src/Services/ServiceResult.php`,
`src/Services/Enums/ServiceStatus.php`, `src/Services/Contracts/ServiceConcern.php`,
`src/Services/Concerns/{LockConcern,TransactionConcern}.php`, `src/Concerns/Lockable.php`,
`src/Exceptions/ApiExceptionHandler.php`.

New exception: `src/Exceptions/LockUnavailableException.php` (transport-neutral).

Removed: the ambient `app('auth')` input path (the explicit actor replaces it).

---

## Out of Scope

- Extracting the service layer into a separate package (explicitly deferred in ADR 0005).
- Migrating the v2 `api` consumer's existing services (a downstream effort once this lands).
- Adopting `spatie/laravel-data` as a dependency.
- Nested-relation traversal allowlist (tracked separately as BL-37).
