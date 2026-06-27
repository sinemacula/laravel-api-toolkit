# 0005 - Re-architect the service (action) layer

- Status: Accepted
- Date: 2026-06-27

## Context

The service layer (`SineMacula\ApiToolkit\Services\Service`) is the skeleton every API action extends:
one concrete service per use-case (`CreateUser`, `UpdateUser`, `DeleteUser`). It exists to give each
action a consistent shape - input handling, a lifecycle, cross-cutting concerns (transaction, lock),
and a structured result - so controllers stay thin and business actions are uniform, testable, and
reusable from anywhere (HTTP, queue, console, another service).

The current shape: a `Service` base takes a loose `array|Collection|\stdClass $payload`, runs a
`prepare → handle → success/failed` lifecycle wrapped by a hand-rolled concern pipeline
(`ServiceConcern[]` folded with `array_reduce`), where `handle()` returns `bool`, and returns a
`ServiceResult` value object (introduced by PRD 07 to replace the old `?bool`). Cache locking comes
from the shared `Lockable` trait via `LockConcern`; database atomicity from `TransactionConcern`.

Two independent architecture reviews (an internal architecture-reviewer pass and a codex pass) agreed
the skeleton is coherent but the failure, transaction, and locking semantics are not yet sound enough
for a base class that other actions build on. The confirmed problems:

1. **Atomicity footgun.** A `handle()` that performs writes and returns `false` *commits* the
   transaction (because `DB::transaction(fn () => $next())` commits on any non-throwing return), while
   a `handle()` that throws rolls back - yet both surface as `ServiceResult::FAILED`. "Failed" can
   therefore mean "failed and persisted" or "failed and rolled back", indistinguishably.
2. **Lock/transport coupling.** `Lockable` throws `TooManyRequestsException` - an HTTP-429 exception
   carrying `X-RateLimit-*` meta - from a generic concurrency primitive. Worse, `run()` catches every
   `Throwable` into `ServiceResult::failure()`, so a lock conflict is *swallowed* and never reaches the
   API handler as a real 429 unless the caller manually re-inspects and rethrows.
3. **Leaky lifecycle.** `success()` runs outside the `try/catch`, so a `success()` exception escapes
   `run()` and bypasses `failed()` - `run(): ServiceResult` is not a total result-returning API.
4. **Over-concrete extension seam.** `ServiceConcern::execute(Service $service, …)` type-hints the
   concrete base and hands every concern the whole object; concern ordering is load-bearing but
   unenforced; `LockConcern`'s `class_uses_recursive` guard is dead code because `Service`
   unconditionally `use`s `Lockable`.
5. **Silent global lock.** The default `getLockId()` returns `''`, so a lock-enabled service that does
   not override it hashes only the class name - every invocation of that class shares one lock and
   accidentally serialises across unrelated payloads.

Beyond the bugs, the layer is missing two capabilities this re-architecture exists to add:

- **Ambient-context independence.** Actions must run identically inline (HTTP) and on a queue. A
  queued job has no `Auth`, `Request`, or session. The layer must therefore never read ambient state.
- **Explicit actor (causer).** We routinely need to know *who* requested an action - for
  authorization, for attribution, and for audit - and that identity must survive serialisation onto a
  queue. Today there is no actor concept; actions that need the current user reach for `Auth::user()`,
  which is exactly what breaks on a queue.

**Current practice (the v2 `api` consumer).** The reference v2 application already builds on this layer
(on toolkit v1.16.4) and sets the lifecycle expectations this ADR must honour: actions are grouped per
entity as `Create`/`Update`/`Destroy` services over a per-entity base (e.g. `Addresses\CreateService ->
AddressService -> Foundation\AuthenticatedService -> Service`); each action uses `prepare()` for business
checks (throwing domain exceptions such as `AddressExistsException`) and `handle()` for the write; data
flows in as `new CreateService(payload: $request->validated())` straight from a toolkit `FormRequest`
(no DTO layer); repositories are reached via `HasRepositories` (`$this->addresses()`); and permission
binding is layered through traits. Crucially, attribution is already wanted - `AuthenticatedService`
exposes the caller via `user()`/`authenticatable()` - but it does so by resolving `app('auth')`
ambiently, the very queue-unsafe coupling this ADR replaces. The re-architecture honours the lifecycle
expectations worth keeping (per-entity bases, `prepare`/`handle`, repositories) but deliberately improves
the parts that aren't ideal: the input (a single self-validating typed object in place of the
FormRequest-plus-array flow) and the auth (an explicit actor in place of ambient `app('auth')`), while
fixing the failure and locking flaws. This context shows what we need; it does not constrain how we
build it.

