# Upgrade Guide

## From 1.x to 2.x

### Raised: laravel-repositories 3.1 is now the floor

`sinemacula/laravel-repositories` moves from `^2.2 || ^3.0` to `^3.1`. The floor is raised rather than widened
because 3.1 changed what composing a query does, and the two behaviours cannot both be documented as one API.

Composing no longer mutates the repository. `withApiCriteria()`, `scopeById()` and `scopeByIds()` return a copy
carrying the composition, and the handle they were called on is left untouched. A composition abandoned by a
caller that never queries through the copy is now garbage rather than state the next unrelated query inherits.

Any caller that composed in one statement and read in another has to keep the handle:

    // Before
    $repository->withApiCriteria();

    return $repository->paginate();

    // After
    return $repository->withApiCriteria()->paginate();

Or, where the composition is conditional:

    $scoped = $repository->withApiCriteria();

    if ($caller->isExternal()) {
        $scoped = $scoped->scopeById($id);
    }

    return $scoped->paginate();

`usingResource()` is configuration rather than composition, so it still mutates the handle it is called on and
reaches only the criteria that handle already carries. Name the resource before composing:

    $repository->usingResource(ArticleResource::class)->withApiCriteria()->paginate();

The three composition methods are marked `@phpstan-pure`, so a discarded result is reported as
`method.resultUnused` from PHPStan level 4 upward. Mark your own repository scope methods the same way to
extend that check to their call sites.

### Raised: Laravel 13.2 is now the floor

`illuminate/*` moves from `^12.0 || ^13.0` to `^13.2`, and `orchestra/testbench` to `^11.0`. Laravel 12 is no
longer supported, and the matrix that tested it has gone with it.

The floor buys the attribute forms of the model declarations. `#[Table]` and `#[Fillable]` arrived in 13.2, so
a model may now carry them in place of the properties:

    #[Table('articles')]
    #[Fillable(['user_id', 'title'])]
    final class Article extends Model
    {
    }

The properties still work, so nothing in your own models has to change. Stay on 2.x's previous release if you
need Laravel 12.

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
implementations ship: extend `Payload` to declare promoted readonly properties plus Laravel `rules()`
and build a validated instance with `Payload::from($request)` (or `from($array)`), or wrap an
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

**Action required:** none for most applications. If you relied on the old whole-table behaviour - a small,
static reference table served entirely from cache with cross-request persistence - opt back into it per
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
referencing them stop working -- so audit your models before upgrading. The new boot-time schema validation
(`api-toolkit.resources.validate_schemas`, enabled by default outside production, or the
`api-toolkit:validate-schemas` command) surfaces schema relations that no longer resolve.

Dynamic relations registered with `Model::resolveRelationUsing()` are now detected and resolved; 1.x did not
support them.

### Removed: BaseResource; ApiResource internals decomposed

`SineMacula\ApiToolkit\Http\Resources\BaseResource` has been removed. `ApiResource` now extends the
toolkit's `ToolkitResource` base (itself extending `Illuminate\Http\Resources\Json\JsonResource`), and
`withFields()`, `withoutFields()`, and `withAll()` live on `ApiResource` itself. Update any type hints
or subclasses referencing `BaseResource` to use `ApiResource` (or `ApiResourceInterface`).

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

### Changed: Filtering and sorting are allowlist-only

API query filtering and sorting are now **allowlist-only**. A resource declares which columns are
filterable and sortable, and which relations may be traversed, in its schema. Any filter, sort, or
relation key the resource has not declared is rejected with a `422` validation error.

In 1.x the posture was the opposite: every column the model exposed was filterable and sortable unless
it was named in `searchable_exclusions` (a blocklist). The exclusion list was the only line of defence,
so a newly added column was queryable the moment it reached the table. A key is now queryable only when
the schema declares it intentionally.

**Declare the query surface** with the fluent markers on the schema DSL:

    use SineMacula\ApiToolkit\Enums\Capability;
    use SineMacula\ApiToolkit\Schema\Field;
    use SineMacula\ApiToolkit\Schema\Relation;

    public static function schema(): array
    {
        return Field::set(
            Field::scalar('id')->filterable(Capability::EXACT)->sortable(),
            Field::scalar('name')->filterable(Capability::EXACT)->sortable(),
            Field::scalar('email')->filterable(Capability::EXACT),
            Relation::to('posts', PostResource::class)->traversable(),
        );
    }

The capability `filterable()` takes decides which operators the column answers; the next section is the
table to choose it from.

