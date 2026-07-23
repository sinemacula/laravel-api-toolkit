# Upgrade Guide

## From 1.x to 2.x

### Moved: schema introspection and validation into the Schema namespace

Schema introspection and validation no longer live under the `Services\` namespace (which now owns only the
action layer). They have moved into `Schema\Introspection\` and `Schema\Validation\`. Update any imports of
the affected classes.

The consumer-facing classes:

    SineMacula\ApiToolkit\Services\SchemaIntrospector
    -> SineMacula\ApiToolkit\Schema\Introspection\SchemaIntrospector

    SineMacula\ApiToolkit\Services\SchemaValidator
    -> SineMacula\ApiToolkit\Schema\Validation\SchemaValidator

The supporting value objects and validation rules moved alongside them:

    SineMacula\ApiToolkit\Services\Introspection\ColumnDefinition
    -> SineMacula\ApiToolkit\Schema\Introspection\ColumnDefinition

    SineMacula\ApiToolkit\Services\Validation\SchemaValidationError
    -> SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError

    SineMacula\ApiToolkit\Services\Validation\Rules\*
    -> SineMacula\ApiToolkit\Schema\Validation\Rules\*

### Composer dependency changes

The logging drivers and the server-sent event streaming support have been extracted into standalone
packages: the CloudWatch log driver now lives in `sinemacula/laravel-log-cloudwatch`, the database log
driver in `sinemacula/laravel-log-database`, and SSE streaming in `sinemacula/laravel-sse`. Applications
that use any of them must require the relevant package directly:

    composer require sinemacula/laravel-log-cloudwatch
    composer require sinemacula/laravel-log-database
    composer require sinemacula/laravel-sse

Applications that use none of these features require no action.

The toolkit now depends on `sinemacula/http-primitives-php`, which is installed automatically and replaces
the internal `HttpStatus` enum (see the next section).

### Removed: HttpStatus enum

The internal `SineMacula\ApiToolkit\Enums\HttpStatus` enum has been removed in favour of the shared
`SineMacula\Http\Enums\HttpStatus` enum provided by `sinemacula/http-primitives-php`.

**Before (1.x):**

    use SineMacula\ApiToolkit\Enums\HttpStatus;

    $code = HttpStatus::NOT_FOUND->getCode();

**After (2.x):**

    use SineMacula\Http\Enums\HttpStatus;

    $code = HttpStatus::NOT_FOUND->getCode();

Custom exceptions extending `ApiException` must type their `HTTP_STATUS` constant with the new enum:

    use SineMacula\Http\Enums\HttpStatus;

    class TeapotException extends ApiException
    {
        public const HttpStatus HTTP_STATUS = HttpStatus::IM_A_TEAPOT;
    }

The shared enum only defines standard HTTP status codes. The non-standard `419` code has no case, so
`HttpStatus::TOKEN_MISMATCH` no longer exists -- `TokenMismatchException` now overrides `getStatusCode()`
to return `419` directly. Custom exceptions that need a non-standard status code should override
`getStatusCode()` in the same way.

### Services: transactions and locking move to class properties

The `Service` base class has been rebuilt around a typed input, an explicit actor, a fixed
transaction-aware lifecycle, and an immutable `ServiceResult`. The 1.x runtime toggles and the `bool`
return value are gone. The following has been removed from the base class:

- `useTransaction()`, `dontUseTransaction()`, `useLock()`, `dontUseLock()` (runtime fluent toggles)
- the `$useTransaction` and `$useLock` properties
- `getStatus(): ?bool` (the outcome is now the return value of `run()`; see below)
- the `success()` and `failed()` lifecycle hooks (replaced by `afterCommit()` and `onFailure()`)

Transactions and locking are now declared as class-level properties instead of toggled at runtime:

    use SineMacula\ApiToolkit\Services\Service;

    class MyService extends Service
    {
        /** Wrap prepare() + handle() in a database transaction. */
        protected bool $transactional = true;

        /** Number of transaction retry attempts. */
        protected int $transactionAttempts = 3;

        /** Acquire a cache lock around the whole pipeline. */
        protected bool $lockable = false;

        protected function handle(): mixed
        {
            // ...
        }
    }

**The default behaviour is unchanged.** As in 1.x, a service runs inside a database transaction by
default (`$transactional = true`) and does not lock (`$lockable = false`). Set `$transactional = false`
on services that must not open a transaction; set `$lockable = true` on services that need a cache lock.

A lockable service must return a non-empty lock identity from `lockId()` (which replaces the 1.x
`getLockId()`). The runner throws `LockOperationException` when `$lockable` is `true` but `lockId()`
returns an empty string. The final cache-lock key is `sha1(static::class . '|' . lockId())`; see the
LockKeyProvider section below.

### Services: typed input and an explicit actor

A service is constructed with a typed input rather than a raw payload, and the causer is supplied
explicitly - the action layer never reads `Auth` or the current `Request` ambiently.

**Input.** The constructor takes a `ServiceInput` (the contract is a single `toArray(): array`). Two
implementations ship: extend `InputData` to declare promoted readonly properties plus Laravel `rules()`
and build a validated instance with `InputData::from($request)` (or `from($array)`), or wrap an
already-validated array in `ArrayInput` for the no-class case.

    use SineMacula\ApiToolkit\Services\Input\ArrayInput;

    $result = (new MyService(new ArrayInput(['title' => 'Hello'])))->run();

**Actor.** Attach the causer with `by()`; it is read inside the service via `actor()` and defaults to an
`AnonymousActor` when none is set. Three actors ship: `EloquentActor::for($user)` wraps an
authenticatable model and is queue-serialisable, `SystemActor` represents a trusted internal caller and
short-circuits the `authorize()` hook, and `AnonymousActor` represents an unauthenticated caller.

    use SineMacula\ApiToolkit\Services\Actors\EloquentActor;

    $result = (new MyService($input))->by(EloquentActor::for($user))->run();

**Resolution, context and queueing.** `Service::make($input)` resolves the service through the container,
so it may declare its own constructor dependencies. `withContext()` attaches a prebuilt `ServiceContext`
(actor, correlation id, source, and metadata). `dispatch()` pushes the service onto the queue via
`ServiceJob`, which re-runs it identically on the worker with the source set to `QUEUE`.

### Services: the fixed lifecycle and its hooks

`ServiceRunner` sequences the lifecycle in a single fixed, transaction-aware order:

    authorize -> validate -> [lock] -> [transaction] -> concerns -> prepare -> handle
    -> commit -> [release lock] -> afterCommit

On failure the transaction rolls back, the lock is released, and `onFailure()` runs. The runner never
throws for business failures; every outcome is captured on the returned `ServiceResult`.

The hooks a subclass may override (all `protected`, all no-ops by default except `handle()`):

- `authorize(): void` - runs before the lock and transaction; throw an authorization exception to deny.
  Skipped automatically for a `SystemActor`.
- `validate(): void` - runs before the lock and transaction; throw a validation exception for bad input.
- `prepare(): void` - runs inside the transaction, before `handle()` (in 1.x `prepare()` was `public`).
- `handle(): mixed` - abstract; runs inside the transaction and **returns** the typed output. Signal
  failure only by throwing (in 1.x `handle()` returned `bool`).
- `afterCommit(mixed $output): void` - replaces the 1.x `success()` hook; runs after the transaction has
  committed. An exception thrown here is captured as a side-effect error on the result and logged; the
  committed outcome stands.
- `onFailure(\Throwable $exception): void` - replaces the 1.x `failed()` hook; runs after rollback and
  lock release. An exception thrown here is caught and logged.

### Services: cross-cutting concerns implement ServiceConcern

`concerns()` returns an ordered list of `ServiceConcern` class-strings. Each concern is resolved from the
container and wraps the core (`prepare()` + `handle()`) inside the transaction, in declaration order (the
first entry is the outermost wrapper). The contract is a single method:

    public function handle(ServiceContext $context, \Closure $next): mixed;

Call `$next()` to continue the pipeline; return or transform its result. For example:

    use Closure;
    use SineMacula\ApiToolkit\Services\Contracts\ServiceConcern;
    use SineMacula\ApiToolkit\Services\ServiceContext;

    class RetryConcern implements ServiceConcern
    {
        public function handle(ServiceContext $context, Closure $next): mixed
        {
            return retry(3, $next);
        }
    }

    class MyService extends Service
    {
        protected function concerns(): array
        {
            return [RetryConcern::class];
        }

        protected function handle(): mixed
        {
            // ...
        }
    }

Do not place transaction or locking entries in `concerns()`. They are not `ServiceConcern`
implementations; they are internal stages driven by the `$transactional` and `$lockable` properties
above. `concerns()` is only for your own cross-cutting wrappers.

### Changed: Service::run() returns a ServiceResult value object

`Service::run()` now returns an immutable `ServiceResult` instead of `bool`, and `getStatus(): ?bool` has
been removed. The result carries:

- `status` - a `ServiceStatus` enum (`SUCCEEDED` or `FAILED`); query it with `succeeded()` or `failed()`.
- `output` - the value returned by `handle()`; read it via the `output` property, `output()`, or
  `outputOr($default)` (which returns the default whenever the result failed).
- `exception` - the captured `Throwable` on failure, or `null` when failure was signalled without
  throwing.
- `sideEffectErrors` - any `Throwable`s caught from `afterCommit()`, via `sideEffectErrors()`.
- `throw()` - rethrows the captured exception when the result failed, otherwise returns the result for
  chaining.

Exceptions thrown by the core lifecycle are no longer rethrown from `run()`; they are passed to
`onFailure()` and captured on the result, and the transaction still rolls back.

**Before (run returns bool, exceptions propagate):**

    try {
        $status = (new MyService($input))->run();
    } catch (Throwable $exception) {
        // handle failure
    }

**After (self-describing result):**

    $result = (new MyService($input))->run();

    if ($result->failed()) {
        // $result->exception is the captured Throwable, or null when
        // handle() signalled failure without throwing
    }

    $value = $result->output();

`handle()` returns the output directly - there is no `$data` property and no `$result->data`. Code that
relied on `run()` throwing should rethrow from the result instead:

    $value = (new MyService($input))->run()->throw()->output();

### Removed: Implicit trait lifecycle hooks on services

In 1.x, traits used by a service participated in the lifecycle through naming conventions: a static
`initialize{TraitName}()` method was invoked during service initialization, and a `{traitName}Success()`
method was invoked after a successful run. This implicit discovery has been removed.

Move initialization logic into the constructor or `prepare()`, move post-success side effects into
`afterCommit()`, or express the behavior as a `ServiceConcern` (see above).

### Lock key generation via LockKeyProvider contract

The `Lockable` trait no longer declares
`abstract generateLockKey()`. The `Service` class now implements
`LockKeyProvider` with a `getLockKey()` method that contains the
same logic.

**Impact on Service subclasses:** Subclasses that previously
overrode `generateLockKey()` must now override `getLockKey()`
instead. The method visibility changes from `protected` to
`public`.

**Before:**

    class MyService extends Service
    {
        protected function generateLockKey(): string
        {
            return sha1('custom-key');
        }
    }

**After:**

    class MyService extends Service
    {
        public function getLockKey(): string
        {
            return sha1('custom-key');
        }
    }

**Impact on standalone Lockable consumers:** Classes using
`Lockable` without extending `Service` should implement
`LockKeyProvider` and provide a `getLockKey()` method instead of
overriding `generateLockKey()`. Alternatively, the `$lockKey`
property may be set directly. The trait's `lock()` and `unlock()`
methods are now `public` (previously `protected`).

**Before:**

    class MyJob
    {
        use Lockable;

        protected function generateLockKey(): string
        {
            return sha1('job-lock');
        }
    }

**After:**

    use SineMacula\ApiToolkit\Contracts\LockKeyProvider;

    class MyJob implements LockKeyProvider
    {
        use Lockable;

        public function getLockKey(): string
        {
            return sha1('job-lock');
        }
    }

### Removed: ServiceLockException

The `ServiceLockException` class has been removed. Lock handling now uses two `\RuntimeException`
subclasses: `LockUnavailableException` when the cache lock is contended and cannot be acquired, and
`LockOperationException` when a lockable service supplies an empty `lockId()` or no lock key. Under the
action layer a contended lock is not thrown to the caller; the runner captures it on the result, so
`(new MyService($input))->run()->exception` holds a `LockUnavailableException` on contention.

Any code that catches `ServiceLockException` should catch `LockUnavailableException` instead (or be
removed if the catch block was unreachable).

### Removed: RepositoryResolver and HasRepositories

The static `RepositoryResolver`, the `HasRepositories` trait, and the `repositories.repository_map` config
key have been removed. Repositories are now resolved through standard Laravel dependency injection.

**Before (1.x):**

    use SineMacula\ApiToolkit\Repositories\Traits\HasRepositories;

    class UserController extends Controller
    {
        use HasRepositories;

        public function index()
        {
            return $this->users()->all();
        }
    }

**After (2.x):**

    class UserController extends Controller
    {
        public function __construct(

            private readonly UserRepository $users,

        ) {}

        public function index()
        {
            return $this->users->all();
        }
    }

Direct calls to `RepositoryResolver::get('alias')` should be replaced with `app(UserRepository::class)` or,
preferably, constructor injection. Remove any `repository_map` entries from a published
`config/api-toolkit.php`; the key is no longer read.

### Renamed: ApiRepository::setAttributes() to persist()

The `setAttributes()` method on `ApiRepository` has been renamed to `persist()`. The signature and behavior
are unchanged.

**Before:**

    $repository->setAttributes($model, $attributes);

**After:**

    $repository->persist($model, $attributes);

### Changed: Repository caching is now per-query (sinemacula/laravel-repositories)

Repository caching lives in the `sinemacula/laravel-repositories` dependency, and its `Cacheable` trait
(`SineMacula\Repositories\Concerns\Cacheable`) previously cached every read against a single whole-table
snapshot. A filtered or by-id read could therefore be served the entire table from the cache, and
populating the cache issued a second query. The trait now caches **per query**: each executed query is
fingerprinted and stored under its own key, so a filtered read never returns the full-table collection,
and a cache hit performs zero database queries.

**What changed:**

- Cache entries are keyed per query fingerprint instead of one whole-table snapshot; the whole-table
  shape is retained for reference mode only.
- Write invalidation is now driven by an explicit write-verb list (`create`, `forceCreate`, `firstOrCreate`,
  `updateOrCreate`, `updateOrInsert`, `update`, `delete`, `forceDelete`, `save`, `insert`, `insertGetId`,
  `upsert`, `increment`, `decrement`, `restore`) instead of sniffing the return type. `create()` returning
  a model now correctly invalidates the cache; `count()` returning an integer no longer does.
- A size guard (`max_rows` / `max_bytes`) skips storing oversized results; the read still executes and
  returns normally.
- A by-id read that misses (returns `null`) is negatively cached for a short, separate `negative_ttl`
  (default 10 seconds), bounding how long a stale "not found" is served.

The public API is unchanged: `withoutCache()`, `flushCache()`, and `getCacheStatus()` behave as before.

**Action required:** none for most applications. If you relied on the old whole-table behaviour — a small,
static reference table served entirely from cache with cross-request persistence — opt back into it per
repository:

    protected bool $cacheReferenceTable = true;

**Recommendation:** use a taggable cache store (Redis, Memcached) for precise per-table invalidation. On a
non-taggable store (file, database) the package invalidates per-query entries through a generational table
version that is bumped on every write; set `REPOSITORY_CACHE_REGISTRY_ENABLED=false` to fall back to
TTL-only staleness.

**Staleness boundary:** writes made outside the repository (raw Eloquent inserts) are not observed for
cached results until the TTL (or `negative_ttl` for cached misses) expires or a repository write flushes
the table.

Configuration lives in that package under `repositories.cache` (`prefix`, `ttl`, `store`, `max_rows`,
`max_bytes`, `reference_ttl`, `negative_ttl`, `registry_enabled`; env `REPOSITORY_CACHE_*`); each value is
overridable per repository via a protected property. See the `sinemacula/laravel-repositories`
documentation for full details.

### Changed: Relation detection requires return type declarations

Relation detection -- used by filtering, attribute persistence, resource value resolution, and schema
validation -- no longer
invokes model methods to discover whether they return a `Relation`. The `SchemaIntrospector` now inspects
declared return types via reflection. A model method is treated as a relation only when its return type (or
one member of a union type) is a subclass of `Illuminate\Database\Eloquent\Relations\Relation`.

**Before (1.x -- detected without a return type):**

    public function posts()
    {
        return $this->hasMany(Post::class);
    }

**After (2.x -- return type required):**

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

Relation methods without a `Relation` return type are silently no longer detected -- filters and eager loads
referencing them stop working -- so audit your models before upgrading. Enabling the new opt-in boot-time
schema validation (`api-toolkit.resources.validate_schemas`, or the `api-toolkit:validate-schemas` command) surfaces
schema relations that no longer resolve.

Dynamic relations registered with `Model::resolveRelationUsing()` are now detected and resolved; 1.x did not
support them.

### Removed: BaseResource; ApiResource internals decomposed

`SineMacula\ApiToolkit\Http\Resources\BaseResource` has been removed. `ApiResource` now extends the
toolkit's `ToolkitResource` base (itself a `Illuminate\Http\Resources\Json\JsonResource`), and
`withFields()`, `withoutFields()`, and `withAll()` live on `ApiResource` itself. Update any type hints or subclasses referencing `BaseResource` to
use `ApiResource` (or `ApiResourceInterface`).

The protected resolution hooks have been removed from `ApiResource` and moved into internal collaborator
classes:

- `getFields()`
- `resolveFieldValue()`
- `resolveSimpleProperty()`
- `resolveComputedValue()`
- `resolveAccessorValue()`
- `resolveRelationValue()`
- `passesGuards()`
- `resolveCountsPayload()`

Subclasses that overrode these methods must express the behavior through the resource schema definition
(fields, computed values, accessors, guards) instead.

`ApiResourceInterface` now declares the full field-resolution surface (`getResourceType()`,
`getDefaultFields()`, `schema()`, `getAllFields()`, `resolveFields()`, `eagerLoadMapFor()`,
`eagerLoadCountsFor()`, `eagerLoadSumsFor()`, `eagerLoadAveragesFor()`, `resolve()`, `withFields()`,
`withoutFields()`, and `withAll()`). Classes implementing the interface directly -- rather than extending
`ApiResource` -- must implement the new methods.

Parameter names have also been normalized to camelCase (`$load_missing` is now `$loadMissing` on the
constructor, `$requested_aliases` is now `$requestedAliases` on `eagerLoadCountsFor()`); update any
named-argument call sites.

### Changed: ApiCriteria decomposed; filter operators are extensible

The protected `applyFilters()`, `applyEagerLoading()`, `applyLimit()`, and `applyOrder()` hooks have been
removed from `ApiCriteria`; the logic now lives in dedicated internal concern classes. Subclasses that
overrode these hooks to add custom filter behavior should instead register a custom operator.

Filter operators are now first-class: implement the `SineMacula\ApiToolkit\Contracts\FilterOperator`
contract and register it on the `OperatorRegistry` singleton (for example, in a service provider):

    use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;

    app(OperatorRegistry::class)->register('$regex', new RegexOperator);

The registry also exposes `override()` and `remove()` for adjusting the built-in operator set.

### Changed: Filtering and sorting are allowlist-by-default

API query filtering and sorting are now **allowlist-by-default**. A resource declares which columns are
filterable and sortable, and which relations may be traversed, in its schema. Any filter, sort, or
relation key the resource has not declared is rejected with a `422` validation error.

In 1.x and earlier 2.x builds the posture was the opposite: every column the model exposed was
filterable and sortable unless it was named in `searchable_exclusions` (a blocklist). The exclusion list
was the only line of defence, so a newly added column was queryable the moment it reached the table. The
posture is now inverted -- a key is queryable only when the schema declares it intentionally.

**Declare the query surface** with the fluent markers on the schema DSL:

    use SineMacula\ApiToolkit\Schema\Field;
    use SineMacula\ApiToolkit\Schema\Relation;

    public static function schema(): array
    {
        return Field::set(
            Field::scalar('id')->filterable()->sortable(),
            Field::scalar('name')->filterable()->sortable(),
            Field::scalar('email')->filterable(),
            Relation::to('posts', PostResource::class)->traversable(),
        );
    }

A field's filter and sort key is its **column name**, not its presentation alias.
`Field::scalar('email_address', 'email')->filterable()` declares `email_address` as the filterable
column even though the field is presented to clients as `email`.

**Action required.** Audit every API resource and add `filterable()`, `sortable()`, and `traversable()`
to the fields and relations clients are expected to query. Keys that clients currently rely on but the
schema does not declare will start returning `422` until they are declared. A resource with no declared
surface rejects every filter and sort key.

**Restore the previous behaviour** by switching back to the blocklist posture:

    API_TOOLKIT_QUERY_POSTURE=blocklist

Under `blocklist` the legacy shape-derived behaviour applies: every model column is filterable and
sortable unless excluded via `searchable_exclusions`. This is a transitional escape hatch; new
applications should adopt the allowlist posture.

**Fail-closed vs fail-quiet.** By default an undeclared key on the root resource is rejected with a named
`422` validation error so clients learn immediately which key is not permitted (`reject_undeclared`,
default `true`). Switch it off to silently drop undeclared keys instead:

    API_TOOLKIT_REJECT_UNDECLARED=false

A dropped key applies no constraint, so a filter the client believes is active is silently ignored --
prefer the default fail-closed behaviour unless a quiet drop is specifically required.

**Widened default exclusions.** The default `searchable_exclusions` (used under the blocklist posture)
now also covers `two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at`, and
`email_verified_at` alongside the existing `password`, `token`, and `remember_token`, so even the legacy
posture leaks fewer sensitive columns out of the box.

### Removed: Request macros in favour of RequestCapabilities

The request macros the toolkit used to register - `includeTrashed()` and `onlyTrashed()` - have
been removed. Resolve these capabilities through the typed
`RequestCapabilities` value object instead. The export-detection macros (`expectsExport()`,
`expectsCsv()`, and `expectsXml()`) have been removed outright: content-negotiated exports now live in the
`sinemacula/laravel-resource-exporter` package, not in the toolkit.

**Before:**

    if ($request->includeTrashed()) {
        // ...
    }

**After:**

    use SineMacula\ApiToolkit\Http\RequestCapabilities;

    $capabilities = RequestCapabilities::fromRequest($request);

    if ($capabilities->includeTrashed()) {
        // ...
    }

The new `DetectsCapabilities` middleware (registered globally by default, configurable via the
`api-toolkit.middleware` config section) precomputes the capabilities once per request; `fromRequest()`
falls back to resolving them lazily when the middleware has not run.

### Exception handling changes

These changes are largely additive but include behavioral fixes worth noting:

- New exception classes are available: `ConflictException` (409), `GoneException` (410),
  `PayloadTooLargeException` (413), `LockedException` (423), `ServiceUnavailableException` (503), and a
  generic `HttpException` carrying an arbitrary `HttpStatus`.
- Unmapped HTTP-layer exceptions (any Symfony `HttpExceptionInterface`) now preserve their original status
  code via the generic `HttpException` instead of rendering as a `500` unhandled error.
- Session token mismatches converted by Laravel to a generic `419` HTTP exception are now mapped back to
  `TokenMismatchException` and render as `419`; in 1.x they rendered as a `500` unhandled error.
- `PolymorphicResource` now throws `ResourceMappingException` (a `\LogicException` subclass) instead of a
  bare `\LogicException`; existing catch blocks for `\LogicException` continue to work.
- The new `api-toolkit.exceptions` config section controls rendering (`render_strategy`) and debug metadata
  (`include_debug_info`). The defaults preserve the 1.x behavior.
- `ApiException` exposes a new instance-level `getStatusCode()` method, which the handler now uses when
  rendering; the static `getHttpStatusCode()` remains available.

### Changed: Deferred writes are safe-by-default

The deferred-write pool (the `Deferrable` trait, backed by `WritePool`) no longer drops records
silently on a flush failure. The default `on_failure` strategy has changed from `log` to `collect`.

**Before (1.x / earlier 2.x -- log and drop):** a chunk insert failure during flush was logged at
error level, the rest of the buffer continued, and the **entire buffer was cleared**, discarding the
failed records. A configured `throw` was also swallowed by the boundary subscriber and downgraded to
a log line, and a memory-pressure auto-flush silently switched `throw` to `collect`.

**After (2.x -- collect and retain):** the three strategies now mean:

- `collect` (new default, safe): catch every chunk failure, accumulate it in the returned
  `WritePoolFlushResult`, and **retain the failed records in the buffer** for the next flush attempt.
  No record is dropped and no exception escapes. The boundary subscriber logs a warning and dispatches
  the `WritePoolFlushFailed` event.
- `throw` (safe, explicit): raise `WritePoolFlushException` on the first failure, carrying the partial
  result, and preserve the failed and unprocessed records. The memory-pressure auto-flush now honours
  this -- `defer()` / `add()` may raise when the pool limit is crossed.
- `log` (opt-in best-effort): catch, log at error level, continue, and **clear the buffer** (the old
  default behaviour). Failed records are dropped; use this only for genuinely disposable writes such
  as audit, analytics, or telemetry.

**Restore the previous behaviour** by opting back into the log strategy:

    DEFERRED_WRITES_ON_FAILURE=log

**New, behaviour-preserving config keys (both default off):**

    # Wrap each table's chunk set in a transaction (all-or-nothing per table).
    DEFERRED_WRITES_TRANSACTIONAL=true

    # Re-throw a WritePoolFlushException at the lifecycle boundary after
    # escalating it (only applies under the 'throw' strategy).
    DEFERRED_WRITES_RETHROW_AT_BOUNDARY=true

**Boundary 500s.** The default was deliberately set to `collect` rather than `throw`: the boundary
flush runs on `RequestHandled` *after* the response is built, so a default-throw would turn a single
constraint violation in a buffered batch into a 500 for an already-completed request. Consumers that
want a raised exception should call `flushWrites()` explicitly under the `throw` strategy, or enable
`DEFERRED_WRITES_RETHROW_AT_BOUNDARY` in a context (such as a queue job) that can absorb it.

**Crash window.** Buffered writes live only in PHP memory until the boundary flush. A crash,
out-of-memory condition, or SIGKILL before the flush loses any unflushed records. This is inherent to
in-memory deferral; for true durability use a real queue. Under Octane the pool is request-scoped, so
retained records are a within-request retry and are discarded when the scope resets between requests --
use `log` for fire-and-forget writes that must never be retained.

**New observability on `WritePoolFlushResult`.** The result now exposes record-level counts --
`flushedRecordCount()`, `failedRecordCount()`, `retainedRecordCount()`, and `droppedRecordCount()` --
alongside the existing chunk counters, plus `flushedTables()` listing every table the flush attempted
to persist. Subscribe to the `WritePoolFlushFailed` event to escalate retained failures to a
dead-letter sink, alerting, or metrics.

**Per-query cache invalidation at the boundary (on by default).** A deferred insert is persisted
through the write pool's bulk INSERT, which bypasses the per-query cache invalidation that fires on a
repository's own write verbs. To keep read-after-deferred-write consistent, the lifecycle-boundary
flush now invalidates the per-query repository cache for every table it persisted, mirroring what an
immediate write does. This is **best-effort**: it covers `Cacheable` repositories on the default cache
configuration (the configured repository cache store, keyed by table name). A repository on a custom
cache store (`cacheStoreName`) or key prefix (`cacheKeyPrefix`) is not reached and must invalidate
manually -- call `flushCache()` after the boundary flush, or rely on the cache TTL.

Disable the automatic invalidation (for example if every Cacheable repository invalidates manually, or
none of the deferred tables are cached) with:

    DEFERRED_WRITES_INVALIDATE_QUERY_CACHE=false

### Changed: Lifecycle metadata flush is now on by default on serving runtimes

Under 2.x, the cross-request metadata flush ships **enabled by default** on runtimes that are actively
serving requests under Octane or running as a queue worker. php-fpm is unaffected because each request
already starts with a clean process; the runtime detector gates engagement and does not fire under
php-fpm even when Octane is installed.

**Runtime detection.** Serving is discriminated from mere installation:

- Octane: the `$_SERVER['LARAVEL_OCTANE']` superglobal is set by a booted Octane worker, not by
  package installation, so the flush only engages when the worker is actually serving.
- Queue worker: `JobProcessed` and `JobFailed` fire inside a real worker loop. A job dispatched over
  the `sync` driver fires the same events within the originating HTTP request; the toolkit checks the
  connection driver and treats `sync` as a non-worker boundary, leaving php-fpm unaffected.

**What survives across requests (the cache-site inventory).** Three in-process metadata caches
accumulate state across requests under a long-lived runtime:

- The process-static schema compile cache (`SchemaCompiler::$cache`), cleared by
  `SchemaCompiler::clearCache()`.
- The `SchemaIntrospector` singleton's in-memory arrays (column definitions, relations, resources),
  cleared by its `flush()` method.
- The `Cache::memo()` `rememberForever` metadata store -- the toolkit metadata keys: model schema
  columns, column definitions, relations, resources, and repository model casts.

The single surface that clears all three is `CacheManager::flush()`, invoked automatically by the
Octane and queue lifecycle listeners at every request/job boundary.

**Scoped flush -- what is NOT cleared.** The flush is scoped to the toolkit's own metadata keys via
a key registry and per-key `Cache::memo()->forget()` calls. It does **not** issue a whole-store
clear. Non-toolkit application keys and repository result caches on a shared cache store survive the
flush. Any new toolkit metadata key must be written through the `MetadataCacheWriter` chokepoint so
it is registered and cleared at the next boundary.

**Re-warm trade-off.** Clearing metadata at the serving boundary means the next request re-warms
that metadata from the database (a small, bounded re-introspection cost). This is the price of
correct schema and cast data after a deploy without a worker restart. Operators who accept potential
staleness in exchange for zero re-warm cost should use the opt-out below.

**Action required.** No action is needed for most applications. The flush is additive on Octane and
queue-worker runtimes; php-fpm behaviour is unchanged.

**Restore the previous behaviour** (metadata is not flushed at boundaries -- staleness risk on
long-lived runtimes after a deploy):

    API_TOOLKIT_LIFECYCLE_OCTANE=false
    API_TOOLKIT_LIFECYCLE_QUEUE=false

Or set the equivalent config keys to `false` in a published `config/api-toolkit.php`:

    'lifecycle' => [
        'octane' => false,
        'queue'  => false,
    ],

When a serving runtime is detected but the flush is opted out, the toolkit logs a one-line
`Log::info` diagnostic so the disabled state is not silent.

### Removed: ProvidesExclusiveLock listener trait

The `ProvidesExclusiveLock` listener trait has been removed. It had no internal consumers - the
shipped listeners are idempotent or boundary-driven and never mixed it in. Any downstream listener
that used it must provide its own locking, for example via the toolkit's `Lockable` concern.
