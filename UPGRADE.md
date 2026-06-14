# Upgrade Guide

## From 1.x to 2.x

### Composer dependency changes

The CloudWatch logging dependencies (`aws/aws-sdk-php` and `phpnexus/cwh`) have moved from `require` to
`suggest`. Applications that use the CloudWatch logging driver must now require them directly:

    composer require aws/aws-sdk-php phpnexus/cwh

Applications that do not use CloudWatch logging require no action.

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

### Services: composable concern pipeline replaces transaction and lock configuration

The following have been removed from the `Service` base class:

- `useTransaction()`
- `dontUseTransaction()`
- `useLock()`
- `dontUseLock()`
- the `$useTransaction` and `$useLock` properties

Transactions and locking are now cross-cutting concerns declared per service via the `concerns()` method.
Each concern wraps the core lifecycle (`prepare()`, `handle()`, `success()`/`failed()`); the first concern
in the array is the outermost wrapper.

**Before (1.x):**

Callers configured service behavior at runtime using fluent methods or property overrides:

    $service = new MyService($payload);
    $service->dontUseTransaction();
    $service->useLock();
    $service->run();

    class MyService extends Service
    {
        protected bool $useTransaction = false;

        protected bool $useLock = true;
    }

**After (2.x):**

Configuration is declared at the class level via the `concerns()` method:

    use SineMacula\ApiToolkit\Services\Concerns\LockConcern;
    use SineMacula\ApiToolkit\Services\Concerns\TransactionConcern;

    class MyService extends Service
    {
        protected function concerns(): array
        {
            return [
                LockConcern::class,
                TransactionConcern::class,
            ];
        }

        protected function handle(): bool
        {
            // ...
        }

        protected function getLockId(): string
        {
            return 'my-service-lock-id';
        }
    }

The caller no longer needs to configure the service externally:

    $service = new MyService($payload);
    $service->run();

**The default behavior has changed.** In 1.x every service ran inside a database transaction by default
(`$useTransaction = true`). In 2.x the base `concerns()` returns an empty array, so a service with no
override runs with no transaction and no locking. Services that relied on the implicit transaction must now
declare `TransactionConcern::class` explicitly.

Custom concerns implement the `ServiceConcern` contract and are resolved from the container, so they may
declare their own constructor dependencies:

    use SineMacula\ApiToolkit\Services\Contracts\ServiceConcern;
    use SineMacula\ApiToolkit\Services\Service;

    class RetryConcern implements ServiceConcern
    {
        public function execute(Service $service, \Closure $next): bool
        {
            return retry(3, $next);
        }
    }

`LockConcern` only takes effect on services whose class uses the `Lockable` trait; the base `Service`
already uses it, so declaring the concern is sufficient.

### Changed: Service::run() returns a ServiceResult value object

`ServiceInterface::run()` now returns an immutable `ServiceResult`
instead of `bool`, and `getStatus(): ?bool` has been removed. The
result carries the outcome status (a `ServiceStatus` enum), optional
result data, and the captured exception on failure.

Exceptions thrown by the core lifecycle are no longer rethrown from
`run()` — they are passed to the `failed()` hook and captured on the
returned result. Transactions still roll back as before. The
`success()` hook now only fires when the service actually succeeded.

**Before (run returns bool, exceptions propagate):**

    try {
        $status = (new MyService($payload))->run();
    } catch (Throwable $exception) {
        // handle failure
    }

**After (self-describing result):**

    $result = (new MyService($payload))->run();

    if ($result->failed()) {
        // $result->exception is the captured Throwable, or null when
        // the handler signalled failure by returning false
    }

    $value = $result->data;

Services expose output by assigning to the protected `$data`
property inside `handle()`; the value is carried on the result.

Code that relied on `run()` throwing must now inspect the result and
rethrow explicitly if desired:

    if ($result->failed() && $result->exception !== null) {
        throw $result->exception;
    }

### Removed: Implicit trait lifecycle hooks on services

In 1.x, traits used by a service participated in the lifecycle through naming conventions: a static
`initialize{TraitName}()` method was invoked during service initialization, and a `{traitName}Success()`
method was invoked after a successful run. This implicit discovery has been removed.

Move initialization logic into the constructor or `prepare()`, move post-success side effects into
`success()`, or express the behavior as a `ServiceConcern` (see above).

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