A field's filter and sort key is its **column name**, not its presentation alias.
`Field::scalar('email_address', 'email')->filterable(Capability::EXACT)` declares `email_address` as the
filterable column even though the field is presented to clients as `email`.

**Action required.** Audit every API resource and add `filterable()`, `sortable()`, and `traversable()`
to the fields and relations clients are expected to query. Keys that clients currently rely on but the
schema does not declare will start returning `422` until they are declared. A resource with no declared
surface rejects every filter and sort key.

**There is no opt-out.** The `query_posture` config key, its `API_TOOLKIT_QUERY_POSTURE` environment
variable, and the `blocklist` posture they selected are gone. The blocklist silently dropped every
undeclared key, so a filter the client believed was active widened the result set instead of narrowing
it -- a fail-open path in a fail-closed package. Values left behind in a published config file or a
`.env` are ignored. The `getSearchableColumns()` and `isSearchable()` methods that backed the blocklist
have been removed from `SchemaIntrospectionProvider` and `SchemaIntrospector`; a custom implementation
of that contract must drop them.

**Fail-closed only.** An undeclared key on the root resource is always rejected with a named `422`
validation error so clients learn immediately which key is not permitted. There is no opt-out: a
dropped key applies no constraint, so a filter the client believes is active would silently widen the
result set. `API_TOOLKIT_REJECT_UNDECLARED` and the `reject_undeclared` config key are gone, and a
value left behind in a published config file is ignored.

**Sensitive columns are refused at the declaration.** `searchable_exclusions` has been replaced by
`api-toolkit.resources.sensitive_columns`, which names the columns that may never be declared
`filterable()`, `sortable()`, or `searchable()`. Schema validation reports a resource that declares one, so
the defect fails the build rather than being filtered out per request. The shipped list covers the stock
Laravel and Fortify auth column family: `password`, `token`, `remember_token`, `two_factor_secret`,
`two_factor_recovery_codes`, `two_factor_confirmed_at`, and `email_verified_at`. Unlike the exclusion
list it replaces, entries are whole column names -- the `users.password` table-scoped form is no longer
recognised, and a bare `password` applies to every resource. A published config file that does not
declare the key at all falls back to that same list, so a `resources` block predating this release
still refuses a queryable credential column.

### Changed: `filterable()` names the capability the column has

`Field::filterable()` is no longer a bare marker. It takes a `SineMacula\ApiToolkit\Enums\Capability`
naming the access path the column is declared to have, and that capability decides which filter operators
the column answers:

    use SineMacula\ApiToolkit\Enums\Capability;

    Field::scalar('status')->filterable(Capability::ENUM);

A bare declaration said a column may be queried but never how, so every declared column answered every
operator: JSON containment against a scalar, the not-equal and not-null anti-predicates against anything at
all, and the comparison operators against a column no index orders. Each of those is a full table scan the
client writes, on a surface the resource believed it had declared narrowly.

**Choosing the capability.** Pick the row that describes the column. The choice is not cosmetic - it is the
operator set that column answers from now on:

| Column                                                 | Capability             | Answers                                                                   | Refuses                                                     |
|--------------------------------------------------------|------------------------|---------------------------------------------------------------------------|-------------------------------------------------------------|
| A key read by equality: an id, a foreign key, an email | `Capability::EXACT`    | `$eq`, `$in`, `$null`, `$notNull`                                         | `$neq`, `$gt`, `$ge`, `$lt`, `$le`, `$between`, `$contains` |
| A small closed set: a status, a type, a backed enum    | `Capability::ENUM`     | `$eq`, `$in`, `$neq`, `$null`, `$notNull`                                 | `$gt`, `$ge`, `$lt`, `$le`, `$between`, `$contains`         |
| An ordered column: a number, a date, a timestamp       | `Capability::RANGE`    | `$eq`, `$in`, `$gt`, `$ge`, `$lt`, `$le`, `$between`, `$null`, `$notNull` | `$neq`, `$contains`                                         |
| A JSON column behind a containment index               | `Capability::DOCUMENT` | `$contains`                                                               | every other shipped operator                                |
| A column whose access path you will not vouch for      | `Capability::OPAQUE`   | `$eq`                                                                     | every other shipped operator                                |

`$neq` reaches the closed set alone, because the complement of a single value spans nearly the whole index
anywhere else. `$contains` reaches the document alone, whose column is the only one an inverted index backs.
The nullity pair travels with every case carrying a B-tree, since both halves read one contiguous partition
of it. The list fan-out is withheld from `OPAQUE`, where each item would cost a scan of its own.