This is a v2 change; breaking changes to the service layer are acceptable. We are not deciding whether
to extract the layer into its own package (see ADR backlog / review notes) - only how to make it
sophisticated, well-structured, and best-practice in place.

## Decision drivers

- One service = one API action/use-case; the base is a skeleton, not a framework.
- **No ambient context** - never read `Auth`, `Request`, `session`, or globals; identical behaviour
  inline and on a queue.
- **Explicit, queue-serialisable actor** for authorization, attribution, and audit, including a
  first-class non-user (system) actor.
- Correct, single-channel failure semantics with all-or-nothing persistence.
- Infrastructure (locking) decoupled from HTTP/API exception semantics.
- A clean extension seam that does not couple concerns to the concrete base or to declaration order.
- Observability/audit by default - every action emits who-did-what-and-what-happened.
- Sophisticated but ergonomic; the happy path for a simple action stays short.

## Decision

Re-architect the layer around five collaborators - **Service** (the action skeleton), **ServiceInput**
(typed input), **Actor** (the causer), **ServiceContext** (the ambient-free execution envelope), and
**ServiceResult** (the total outcome) - driven by a **ServiceRunner** that owns a fixed, correct
lifecycle. Concerns become genuinely pluggable cross-cutting middleware; transaction and locking
become first-class runner stages rather than user-ordered concerns.

### 1. The action skeleton

`abstract class Service` remains the name (namespace continuity), documented as one-action-per-class
and generic over its input and output via docblock templates (PHP has no runtime generics):

```php
/**
 * @template TInput of ServiceInput
 * @template TOutput
 */
abstract class Service
{
    /** @return TOutput  Failure is signalled by throwing, never by a return value. */
    abstract protected function handle(): mixed;
}
```

A service is constructed with its input and an actor and produces a typed output. It declares its
behaviour through small, intention-revealing hooks and properties rather than by assembling
machinery.

### 2. Input - one self-validating typed object, not a FormRequest plus a DTO

The aim is strong typing with no duplicated effort. The real duplication is not "FormRequest vs DTO" to
be shuffled around - it is declaring the input's *shape* and its *validation* in two places. So we declare
them once, in a single self-validating typed input that *replaces* the FormRequest for actions:

```php
// One class per action: typed properties are the shape, attributes are the
// validation, and both live here and nowhere else.
final class CreateAddressInput extends InputData
{
    public function __construct(
        #[Required, Max(128)] public string $addressLine1,
        #[Nullable, Max(128)] public ?string $addressLine2,
        #[Required, Max(128)] public string $city,
        #[Required] public AddressClassification $classification,
        public bool $verifyAddress = false,
    ) {}
}
```

`ServiceInput` is the contract; `InputData` is the toolkit-owned abstract base concrete inputs extend. It
provides:

- `from(Request|array $source): static` - validates `$source` against the rules compiled from the
  property attributes (with a `rules(): array` override for cross-field or complex rules), then hydrates
  the typed instance. A validation failure throws Laravel's `ValidationException`, so the API handler
  still renders 422.
- a plain, immutable, queue-serialisable object with no request, container, or ambient state, so the
  same input crosses to a queue, a console command, or another service unchanged.

This collapses FormRequest and DTO into one class with a single source of truth: the typed properties
give strong typing and full IDE/PHPStan support (`$this->input->city` is a `string`, `->classification`
an enum), the attributes give validation, and `handle()` reads typed properties, never `get('city')`.

**One contract, every entry point:**

- HTTP: `CreateAddressInput::from($request)` replaces the FormRequest. Optionally the toolkit registers a
  container resolver so type-hinting `CreateAddressInput` in a controller validates-and-injects it,
  matching FormRequest ergonomics.
- Queue / console / internal: `CreateAddressInput::from($array)` or `new CreateAddressInput(...)` with
  named arguments - fully typed and validated, no HTTP required.

**Escape hatch and interop.** A trivial or genuinely dynamic action can use `ArrayInput implements
ServiceInput` (typed accessors over a validated array) and declare no class at all. Because `ServiceInput`
is just a contract, a `spatie/laravel-data` `Data` object also satisfies it directly - the toolkit does
not depend on laravel-data, but does not preclude it.