The `ServiceLockException` class has been removed. It was never
thrown by the framework -- lock acquisition failures use
`TooManyRequestsException`.

Any code that catches `ServiceLockException` should be updated
to catch `TooManyRequestsException` instead (or removed if the
catch block was unreachable).

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

### Changed: Repository caching is now per-query

The `Cacheable` trait previously cached every read against a single whole-table snapshot. A filtered or
by-id read could therefore be served the entire table from the cache, and populating the cache issued a
second query. The trait now caches **per query**: each executed query is fingerprinted and stored under its
own key, so a filtered read never returns the full-table collection, and a cache hit performs zero database
queries.

**What changed:**

- The default cache key changed from `repository-cache:<table>` (whole table) to
  `repository-query:<table>:<hash>` (per query). The old keys are retained for reference mode only.
- Write invalidation is now driven by an explicit write-verb list (`create`, `forceCreate`, `firstOrCreate`,
  `updateOrCreate`, `updateOrInsert`, `update`, `delete`, `forceDelete`, `save`, `insert`, `insertGetId`,
  `upsert`, `increment`, `decrement`, `restore`) instead of sniffing the return type. `create()` returning
  a model now correctly invalidates the cache; `count()` returning an integer no longer does.
- A size guard (`max_rows` / `max_bytes`) skips storing oversized results; the read still executes and
  returns normally.

The public API is unchanged: `withoutCache()`, `flushCache()`, and `getCacheStatus()` behave as before.

**Action required:** none for most applications. If you relied on the old whole-table behaviour — a small,
static reference table served entirely from cache with cross-request persistence — opt back into it per
repository:

    protected bool $cacheReferenceTable = true;

**Recommendation:** use a taggable cache store (Redis, Memcached) for precise per-table invalidation. On a
non-taggable store (file, database) the toolkit keeps a per-table registry of live keys so writes can
invalidate them; set `api-toolkit.repositories.cache.registry_enabled` to `false` to fall back to TTL-only
staleness.

**Staleness boundary:** a by-id read that misses (returns `null`) is **not cached** — the query re-executes
until the row exists. Empty Collections are cached normally. Writes made outside the repository (raw Eloquent
inserts) are not observed for cached non-null results until the TTL expires or a repository write flushes the
table.

New configuration lives under `repositories.cache` (`ttl`, `store`, `max_rows`, `max_bytes`,
`reference_ttl`, `registry_enabled`); each value is overridable per repository via a protected property.

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
schema validation (`api-toolkit.resources.validate_schemas`, or the `api:validate-schemas` command) surfaces
schema relations that no longer resolve.

Dynamic relations registered with `Model::resolveRelationUsing()` are now detected and resolved; 1.x did not
support them.

### Removed: BaseResource; ApiResource internals decomposed

`SineMacula\ApiToolkit\Http\Resources\BaseResource` has been removed. `ApiResource` now extends
`Illuminate\Http\Resources\Json\JsonResource` directly, and `withFields()`, `withoutFields()`, and
`withAll()` live on `ApiResource` itself. Update any type hints or subclasses referencing `BaseResource` to
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

`ApiResourceInterface` now declares the full field-resolution surface (`schema()`, `getAllFields()`,
`resolveFields()`, `eagerLoadMapFor()`, `eagerLoadCountsFor()`, `resolve()`, `withFields()`,
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

### Deprecated: Request macros in favour of RequestCapabilities

The request macros registered by the toolkit -- `includeTrashed()`, `onlyTrashed()`, `expectsExport()`,
`expectsCsv()`, `expectsXml()`, `expectsPdf()`, and `expectsStream()` -- are deprecated. They continue to
work in 2.x but emit deprecation notices and will be removed in a future release. Use the typed
`RequestCapabilities` value object instead.

**Before:**

    if ($request->expectsCsv()) {
        // ...
    }

**After:**

    use SineMacula\ApiToolkit\Http\RequestCapabilities;

    $capabilities = RequestCapabilities::fromRequest($request);

    if ($capabilities->expectsCsv()) {
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
alongside the existing chunk counters. Subscribe to the `WritePoolFlushFailed` event to escalate
retained failures to a dead-letter sink, alerting, or metrics.