There is deliberately no text capability. Matching part of a value is the search surface's job, and it is
declared with `searchable()` instead.

An operator no row names is one your own application registered against the `OperatorRegistry`. The package
gates the operators whose SQL it wrote and leaves those to the application that bound them, so a custom
operator keeps working on any declared column. Overriding a shipped token keeps that token's place in the
table.

**Action required.** Every `filterable()` call site needs a capability - an unargued call now raises an
`ArgumentCountError` the first time the schema compiles. Choosing the narrowest honest row is the point of
the change; `Capability::OPAQUE` is the honest answer where the access path is genuinely unknown, and it
still leaves the column filterable by equality. Then audit clients: a request pairing an operator with a
column that no longer answers it is rejected with a `422` naming the operator, the column, and the operators
that column does accept, decided from the declaration before any handler runs and before any SQL is issued.

### Changed: a sortable declaration must be backed by an index

`sortable()` offers to order the whole table by a column on request, and nothing used to check that an index
could hold that order. Schema validation now asks the connection, and a declaration no ordered index leads
with fails validation.

Leading is the whole test. Only the leading column of an index is a key prefix, so a column named second in
a composite index is covered by that index and still cannot be ordered by on its own - checking mere
membership would pass exactly the declaration the database cannot serve. Where the connection names index
kinds, only a kind that holds an order counts, so a full-text or trigram index over a column does not make
it sortable.

Two narrow overrides exist for what reading the catalogue cannot show:

    Field::scalar('name')->sortable()->indexed('users_lower_name_index');
    Field::scalar('reference')->sortable()->unindexed('the table is bounded at a few hundred rows');

`indexed()` names the index behind the column - an index over an expression, say, or one whose predicate the
catalogue reports apart from its columns. The name is looked up on the connection, so naming an index the
table does not carry is itself a defect; what that index covers is not read back, so the override vouches
for the column rather than proving it. `unindexed()` records a deliberate exemption and requires a reason,
so an exemption is never silent; it is the artefact a reviewer weighs a sort that reads the table against.

A connection that cannot be inspected at all reports nothing rather than reporting nothing found, so booting
without a database, or before migrations have run, skips the check instead of failing it. The catalogue is
read during validation and never while a sort is served.

**Action required.** Run `php artisan api-toolkit:validate-schemas` after upgrading. For each reported
column, add the missing index in your own migration, drop the `sortable()` marker, or - where the sort is
genuinely affordable without one - record why with `unindexed()`.

### Changed: The query layer no longer fails open

Four query-layer behaviours that quietly widened a result set now fail the request instead.

**`?order=random` is opt-in.** The keyword used to bypass the sortable-column enforcement entirely and
apply the most expensive sort available on any resource. It is now disabled by default; while it is
disabled the keyword is gated like any other sort key and rejected as undeclared. Re-enable it with:

    API_TOOLKIT_ALLOW_RANDOM_ORDER=true

**A `filters` document must be a JSON object.** A document that cannot be decoded -- malformed, or
nested beyond the decoder's 512-level depth limit -- or that decodes to a scalar or a populated list is
now rejected with a `422` naming the `filters` parameter. Previously it was reduced to an empty filter
set and the request answered with the unfiltered table.

**`$contains` no longer discards a clause the grammar rejects.** A JSON-containment predicate the
active database grammar cannot express now propagates the grammar's exception rather than being logged
and dropped, which returned a wider result set than the client asked for.

**Filterable and sortable declarations are validated at boot.** A `filterable()` or `sortable()` marker
on a computed field, or on a field whose accessor reads a different path from the column it declares, is
reported by schema validation, as is one naming a column the table does not carry at all. Such a declaration
used to compile cleanly and fail at request time with a database error naming a column that does not exist.
The first two are read from the schema; the last is read from the table's own column listing, so it is
reported only where the connection can be inspected - a boot with no database behind it, or one whose
migrations have not run, proves nothing and is left alone. Run `php artisan api-toolkit:validate-schemas`
after upgrading and either drop the marker or move it to the backing column.

### Added: structural caps bound the cost of a single query

A family of caps now bounds the structural cost of one request. Every part of an amplified query is
individually cheap and individually declared - a filter nested a few levels, a value list a few hundred long,
a handful of sort keys, a page a long way into a result set - and it is the multiplication that is expensive.
Each of those dimensions carries a cap, and a request that exceeds one is rejected rather than served.