**Build note.** The only new machinery is the attribute-to-rules compiler. Decision: hand-roll a focused
reflection pass mapping a small set of toolkit-owned validation attributes to Laravel rule strings -
consistent with the toolkit's owned-core philosophy (ADR 0003/0004) - with `rules()` always available for
anything attributes cannot express. No `spatie/laravel-data` dependency is taken (though a `Data` object
still satisfies the `ServiceInput` contract for teams that already use it).

For update/destroy actions the service still takes its subject model as a distinct constructor argument
(`new UpdateAddress($address, $input)`); the subject entity is not part of the input payload, and the
toolkit `FormRequest` remains available for non-action (e.g. query-only) endpoints.

### 3. Actor - the explicit, queue-safe causer

This formalises and fixes what v2's `Foundation\Services\AuthenticatedService` does today. That base
resolves `$this->auth = app('auth')` ambiently in `initialize()` (with a `setAuth()` escape hatch) and
exposes `user()`/`authenticatable()` - which is precisely the queue-unsafe coupling to ambient auth this
re-architecture removes. The Foundation layer already has the right concept (a `Caller`); we make it the
explicit, always-passed, serialisable input to every action and delete the ambient `app('auth')` path.

```php
interface Actor
{
    public function actorIdentifier(): string|int|null; // null for system
    public function actorType(): string;                // morph alias, or 'system'
    public function actorLabel(): string;               // human label, snapshot at capture
    public function toAuthenticatable(): ?Authenticatable; // for Gate::forUser(), null for system
}
```

Three shipped implementations:

- `EloquentActor` - adapts an `Authenticatable`/`Model` (in v2, the Foundation `Caller`). The current
  user becomes an actor *at the call site* (`EloquentActor::of(Auth::caller())`), never inside the
  service - the controller passes it in: `new CreateService($input)->by(Auth::caller())`.
- `SystemActor` - a null-object for system/scheduled/no-user contexts
  (`SystemActor::named('scheduler')`); trusted by default, `toAuthenticatable()` returns null.
- `AnonymousActor` - unauthenticated public requests, distinct from system.

**Queue safety.** An actor is serialisable. `EloquentActor` serialises the morph type + id (re-resolved
on the worker, like `SerializesModels`) *and* snapshots `actorLabel()` at capture time, so attribution
and audit survive even if the underlying user is later renamed or deleted. The service reads
`$this->actor()`; it never touches `Auth`. This is the linchpin that makes inline and queued execution
identical.

**Authorization** uses the actor, not the facade: `Gate::forUser($this->actor()->toAuthenticatable())`.
`SystemActor` short-circuits to allowed (or an explicit system policy). This keeps authorization
queue-safe.

### 4. ServiceContext - the ambient-free envelope

```php
final readonly class ServiceContext
{
    public function __construct(
        public Actor $actor,
        public string $correlationId,        // generated if absent; threads through logs/events
        public ServiceSource $source,        // Http | Queue | Console | Internal
        public array $metadata = [],         // ip, user-agent, request id - captured, never read ambiently
    ) {}
}
```

The context is the explicit replacement for ambient state: whatever an action would have sniffed from
the request is captured at the boundary and passed in. It is immutable, queue-serialisable, and is what
concerns receive (see §7) - not the concrete `Service`.

### 5. Lifecycle - one legible, transaction-aware sequence

The `ServiceRunner` executes a fixed sequence whose transactional context is legible from the hook
name and documented on each. Hooks are overridable; all default to no-ops except `handle()`.

```text
authorize()        pre-flight · no lock/tx · throws AuthorizationException · uses $this->actor()
validate()         pre-flight · no lock/tx · throws ValidationException (or input self-validates)
─ acquire lock ─   if lockId() is set · transport-neutral LockUnavailableException on contention
─ begin tx ─       if $transactional (default true) · configurable retries
  prepare()          in tx · load/lock rows, pre-compute
  handle(): TOutput  in tx · the unit of work · FAILURE = throw · returns the output
─ commit tx ─
─ release lock ─
afterCommit(out)   post-commit · no lock/tx · side effects only (events, mail, notifications)
onFailure(e)       after rollback + lock release · durable failure recording/compensation · must not throw
finally            emit ServiceCompleted | ServiceFailed (actor, action, outcome, duration)
```

This resolves the review findings structurally:

- **Single failure channel (fixes atomicity).** `handle()` returns the output and signals failure only
  by throwing. The `bool` return is gone, so the `return false`-commits ambiguity cannot exist:
  `DB::transaction` commits on clean return and rolls back on throw, by construction.
- **`success()` → `afterCommit()`** runs after commit, outside the transaction and lock; the name
  states its context. Exceptions thrown in `afterCommit()` are caught, recorded on the result as
  `sideEffectErrors`, and logged - the persisted work stands and `run()` stays total.
- **`failed()` → `onFailure()`** runs *after* rollback (so it can durably record the failure for
  audit), outside the transaction; if it throws, the throw is caught and logged.
- **Pre-flight hooks** (`authorize`/`validate`) run before any lock or transaction is opened, so a
  rejection costs nothing and opens no resources.

### 6. ServiceResult - the total outcome

```php
/** @template TOutput */
final readonly class ServiceResult
{
    public ServiceStatus $status;        // Succeeded | Failed
    public mixed $output;                // TOutput|null
    public ?\Throwable $exception;       // failure cause
    public array $sideEffectErrors;      // afterCommit() throwables, if any

    public function succeeded(): bool;
    public function failed(): bool;
    public function output(): mixed;     // TOutput
    public function throw(): static;     // rethrow $exception if failed, else return $this
    public function outputOr(mixed $default): mixed;
}
```

`run()` is **total**: it never throws for business failures (authorization, validation, domain errors,
lock contention) - all are captured. Callers opt into propagation with `->throw()`, which mirrors
Laravel's `Http` response and lets the HTTP path surface the right status cleanly:

```php
$user = CreateUser::make($input)->by($actor)->run()->throw()->output();
```

Because authorization and validation failures are captured then rethrown on `->throw()`, the API
exception handler still renders 403/422 - without the service ever knowing about HTTP.

### 7. Concerns - pluggable middleware, framework-owned ordering

Transaction and locking stop being user-declared concerns and become first-class runner stages with a
fixed, correct order (lock outermost so it spans the commit; transaction inside; custom concerns
inside the transaction, around the core). This removes the load-bearing-order smell and the dead
`LockConcern` guard entirely - the runner decides from `lockId()` and `$transactional`.

`ServiceConcern` is retained for genuine custom cross-cutting (idempotency, metrics, structured
logging) and is narrowed so concerns no longer couple to the concrete base:

```php
interface ServiceConcern
{
    /** @param Closure(): mixed $next  @return mixed the (possibly wrapped) output */
    public function handle(ServiceContext $context, \Closure $next): mixed;
}
```

A concern receives the immutable context and the continuation - interface-segregated, base-class-free,
and queue-safe. Services list custom concerns via `concerns(): array`; the runner slots them inside the
transaction.

### 8. Locking - transport-neutral