None of these bounds existed in 1.x, which makes this the change most likely to refuse traffic that used to
be answered: a deeply nested filter, a large `$in` list, a six-key sort, or a deep page all worked before and
return a `422` now. The caps live under `api-toolkit.query_cost`, and these are the shipped defaults:

    max_bytes         8192   the byte length of the `filters` document as received
    max_parse_depth   16     the object levels that document nests
    max_depth         3      the levels a filter descends, counting a logical group or a relation as one
    max_nodes         100    the keys a filter visits in total
    max_in_items      500    the items a single operator value list carries, such as the one `$in` reads
    max_order_keys    3      the columns one request may order by
    max_aggregates    5      the relation counts, sums, and averages one request asks for, combined
    max_offset        500000 the rows a paginated read may scan past to reach its page

Each is settable from the environment as `API_TOOLKIT_QUERY_` followed by the cap name, so
`API_TOOLKIT_QUERY_MAX_IN_ITEMS` sets `max_in_items`. Setting a cap to `0` (or `null`) disables that
dimension and leaves it unbounded. A config file published before this release declares none of these keys,
and every cap still applies at its shipped default there, so republishing the config is not what turns them
on.

**The parse tier rejects before the filter tree is walked.** `max_bytes` and `max_parse_depth` are enforced
while the query string is validated: the first against the byte length of the `filters` value as it arrives,
the second against the object levels it nests, measured once the value is known to decode as JSON so that a
malformed document keeps its own validation failure rather than being reported as a cost. A document too
large or too deeply nested to be worth interpreting is refused before it is interpreted at all.

The remaining caps are enforced as the criteria are applied. `max_depth` and `max_nodes` are measured during
the walk itself, so an oversized filter aborts part way through rather than after the whole tree has been
built. `max_in_items` is measured against the items an operator will read rather than the shape of the value,
so a list spelled as a delimited string is bounded exactly as one spelled as an array. `max_order_keys`
counts the sort columns, `max_aggregates` the relation counts, sums, and averages together, since each adds
its own correlated subquery, and `max_offset` bounds the rows scanned past to reach the requested page, but
only where a page number was asked for. All of it happens while the query is being composed: a rejected
request issues no SQL.

`max_offset` counts rows, not pages. The cost of a page is the rows skipped to reach it, which is the page
number multiplied by the page size, so a cap on the page number alone moved whenever an operator tuned the
page size: at a page size of 100 the old bound of 10,000 pages allowed a read to skip almost a million rows.
The shipped bound of 500,000 rows leaves the furthest page at 10,000 for the default page size of 50, which
is the reach the old cap allowed there, and 5,000 at the `max_limit` ceiling of 100.

Two things follow. A cursor-paginated read is no longer bounded by this cap at all, because a cursor seeks
to its position rather than counting rows to it, so a `page` carried alongside a cursor costs nothing to
honour. And the refusal still names pages: a caller who asked for `page=6000` is told the furthest page
available, not a row count they never supplied.

A request over a cap is answered with the standard error envelope, whose `meta` names what was exceeded:

    {
      "error": {
        "status": 422,
        "code": 10201,
        "meta": {
          "parameter": "filters",
          "pointer": "/posts/title/$in",
          "reason": "max_in_items",
          "limit": 500,
          "actual": 501
        }
      }
    }

`parameter` names the query parameter at fault - `filters`, `order`, `page`, or `aggregates`, which stands
for `counts`, `sums`, and `averages` together. `pointer` is a JSON pointer into the filter document, and is
empty where a cap bounds a parameter as a whole rather than a position within it. `reason` names the cap
exactly as the config key spells it, and `limit` against `actual` is the bound in force against the value
supplied, so the client can correct the query without server-side diagnosis. The title and detail carried
alongside are the ones the error catalogue lists for the code.

**The defaults are a starting point, not a measurement.** They are calibrated against the package's own
fixture schemas rather than measured against production traffic, which is exactly why every one of them is
configuration. A `max_depth` of 3 and a `max_nodes` of 100 hold a filter that reads as a filter rather than
as a program; whether they hold yours is a question about your own API, and the answer belongs in your config
file rather than in a patch to this package.

**Action required.** Audit what your clients actually send before upgrading, because each cap refuses a
request that used to be answered. An access log carrying query strings is enough to find the shapes at risk:
filter documents over 8192 bytes or nested past sixteen object levels, filters descending more than three
levels or carrying more than a hundred keys, `$in` lists over five hundred items, requests ordering by more
than three columns, requests asking for more than five relation aggregates across `counts`, `sums`, and
`averages`, and anything paging beyond page 10000, which is usually a job walking a whole table by page
number. Where the shape is legitimate, raise that cap to the largest query you intend to serve rather than
disabling the dimension; where it is not, the `422` names the cap and the client can be corrected against it.
The generated Query Surface Reference carries the resolved value of every cap and the shape of the
rejection, so clients can be told the bounds without being handed the config file.

### Changed: `?limit` above the ceiling is rejected rather than clamped

A client-supplied `?limit` above `api-toolkit.parser.max_limit` (default 100) used to be reduced to the
ceiling and the request answered anyway. It is now rejected with the same `422` the query-cost caps are
enforced with, carrying the parameter, the ceiling, and the size asked for:

    "meta": {
      "parameter": "limit",
      "pointer": "",
      "reason": "max_limit",
      "limit": 100,
      "actual": 500
    }

The clamp was the last fail-quiet path in the query layer. A client that asked for 500 rows and was handed
100 cannot tell that from a page that ran out, so it stops paging and drops the tail it never learned was
there. The `max_offset` cap that arrives with this release rejects a page beyond its bound for the same
reason, and the two behave the same way.

The ceiling is otherwise unchanged: the same config key, the same `API_PARSER_MAX_LIMIT` variable, the same
default, and setting it to `0` (or `null`) still disables it and leaves the page size unbounded.

**Action required.** Audit clients that ask for a page larger than the ceiling - they now receive a `422`
where they previously received a shortened page. Either raise the ceiling to the largest page you intend to
serve, or have the client ask within it.

### Removed: the `$like` operator, replaced by `?search=`

The `$like` filter operator has been deleted from the shipped operator set, and
`SineMacula\ApiToolkit\Repositories\Criteria\Operators\LikeOperator` no longer exists. A request using the
token is now rejected as an unknown operator, and the token no longer appears in the exported OpenAPI
document.

`$like` was the only shipped operator that could not be served from an index. It compiled to
`column LIKE '%term%'`, which reads every row of the table on every supported engine, and it could be
applied to any filterable column and carried into a relation subquery, where the cost is paid once per
candidate row. Free-text matching now has a parameter of its own that is declared per field and served by
a driver that refuses a shape it cannot answer from an index:

    GET /users?search=smith

Declare which fields the term is matched against, and how, in the resource schema:

    use SineMacula\ApiToolkit\Enums\SearchStrategy;
    use SineMacula\ApiToolkit\Schema\Field;

    public static function schema(): array
    {
        return Field::set(
            Field::scalar('name')->searchable(SearchStrategy::SUBSTRING),
            Field::scalar('email')->searchable(SearchStrategy::SUBSTRING),
        );
    }

The search applies to the columns of the requested resource only and never traverses a relation. Terms are
bounded by the new `api-toolkit.search` config block, and a term outside those bounds is rejected with a
`422`. The shortest word accepted is three characters, and configuration may raise that floor but never
lower it: below three, MySQL matches nothing once the word is shorter than the index token size and
PostgreSQL answers correctly but by reading the whole table, and neither failure is visible in the
response. The floor applies to each word, not to the term as a whole, because a word beneath it is dropped
from a full-text phrase while a pattern comparison keeps it, and the two engines would then answer the same
request with different rows.

**One strategy per surface on MySQL.** A full-text match OR-ed with any other predicate loses the full-text
access path and reads the whole table, so the MySQL driver refuses a surface declaring an anywhere-match
beside another strategy rather than serving it as a scan. Declare every searchable column of a resource
with the same strategy there, or keep the anywhere-match to its own resource. PostgreSQL has no such
restriction: the planner combines the index scans behind a disjunction.

**The indexes are yours to create.** A driver ships for MySQL, PostgreSQL, and SQLite, registered against
the names those connections report, and each proves a declaration against the live schema rather than
emitting a predicate that scans. MariaDB reports its own name and has no n-gram parser, so no driver is
registered for it and a search there fails until you register one yourself. The migration creating the
index belongs to your application:

    -- MySQL: an anywhere-match, over exactly the columns declared for it
    ALTER TABLE users ADD FULLTEXT INDEX users_search_ngram (name, email) WITH PARSER ngram;

    -- PostgreSQL: a prefix match and an anywhere-match, per declared column
    CREATE EXTENSION IF NOT EXISTS pg_trgm;
    CREATE INDEX users_name_trgm ON users USING gin (name gin_trgm_ops);
    CREATE INDEX users_email_trgm ON users USING gin (email gin_trgm_ops);