`Lockable` throws a new transport-neutral `LockUnavailableException` (in the toolkit's lock namespace),
not `TooManyRequestsException`. The API layer maps it: one row in `ApiExceptionHandler`
(`LockUnavailableException → 429`), where the existing exception-mapping seam already lives. The
service layer no longer knows about HTTP.

Lock identity is mandatory when locking is enabled: a service that sets locking must return a non-empty
`lockId()` (typically derived from the input's identity, e.g. the target user id). An empty key throws
`LockConfigurationException` at run time rather than silently sharing one class-wide lock.

### 9. Invocation and queueing

A fluent, container-resolved entry point that runs sync or dispatches to a queue with the *same* call
shape - the actor and input ride along, so the queued run is byte-for-byte the inline run:

```php
// synchronous
$result = UpdateUser::make($input)->by($actor)->run();          // ServiceResult<User>

// queued - serialises input + actor reference + context; re-hydrates and runs on the worker
UpdateUser::make($input)->by($actor)->dispatch();

// system-initiated (no user; safe on the scheduler/queue)
PurgeExpiredTokens::make($input)->by(SystemActor::named('scheduler'))->run();
```

Queueing is a thin `ServiceJob` bridge (or a `Queueable` trait on the service) that serialises the
input DTO, the actor reference (+ label snapshot), and the context metadata. No `Auth`, `Request`, or
container-bound singletons are captured.

### 10. Observability and audit by default

The runner dispatches `ServiceCompleted` and `ServiceFailed` events carrying the actor, the service
class, an input summary, the outcome, and the duration. This closes the observability gap the reviews
flagged (failures were swallowed into values with no breadcrumb) and makes audit logging
("who did what, when, with what result") a subscriber rather than per-action boilerplate - which is the
whole point of carrying an explicit actor.

## Alternatives considered

- **Patch the current design only** (fix the five bugs, keep the shape). Rejected: it leaves the loose
  payload, the ambient-context risk, and the missing actor - the capabilities this work exists to add.
- **Adopt `lorisleiva/laravel-actions`.** Powerful and well-shaped, and it informed this design
  (as-controller/as-job/as-listener), but it is a heavy external dependency with its own opinions and
  no native fit with the toolkit's `ServiceResult`, exception handler, and lock primitives. Rejected as
  a dependency; borrowed as inspiration.
- **Keep ambient `Auth` with a queue shim** (capture and re-bind the user around a job). Rejected:
  fragile, still couples actions to global state, and offers no system/anonymous actor. Explicit actor
  passing is simpler and strictly more capable.
- **Keep `handle(): bool`.** Rejected: it is the root of the atomicity footgun.
- **Keep the FormRequest and add a separate DTO** (the `FormRequest -> DTO -> service` flow). Rejected:
  it declares the input's shape and validation twice. The chosen design (§2) collapses both into one
  self-validating typed input, so there is a single source of truth and no duplicated effort.
- **Keep a flexible `array`/`Collection` payload.** Rejected: it forfeits the strong typing and
  single-source validation that are the whole point of this change; `handle()` would still read
  `get('city')` with no type or IDE support.
- **Mandate `spatie/laravel-data` as the input engine.** Considered as the off-the-shelf source of
  attribute/type-driven rules and request hydration. Decided against: the toolkit hand-rolls a focused
  attribute-to-rules compiler instead (owned core, no dependency, consistent with ADR 0003/0004). A
  `Data` object still satisfies the `ServiceInput` contract, so consumers already using laravel-data are
  not shut out.
- **`run()` throws on failure** (no total result). Rejected: a total result with opt-in `->throw()`
  serves both the HTTP path (which wants exceptions) and programmatic callers (which want values)
  without a dual model.

## Consequences

- **Breaking (intended, v2).** `handle()` changes from `bool` to returning the output and throwing on
  failure; `$payload` becomes a typed `ServiceInput`; construction requires an actor; `run()` returns
  `ServiceResult<TOutput>` and is total; `success()/failed()` become `afterCommit()/onFailure()`;
  `ServiceConcern::execute(Service, Closure)` becomes `handle(ServiceContext, Closure)`. Consumers'
  existing services must migrate. An UPGRADE.md section will document the mechanical changes.
- **New concepts.** `Actor`/`ServiceContext`/`ServiceInput` add surface area and a learning curve. This
  is the deliberate cost of queue-safety and auditability; the fluent entry point and `ArrayInput`
  keep simple actions short.
- **Atomicity is correct by construction**, locking is HTTP-agnostic and reusable off the request path,
  and the lifecycle's transactional context is self-documenting.
- **Queue parity.** The same action, actor, and input run identically inline and on a queue, which is
  the primary requirement.
- **Audit/observability** become opt-in subscribers to first-class events rather than per-action code.
- **`Lockable` change is shared.** Switching its exception to `LockUnavailableException` also affects
  the non-service users of the trait; the single new `ApiExceptionHandler` mapping preserves the
  existing 429 behaviour for HTTP callers.

## Decisions (confirmed)

The four forks are settled:

1. **Input model** - a single self-validating typed input (`InputData`, implementing `ServiceInput`)
   that replaces the FormRequest for actions: typed properties for shape, attributes for validation, one
   source of truth, hydratable from request/array/named-args and queue-safe (see §2). The attribute-to-
   rules compiler is hand-rolled (no `spatie/laravel-data` dependency); `ArrayInput` is the trivial
   escape hatch and a laravel-data `Data` object still satisfies the contract for teams that use it.
2. **Authorization ownership** - the service offers an optional `authorize()` hook using the explicit
   actor (it remains valid for a controller/middleware to authorize too; the hook is for action-level
   rules, and accommodates the existing permission-binding pattern).
3. **Actor on the queue** - a re-resolvable reference plus a label snapshot.
4. **Name** - keep `Service` (namespace continuity).

All forks are settled and the ADR is **Accepted**. Implementation proceeds as its own PR, sequenced
behind decoupling `Lockable` from `TooManyRequestsException`.