MySQL resolves a match only against a full-text index whose column list is exactly the matched one, which
is why the index covers the declared set rather than one column each. An exact match needs only an ordinary
index leading with the column. SQLite carries neither index kind, so it serves every strategy and proves
none of them.

`api-toolkit.search.unverified_connections` ships empty, so nothing is waived until you name a connection.
**If you develop against SQLite and declare a searchable field, you must name that connection yourself**,
or the application will not boot. Schema validation is enabled by default outside production, and it throws
rather than warns, so an unnamed connection stops every route and every Artisan command, `php artisan
migrate` on a fresh checkout included. It does not wait for a search request, and it fires before anything
touches the database, so a missing SQLite file will not spare you:

    // config/api-toolkit.php
    'search' => [
        'unverified_connections' => ['sqlite'],
    ],

The list was previously shipped with `sqlite` already in it. It matches on connection name, and a stock
application names its connections after their engines, so the shipped entry waived the proof for anyone
running SQLite in production without their ever choosing to. Naming the connection yourself is the same
one-line change, made deliberately.

That list is read by connection name, as `config/database.php` keys it, and not by the engine behind the
connection, so an application naming its connections for itself waives one of them without waiving every
connection on the same engine. It does not make the setting a safe one: a stock application names each
connection after its engine, so writing `mysql` there still waives the connection named `mysql`, which is
usually the one serving production. Listing a connection that serves traffic reinstates the full-table scan
the declaration exists to prevent, and this is the one control with nothing behind it to catch that.

Run `php artisan api-toolkit:validate-schemas` in your build, which is the cheapest place to find a missing
index. Because schema validation is disabled in production by default, the same proof is also taken on the
first search each worker process serves and memoised from there, so a missing index refuses the request
rather than reading the table behind it: on PostgreSQL a missing trigram index is not an error, and the
search would otherwise return the right rows out of a sequential scan indefinitely.

**Action required.** Replace client calls using `$like` with `?search=`, having declared the fields it may
match. Where the old behaviour is genuinely wanted -- an unindexed partial match on an arbitrary filterable
column -- register the operator yourself, in a service provider's `boot()`:

    use Illuminate\Database\Eloquent\Builder;
    use SineMacula\ApiToolkit\Contracts\FilterOperator;
    use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext;
    use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;

    final class LikeOperator implements FilterOperator
    {
        public function apply(Builder $query, string $column, mixed $value, FilterContext $context): void
        {
            $term = is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';

            if ($context->isOr()) {
                $query->orWhere($column, 'like', "%{$term}%");
            } else {
                $query->where($column, 'like', "%{$term}%");
            }
        }
    }

    app(OperatorRegistry::class)->register('$like', new LikeOperator);

Registering it restores the full-table scan the removal exists to prevent, including inside relation
subqueries. Prefer `?search=` unless you have measured the alternative.

### Changed: the exported OpenAPI document carries the query surface

The exporter built the whole query-parameter grammar into `components.parameters` and then referenced none
of it, so a client generated from the document sent no query parameter on any endpoint. Every documented
operation now references the parameters its action honours: the sparse fieldset and the relation aggregates
wherever a resource is serialised, the selection and paging set on an index, and soft-delete visibility on
the read actions of a model that soft deletes. A model with no deleted-at column is never offered `trashed`,
the server having nothing to widen.

Two of the emitted components changed with it. The filter parameter was emitted as `filter`, a bracketed
deep object the parser rejects outright, so a client built from it sent a filter the API answered with the
unfiltered table; it is now `filters`, the single URL-encoded JSON document the parser accepts. Soft-delete
visibility was documented nowhere despite being a shipped capability, and is now emitted as `trashed`.

Each resource schema property that answers a query also carries an `x-query-surface` extension naming the
key to send it under, the capability it is filterable with together with the operators that capability
answers, whether an index backs its sort, and the strategy a free-text search matches it by. The relations a
filter may descend through are named on the schema itself as `x-traversable-relations`. Both extensions are
read from the compiled schema the request-time gates read, so the document cannot offer a column the request
would reject. The operators are narrowed further against the bound operator registry: a token removed from
the registry is refused as an undeclared key rather than dispatched, so it is documented nowhere, and a
column whose every operator has been removed carries no `filter` member at all.

A relation naming a resource that no registered model maps to no longer emits a reference to a component the
document never defines. Such a property now carries its resolved cardinality over a bare object marked
`x-undocumented`, so the reference walk of a generated client never dangles.

`api-toolkit:docs:generate` writes the Query Surface Reference once per configured audience, into
`<docs_path>/audiences/<audience>/60-query-surface-reference.md`, and the manual assembler reads a shared
section file only when the audience being assembled has no file of the same name. What a resource may be
filtered, ordered, and searched by is the same disclosure its schema is, so it now reaches only the
documents that already carry that schema.

**Action required.** Regenerate any client built from the exported document, and rename the `filter`
parameter to `filters` in anything that read the old component names. A client that builds the query string
itself is unaffected. Re-run `api-toolkit:docs:generate` and delete any `60-query-surface-reference.md` left
at the root of the docs directory by an earlier run; a shared file of that name is shadowed by the
per-audience one where both exist, but is read by any audience the generator has not written a file for.

### Removed: Request macros in favour of the parsed query

The request macros the toolkit used to register - `includeTrashed()` and `onlyTrashed()` - have been removed.
Soft-delete visibility is now parsed from the request alongside every other query parameter and read as a
typed `TrashedState`. The export-detection macros (`expectsExport()`, `expectsCsv()`, and `expectsXml()`)
have been removed outright: content-negotiated exports now live in the `sinemacula/laravel-resource-exporter`
package, not in the toolkit.

**Before:**

    if ($request->includeTrashed()) {
        // ...
    }

**After:**

    use SineMacula\ApiToolkit\Enums\TrashedState;
    use SineMacula\ApiToolkit\Facades\ApiQuery;

    if (ApiQuery::getTrashed() === TrashedState::WITH) {
        // ...
    }

Reading the state directly is rarely necessary. A repository read through `withApiCriteria()` applies it for
you, and only where the model uses `SoftDeletes` and the resource has opted in by overriding
`allowsTrashed()`; a resource that has not opted in keeps its soft-deleted records hidden whatever the
request asks.

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

Under 2.x, the lifecycle flush, which resets the toolkit's in-process state between requests and jobs, ships
**enabled by default** on runtimes that are actively serving requests under Octane or running as a queue
worker. php-fpm is unaffected because each request already starts with a clean process; the runtime
detector gates engagement and does not fire under php-fpm even when Octane is installed.

**Runtime detection.** Serving is discriminated from mere installation:

- Octane: the `$_SERVER['LARAVEL_OCTANE']` superglobal is set by a booted Octane worker, not by
  package installation, so the flush only engages when the worker is actually serving.
- Queue worker: `JobProcessed` and `JobFailed` fire inside a real worker loop. A job dispatched over
  the `sync` driver fires the same events within the originating HTTP request; the toolkit checks the
  connection driver and treats `sync` as a non-worker boundary, leaving php-fpm unaffected.

**What the boundary resets (the cache-site inventory).** The toolkit keeps in-process state that
accumulates across requests under a long-lived runtime:

- The process-static memos: the schema compile cache (`SchemaCompiler`), the serialization, eager-load,
  field, and field-to-column memos, the compiled search plans, and the per-process index proofs.
- The `SchemaIntrospector` singleton's in-memory arrays (column listings, column definitions, and index
  catalogues).
- The memoised metadata generation, so the worker re-reads it and picks up an invalidation made elsewhere.
- The bound query parser's state.

The single surface that resets all of it is `CacheManager::flush()`, invoked automatically by the Octane and
queue lifecycle listeners at every request/job boundary. Octane fires it after every operation it serves:
requests, tasks, and ticks.

**What the boundary leaves alone.** Toolkit metadata - schema columns, column definitions, index catalogues,
relation lookups, resources, and repository model casts - is read and written through `Cache::memo()`, which
memoises reads for the current request or job on top of the application's cache store. The entries live in
that store, which is usually shared by every worker (Redis, Memcached, the database), so they are not
in-process state and a boundary does not touch them. The framework already discards the memoised repository
at each boundary (Octane forgets scoped instances after every operation, and the queue worker does so before
each job), so the next request reads the store afresh. Nothing on the store, toolkit or not, is cleared at a
boundary; stored metadata is retired only by replacing the generation (see the metadata invalidation section
below). Any new toolkit metadata key must be read and written through the `MetadataCacheWriter` chokepoint so
it is namespaced by that generation.

**No re-warm cost.** Because the stored metadata survives the boundary, every worker serves what any worker
has already read, and a request after a boundary does not re-query the schema. A column listing or set of
column definitions that reads empty, as one does before its table exists, is never stored, so a worker reads
the table's columns afresh after its next boundary once the table exists.

**Action required.** No action is needed for most applications. The flush is additive on Octane and
queue-worker runtimes; php-fpm behaviour is unchanged.

**Opt out** (in-process memos then grow for the life of the worker, and the worker never re-reads the
metadata generation, so an invalidation made elsewhere is not picked up until it restarts):

    API_TOOLKIT_LIFECYCLE_OCTANE=false
    API_TOOLKIT_LIFECYCLE_QUEUE=false

Or set the equivalent config keys to `false` in a published `config/api-toolkit.php`:

    'lifecycle' => [
        'octane' => false,
        'queue'  => false,
    ],

When a serving runtime is detected but the flush is opted out, the toolkit logs a one-line
`Log::info` diagnostic so the disabled state is not silent.

### Added: metadata is invalidated across processes after migrations

Cached schema metadata lives in the shared cache store, mostly forever, and the lifecycle flush leaves the
store alone. Metadata written before a deploy that changed the schema would therefore be served warm to every
process after it.

Under 2.x every metadata key is stored under a generation held in the same cache store. Replacing the
generation retires every metadata entry in every process sharing the store at once:

- The generation is replaced automatically when a migration run (`migrate`, `migrate:rollback`,
  `migrate:fresh`, and the like) finishes, which covers schema changes at migrate time. A run with nothing to
  migrate, or a `--pretend` run, keeps the metadata warm. If the cache store cannot be written, the hook logs
  a warning rather than failing the migration.
- `php artisan api-toolkit:invalidate-metadata` replaces it on demand, and exits with a failure if the store
  rejects the new generation.
- `CacheManager::invalidateMetadata()` is the programmatic entry point behind both.

The Octane and queue boundary flushes reset in-process state only. They re-read the generation rather than
replacing it, so a long-lived worker picks up an invalidation made elsewhere at its next boundary.

**Multi-tenant applications.** Column listings, column definitions, and index catalogues are keyed by the
schema the connection actually reads: its name, with any read or write suffix, its effective database, its
table prefix, its Postgres `search_path` (or `schema`), and its database user, since a search path can name
the schema after whoever connects. A tenancy switcher that repoints one connection name at another tenant's
database, prefix, or search path - by configuration, or on the resolved connection - therefore reads and
caches each tenant's schema apart, even within a single job. The identity also includes the server the
connection is configured for: its driver, its configured host list (in any order), port, Unix socket, and the
Postgres `connect_via_database` and `connect_via_port` or SQL Server ODBC data source name. Every host in one
configured list must serve the same schema. The identity is resolved from configuration and the connection's
own state, never by a query, so a change is seen only once it is on the connection object: a switcher must
purge the connection (`DB::purge()`) or configure a fresh one after changing its configuration, since
`DB::reconnect()` reuses the old configuration. A search path changed at runtime by a statement such as
`SET search_path`, rather than through the connection's `search_path` or `schema` configuration, is not seen,
so a switcher that works that way needs a per-tenant cache prefix, which separates the shared cache but not
the in-process memos of one job. A read alias such as `tenant::read` is described by its write-side settings,
including the write host, as the framework builds it, so a switcher must repoint the write side along with the
read side. With the list form of read and write settings (`'write' => [[...], [...]]`), the framework picks
one entry per connection, so workers may cache under a few keys that are each correct but fill separately;
prefer one entry with a host list. Casts, relation lookups, and the model-to-resource map are derived from
code and read the same for every tenant, so they stay keyed by class and are shared. Where each tenant has its
own cache prefix, each tenant also has its own generation, so run the invalidation once per tenant.

The migration hook alone does not keep metadata correct across a deploy. Migrations run before the new
release takes traffic, so workers still on the old code can refill the new generation with their casts,
resource mappings, and relation lookups, cached forever.

**Action required.** Add `php artisan api-toolkit:invalidate-metadata` to every deploy, after the new release
is live. Code that read toolkit metadata keys straight from the
store must go through `MetadataCacheWriter`, since the stored key now carries the generation. Metadata cached by
1.x is not read after the upgrade and is rebuilt on first use.

**Opt out of the migration hook:**

    API_TOOLKIT_LIFECYCLE_MIGRATIONS=false

Or in a published `config/api-toolkit.php`:

    'lifecycle' => [
        'migrations' => false,
    ],

Entries written under a retired generation are no longer read but stay in the store until it evicts or clears
them, so each invalidation leaves at most one copy of the metadata behind.

### Removed: ProvidesExclusiveLock listener trait

The `ProvidesExclusiveLock` listener trait has been removed. It had no internal consumers - the
shipped listeners are idempotent or boundary-driven and never mixed it in. Any downstream listener
that used it must provide its own locking, for example via the toolkit's `Lockable` concern.
