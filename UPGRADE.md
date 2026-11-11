# Upgrade Guide

## From 1.x to 2.x

### Raised: laravel-repositories 3.2.1 is now the floor

`sinemacula/laravel-repositories` now requires `^3.2.1`. From 3.1, composing a query no longer mutates the
repository: `withApiCriteria()`, `scopeById()` and `scopeByIds()` return a copy carrying the composition and leave
the handle they were called on untouched.

Any caller that composed in one statement and read in another has to keep the handle:

```php
// Before
$repository->withApiCriteria();

return $repository->paginate();

// After
return $repository->withApiCriteria()->paginate();
```

Or, where the composition is conditional:

```php
$scoped = $repository->withApiCriteria();

if ($caller->isExternal()) {
    $scoped = $scoped->scopeById($id);
}

return $scoped->paginate();
```

`usingResource()` is configuration, not composition, so it still mutates the handle and only reaches criteria that
handle already carries. Name the resource before composing:

```php
$repository->usingResource(ArticleResource::class)->withApiCriteria()->paginate();
```

The three composition methods are marked `@phpstan-pure`, so PHPStan (level 4 and up) reports a discarded result as
`method.resultUnused`. Mark your own repository scope methods the same way to extend the check to their call sites.

### Raised: Laravel 13.2 is now the floor

`illuminate/*` now requires `^13.2`. Laravel 12 is no longer supported; stay on 1.x if you need it.

13.2 adds the `#[Table]` and `#[Fillable]` model attributes, which a model may use in place of the properties:

```php
#[Table('articles')]
#[Fillable(['user_id', 'title'])]
final class Article extends Model
{
}
```

The properties still work, so your models do not have to change.

### Moved: schema introspection and validation into the Schema namespace

Schema introspection and validation have moved out of `Services\` (which now holds only the action layer) into
`Schema\Introspection\` and `Schema\Validation\`. Update imports:

```text
SineMacula\ApiToolkit\Services\SchemaIntrospector
-> SineMacula\ApiToolkit\Schema\Introspection\SchemaIntrospector

SineMacula\ApiToolkit\Services\SchemaValidator
-> SineMacula\ApiToolkit\Schema\Validation\SchemaValidator

SineMacula\ApiToolkit\Services\Introspection\ColumnDefinition
-> SineMacula\ApiToolkit\Schema\Introspection\ColumnDefinition

SineMacula\ApiToolkit\Services\Validation\SchemaValidationError
-> SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError

SineMacula\ApiToolkit\Services\Validation\Rules\*
-> SineMacula\ApiToolkit\Schema\Validation\Rules\*
```

### Moved: the resource schema DSL and the toolkit traits

The schema helpers have moved out of `Http\Resources\Schema\`, and every `Traits\` namespace is now `Concerns\`.
Update imports:

```text
SineMacula\ApiToolkit\Http\Resources\Schema\{Field, Count, Relation, BaseDefinition}
-> SineMacula\ApiToolkit\Schema\{Field, Count, Relation, BaseDefinition}

SineMacula\ApiToolkit\Traits\Lockable
-> SineMacula\ApiToolkit\Concerns\Lockable

SineMacula\ApiToolkit\Traits\OrdersFields
-> SineMacula\ApiToolkit\Concerns\OrdersFields

SineMacula\ApiToolkit\Enums\Traits\ProvidesCode
-> SineMacula\ApiToolkit\Enums\Concerns\ProvidesCode

SineMacula\ApiToolkit\Http\Middleware\Traits\ThrottleRequestsTrait
-> SineMacula\ApiToolkit\Http\Middleware\Concerns\ThrottleRequestsTrait

SineMacula\ApiToolkit\Repositories\Traits\ResolvesResource
-> SineMacula\ApiToolkit\Repositories\Concerns\ResolvesResource
```

`Sum` and `Average` are new alongside `Count` in `SineMacula\ApiToolkit\Schema`.

### Composer dependency changes

The logging drivers and server-sent event streaming have been extracted into their own packages. If you use any of
them, require the package directly:

```bash
composer require sinemacula/laravel-log-cloudwatch
composer require sinemacula/laravel-log-database
composer require sinemacula/laravel-sse
```

Content-negotiated exports live in `sinemacula/laravel-resource-exporter`, which is no longer a dependency; require
it if you export resources. `illuminate/notifications` is now optional and only needed for the notification audit
log.

The toolkit now depends on `sinemacula/http-primitives-php`, which replaces the internal `HttpStatus` enum (see the
next section).

### Removed: HttpStatus enum

`SineMacula\ApiToolkit\Enums\HttpStatus` has been removed in favour of `SineMacula\Http\Enums\HttpStatus` from
`sinemacula/http-primitives-php`.

**Before (1.x):**

```php
use SineMacula\ApiToolkit\Enums\HttpStatus;

$code = HttpStatus::NOT_FOUND->getCode();
```

**After (2.x):**

```php
use SineMacula\Http\Enums\HttpStatus;

$code = HttpStatus::NOT_FOUND->getCode();
```

Custom exceptions extending `ApiException` must type their `HTTP_STATUS` constant with the new enum:

```php
use SineMacula\Http\Enums\HttpStatus;

class TeapotException extends ApiException
{
    public const HttpStatus HTTP_STATUS = HttpStatus::IM_A_TEAPOT;
}
```

The shared enum has only standard status codes, so `HttpStatus::TOKEN_MISMATCH` (`419`) is gone.
`TokenMismatchException` overrides `getStatusCode()` to return `419`; do the same in any custom exception that needs
a non-standard status.

### Removed: other 1.x classes and config

These have been removed with no replacement in the toolkit:

- `Contracts\PureEnumInterface` and `Enums\Traits\PureEnumHelper`
- `Exceptions\FileUploadException`, `InvalidImageException`, `InvalidNotificationException` and
  `SmsSendFailedException`
- `Http\FormRequest`
- `Http\Controllers\RespondsWithExport` (exports are in `sinemacula/laravel-resource-exporter`) and
  `Http\Controllers\RespondsWithStream` (streaming is in `sinemacula/laravel-sse`)
- `Logging\CloudWatchLogger`, `Logging\DatabaseHandler`, `Logging\DatabaseLogger` and `Models\LogMessage` (now in the
  log driver packages)
- `Repositories\Traits\InteractsWithModelSchema`
- `Services\Contracts\ServiceInterface`, `Services\InjectableService` and `Services\Traits\HasServiceLifecycle` (see
  the Services sections below)

The `exports`, `logging` and `repositories.cast_map` config keys are no longer read; remove them from a published
`config/api-toolkit.php`.

### Services: transactions and locking move to class properties

The `Service` base class has been rebuilt around a typed input, an explicit actor, a fixed transaction-aware
lifecycle and an immutable `ServiceResult`. Removed from the base class:

- `useTransaction()`, `dontUseTransaction()`, `useLock()`, `dontUseLock()`
- the `$useTransaction` and `$useLock` properties
- `getStatus(): ?bool` (the outcome is now the return value of `run()`)
- the `success()` and `failed()` hooks (replaced by `afterCommit()` and `onFailure()`)

Transactions and locking are declared as class properties:

```php
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
```

The defaults match 1.x: a service runs in a transaction (`$transactional = true`) and does not lock
(`$lockable = false`).

A lockable service must return a non-empty lock identity from `lockId()` (which replaces 1.x's `getLockId()`);
otherwise the runner throws `LockOperationException`. The cache-lock key is `sha1(static::class . '|' . lockId())`;
see the `LockKeyProvider` section below.

### Services: typed input and an explicit actor

A service is constructed with a typed input, and the actor is supplied explicitly; the action layer never reads
`Auth` or the current `Request` itself.

**Input.** The constructor takes a `ServiceInput` (a single `toArray(): array`). Two implementations ship:

- `Payload` - declare promoted readonly properties plus Laravel `rules()`, and build a validated instance with
  `Payload::from($request)` or `from($array)`. Parameters marked `#[\SensitiveParameter]` are left out of the
  `inputSummary` the lifecycle events carry. Body keys map to camelCase properties (`client_name` hydrates
  `$clientName`, and `toArray()` returns `client_name`); a property named exactly after the key takes precedence.
- `ArrayInput` - wraps an already-validated array.

Declare the input and output types with `@extends Service<TInput, TOutput>`; `$this->input` is then typed as `TInput`
in every hook, so drop any `@var` narrowing. The property is readonly: a hook cannot reassign `$this->input`, and a
subclass constructor must pass the input to `parent::__construct($input)` rather than redeclaring `$input` as a
promoted property or assigning it.

```php
use SineMacula\ApiToolkit\Services\Input\ArrayInput;

$result = (new MyService(new ArrayInput(['title' => 'Hello'])))->run();
```

**Actor.** Attach the actor with `by()` and read it with `actor()`; it defaults to an `AnonymousActor`. Three actors
ship: `EloquentActor::for($user)` wraps an authenticatable model and is queue-serialisable, `SystemActor` is a trusted
internal caller that skips `authorize()`, and `AnonymousActor` is an unauthenticated caller.

```php
use SineMacula\ApiToolkit\Services\Actors\EloquentActor;

$result = (new MyService($input))->by(EloquentActor::for($user))->run();
```

**Resolution, context and queueing.** `Service::make($input)` resolves the service through the container, so it may
declare constructor dependencies. `withContext()` attaches a prebuilt `ServiceContext` (actor, correlation id, source
and metadata). `dispatch()` queues the service via `ServiceJob`, which re-runs it on the worker with the source set to
`QUEUE`. A failed queued run fails the job with the captured exception, so the worker retries it per `--tries` and
`--backoff` and records it in the failed job store. Each failed attempt runs `onFailure()` and fires `ServiceFailed`
again. On the `sync` connection the failure is thrown to the dispatching code.

### Services: a service reads its run context

**Context.** During a run, `$this->context()` returns the `ServiceContext` in use: the one given through
`withContext()`, the `INTERNAL` context `run()` builds when none was given, or the `QUEUE` context rebuilt on the
worker. Every hook, `concerns()` and `lockId()` see the same instance; calling it outside a run throws a
`LogicException`. A closure that runs after the service returns must capture `$this->context()` first. Read ambient
metadata from the context instead of injecting the `Request` into a service.

**HTTP edge.** `ServiceContext::fromRequest($request, $actor, $metadata = [])` builds an `HTTP` context carrying the
client IP and user agent under `ServiceContext::METADATA_IP` and `ServiceContext::METADATA_USER_AGENT`, with
`$metadata` merged over them. The correlation id comes from the header named by the new
`api-toolkit.services.correlation_header` config (default `X-Request-Id`) when it holds a usable value, and is
generated otherwise. Replace hand-built HTTP contexts with it:

```php
// Before
->withContext(ServiceContext::for($actor, ServiceSource::HTTP, [
    'ip'         => $request->ip(),
    'user_agent' => $request->userAgent(),
]))

// After
->withContext(ServiceContext::fromRequest($request, $actor))
```

A published config file picks up the `services` section from the package defaults; copy it in only to change the
header.

**Actor consistency.** During a run `actor()` returns the run context's actor, and the `ServiceCompleted` and
`ServiceFailed` events report that actor, not the one given to `by()`. Calling `by()` after `withContext()` changes
`actor()` outside a run but not the attached context.

### Services: the fixed lifecycle and its hooks

`ServiceRunner` runs the lifecycle in a fixed order:

```text
authorize -> validate -> [lock] -> [transaction] -> concerns -> prepare -> handle
-> commit -> [release lock] -> afterCommit
```

On failure the transaction rolls back, the lock is released, and `onFailure()` runs. The runner never throws for
business failures; every outcome is captured on the returned `ServiceResult`.

Overridable hooks (all `protected`, all no-ops by default except `handle()`):

- `authorize(): void` - runs before the lock and transaction; throw an authorization exception to deny. Skipped for a
  `SystemActor`.
- `validate(): void` - runs before the lock and transaction; throw a validation exception for bad input.
- `prepare(): void` - runs inside the transaction, before `handle()` (1.x `prepare()` was `public`).
- `handle(): mixed` - abstract; runs inside the transaction and **returns** the output. Signal failure only by
  throwing (1.x `handle()` returned `bool`).
- `afterCommit(mixed $output): void` - replaces 1.x `success()`; runs after commit. An exception thrown here is
  captured as a side-effect error on the result and reported; the committed outcome stands.
- `onFailure(\Throwable $exception): void` - replaces 1.x `failed()`; runs after rollback and lock release. An
  exception thrown here is caught and reported.

### Services: cross-cutting concerns implement ServiceConcern

`concerns()` returns an ordered list of `ServiceConcern` class-strings. Each is resolved from the container and wraps
`prepare()` + `handle()` inside the transaction, in declaration order (the first entry is outermost). The contract is
one method:

```php
public function handle(ServiceContext $context, \Closure $next): mixed;
```

Call `$next()` to continue, and return or transform its result:

```php
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
```

Transactions and locking are not concerns; they are driven by `$transactional` and `$lockable`. Use `concerns()` only
for your own wrappers.

### Changed: Service::run() returns a ServiceResult value object

`Service::run()` returns an immutable `ServiceResult` instead of `bool`, and `getStatus(): ?bool` is gone. The result
carries:

- `status` - a `ServiceStatus` enum (`SUCCEEDED` or `FAILED`); check it with `succeeded()` or `failed()`.
- `output` - the value `handle()` returned; read it via the `output` property, `output()`, or `outputOr($default)`
  (which returns the default whenever the result failed).
- `exception` - the captured `Throwable`, or `null` when failure was signalled without throwing.
- `sideEffectErrors` - any `Throwable`s caught from `afterCommit()`, via `sideEffectErrors()`.
- `throw()` - throws when the result failed, otherwise returns the result. It rethrows the captured exception, or
  throws a `ServiceFailedException` (whose `result()` returns the failed result) when there is none.

Exceptions from the lifecycle are no longer rethrown from `run()`; they are passed to `onFailure()` and captured on
the result, and the transaction still rolls back.

**Before:**

```php
try {
    $status = (new MyService($input))->run();
} catch (Throwable $exception) {
    // handle failure
}
```

**After:**

```php
$result = (new MyService($input))->run();

if ($result->failed()) {
    // $result->exception is the captured Throwable, or null when
    // handle() signalled failure without throwing
    return $fallback;
}

$value = $result->output();
```

There is no `$data` property and no `$result->data`. Code that relied on `run()` throwing should call `runOrFail()`,
which returns the output typed as the service's `TOutput` or throws the failure:

```php
$value = (new MyService($input))->runOrFail();
```

`runOrFail()` is `run()->throw()->output()`, with the same context handling and lifecycle events as `run()`.

### Removed: implicit trait lifecycle hooks on services

1.x called a static `initialize{TraitName}()` on each trait during service initialisation and a
`{traitName}Success()` method after a successful run. This discovery is gone. Move initialisation into the
constructor or `prepare()`, post-success side effects into `afterCommit()`, or write a `ServiceConcern`.

### Lock key generation via LockKeyProvider contract

The `Lockable` trait no longer declares `abstract generateLockKey()`. `Service` implements `LockKeyProvider` with a
public `getLockKey()` instead.

**Service subclasses** that overrode `generateLockKey()` must override `getLockKey()`, which is `public`:

```php
// Before
class MyService extends Service
{
    protected function generateLockKey(): string
    {
        return sha1('custom-key');
    }
}

// After
class MyService extends Service
{
    public function getLockKey(): string
    {
        return sha1('custom-key');
    }
}
```

**Standalone `Lockable` users** should implement `LockKeyProvider` and provide `getLockKey()`, or set the `$lockKey`
property directly. The trait's `lock()` and `unlock()` are now `public`.

```php
// Before
class MyJob
{
    use Lockable;

    protected function generateLockKey(): string
    {
        return sha1('job-lock');
    }
}

// After
use SineMacula\ApiToolkit\Contracts\LockKeyProvider;

class MyJob implements LockKeyProvider
{
    use Lockable;

    public function getLockKey(): string
    {
        return sha1('job-lock');
    }
}
```

### Removed: ServiceLockException

`ServiceLockException` is gone. Lock handling uses two `\RuntimeException` subclasses: `LockUnavailableException`
when the lock is contended, and `LockOperationException` when a lockable service supplies an empty `lockId()` or no
lock key. A service run does not throw on contention; the runner captures it, so
`(new MyService($input))->run()->exception` holds a `LockUnavailableException`.

Catch `LockUnavailableException` where you caught `ServiceLockException`, or remove the catch if it was unreachable.

### Removed: RepositoryResolver and HasRepositories

The static `RepositoryResolver`, the `HasRepositories` trait and the `repositories.repository_map` config key are
gone. Resolve repositories through dependency injection.

**Before (1.x):**

```php
use SineMacula\ApiToolkit\Repositories\Traits\HasRepositories;

class UserController extends Controller
{
    use HasRepositories;

    public function index()
    {
        return $this->users()->all();
    }
}
```

**After (2.x):**

```php
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
```

Replace `RepositoryResolver::get('alias')` with constructor injection or `app(UserRepository::class)`. Remove
`repository_map` from a published `config/api-toolkit.php`; it is no longer read.

### Renamed: ApiRepository::setAttributes() to persist()

`ApiRepository::setAttributes()` is now `persist()`, with the same signature.

```php
// Before
$repository->setAttributes($model, $attributes);

// After
$repository->persist($model, $attributes);
```

### Changed: repository caching is per-query (sinemacula/laravel-repositories)

The `Cacheable` trait in `sinemacula/laravel-repositories` (`SineMacula\Repositories\Concerns\Cacheable`) used to
cache every read against one whole-table snapshot, so a filtered or by-id read could be served the entire table. It
now caches **per query**: each query is fingerprinted and stored under its own key, and a cache hit runs no query.

- Entries are keyed per query fingerprint; the whole-table snapshot is kept for reference mode only.
- Writes invalidate on an explicit verb list (`create`, `forceCreate`, `firstOrCreate`, `updateOrCreate`,
  `updateOrInsert`, `update`, `delete`, `forceDelete`, `save`, `insert`, `insertGetId`, `upsert`, `increment`,
  `decrement`, `restore`). `create()` now invalidates; `count()` no longer does.
- A size guard (`max_rows` / `max_bytes`) skips storing oversized results; the read still returns normally.
- A by-id miss (`null`) is cached for a short, separate `negative_ttl` (default 10 seconds).

`withoutCache()`, `flushCache()` and `getCacheStatus()` are unchanged.

**Action required:** none for most applications. To keep the old whole-table behaviour for a small, static
reference table, opt in per repository:

```php
protected bool $cacheReferenceTable = true;
```

Use a taggable store (Redis, Memcached) for precise per-table invalidation. On a non-taggable store (file, database)
per-query entries are invalidated through a table version bumped on every write; set
`REPOSITORY_CACHE_REGISTRY_ENABLED=false` to fall back to TTL-only expiry.

Writes made outside the repository (raw Eloquent inserts) are not seen by cached results until the TTL (or
`negative_ttl`) expires or a repository write flushes the table.

Configuration lives in that package under `repositories.cache` (`prefix`, `ttl`, `store`, `max_rows`, `max_bytes`,
`reference_ttl`, `negative_ttl`, `registry_enabled`; env `REPOSITORY_CACHE_*`), each overridable per repository via a
protected property. See the `sinemacula/laravel-repositories` documentation.

### Changed: relation detection requires return type declarations

Relation detection (used by filtering, attribute persistence, resource value resolution and schema validation) no
longer calls model methods. `SchemaIntrospector` reads declared return types via reflection, and a method is a
relation only when its return type (or a member of a union) is a subclass of
`Illuminate\Database\Eloquent\Relations\Relation`.

```php
// Before (1.x, detected without a return type)
public function posts()
{
    return $this->hasMany(Post::class);
}

// After (2.x, return type required)
public function posts(): HasMany
{
    return $this->hasMany(Post::class);
}
```

A relation method without a `Relation` return type is silently no longer detected, so filters and eager loads using
it stop working. Audit your models before upgrading. Schema validation (`api-toolkit.resources.validate_schemas`, on
by default outside production, or `php artisan api-toolkit:validate-schemas`) reports schema relations that no longer
resolve.

Relations registered with `Model::resolveRelationUsing()` are now detected; 1.x did not support them.

### Removed: BaseResource; ApiResource internals decomposed

`SineMacula\ApiToolkit\Http\Resources\BaseResource` is gone. `ApiResource` extends the toolkit's `ToolkitResource`
(itself a `JsonResource`), and `withFields()`, `withoutFields()` and `withAll()` live on `ApiResource`. Change type
hints and subclasses of `BaseResource` to `ApiResource` (or `ApiResourceInterface`).

These protected hooks have been removed from `ApiResource`:

- `getFields()`
- `resolveFieldValue()`
- `resolveSimpleProperty()`
- `resolveComputedValue()`
- `resolveAccessorValue()`
- `resolveRelationValue()`
- `passesGuards()`
- `resolveCountsPayload()`

Express overridden behaviour through the schema definition (fields, computed values, accessors, guards) instead.

`ApiResourceInterface` now declares `getResourceType()`, `getDefaultFields()`, `schema()`, `getAllFields()`,
`resolveFields()`, `eagerLoadMapFor()`, `eagerLoadCountsFor()`, `eagerLoadSumsFor()`, `eagerLoadAveragesFor()`,
`resolve()`, `withFields()`, `withoutFields()` and `withAll()`. Classes implementing it directly must add the new
methods.

Parameter names are now camelCase (`$load_missing` -> `$loadMissing` on the constructor, `$requested_aliases` ->
`$requestedAliases` on `eagerLoadCountsFor()`); update named-argument call sites.

### Added: resources are discovered from `#[ForModel]` and compiled by `optimize`

A resource carrying `#[ForModel(...)]` is bound to its model at boot, so `api-toolkit.resources.resource_map` no
longer needs an entry per resource. With `api-toolkit.resources.paths` left `null`, the application's own
`Http/Resources` directory and each module's (resolved from `app_path()`) are scanned; set an array to choose the
roots, or an empty array to turn discovery off. An explicit `resource_map` entry always wins over a discovered binding.

Without a compiled map the scan runs on every boot. The compiled map is `bootstrap/cache/api-toolkit-resources.php`,
written by `php artisan api-toolkit:resources:cache` and removed by `php artisan api-toolkit:resources:clear`; both
are hooked into `php artisan optimize` and `optimize:clear`. While the file exists it is served as-is; with
`validate_schemas` enabled it is ignored. Discovery never uses the cache store, so with `validate_schemas` disabled,
boot and `route:cache` work without a reachable store; schema validation reads its metadata through the store.

**Action required.** Run `php artisan optimize` (or `php artisan api-toolkit:resources:cache`) on every deploy.
Rebuild or clear the compiled map after adding, moving or re-binding a resource, or after changing `paths`.

### Changed: dynamic morph mapping no longer requires a morph map

In 1.x, `api-toolkit.resources.enable_dynamic_morph_mapping` called `Relation::enforceMorphMap()`, so a polymorphic
write for any model without a resource (including a vendor model) threw `ClassMorphViolationException`. The derived
aliases are now merged into `Relation::morphMap()` and a morph map is never required, so a model without a resource
morphs to its class name. An alias or model your application has already mapped is left alone, and an alias claimed
by more than one model is registered for none of them. Two resources sharing a `RESOURCE_TYPE` log a warning, or fail
the boot when `validate_schemas` is enabled.

**Action required.**

- If you relied on the toolkit enforcing the morph map, call `Relation::requireMorphMap()` in your own service
  provider and map every morphable model that has no resource.
- If a resource is bound to several models, or shares its `RESOURCE_TYPE` with another resource, register the
  aliases those models already store in your own `Relation::morphMap()`, from a service provider's `register()`
  method, or their stored rows no longer resolve. This also clears the warning or boot failure.
- If your application maps a model that has a resource before the toolkit boots (from a `register()` method, or a
  provider that boots earlier), 1.x stored the resource's `RESOURCE_TYPE` for it, but the toolkit now leaves your
  alias in place. Add the `RESOURCE_TYPE` alias to your own morph map so rows already stored under it still resolve.

### Changed: ApiCriteria decomposed; filter operators are extensible

The protected `applyFilters()`, `applyEagerLoading()`, `applyLimit()` and `applyOrder()` hooks have been removed from
`ApiCriteria`. Subclasses that overrode them to add filter behaviour should register a custom operator instead:
implement `SineMacula\ApiToolkit\Contracts\FilterOperator` and register it on the `OperatorRegistry` singleton, for
example in a service provider:

```php
use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;

app(OperatorRegistry::class)->register('$regex', new RegexOperator);
```

The registry also has `override()` and `remove()` for adjusting the built-in operators.

### Changed: filtering and sorting are allowlist-only

A resource now declares which columns are filterable and sortable, and which relations may be traversed, in its
schema. Any other filter, sort or relation key is rejected with a `422`. In 1.x every column was filterable and
sortable unless it was listed in `searchable_exclusions`.

Declare the query surface with the schema markers:

```php
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
```

The capability decides which operators the column answers; see the next section.

A field's filter and sort key is its **column name**, not its alias.
`Field::scalar('email_address', 'email')->filterable(Capability::EXACT)` makes `email_address` filterable even though
clients see the field as `email`.

**Action required.** Add `filterable()`, `sortable()` and `traversable()` to every field and relation clients query.
Undeclared keys return `422` until declared, and a resource with no declared surface rejects every filter and sort.

There is no opt-out. The `query_posture` config key, its `API_TOOLKIT_QUERY_POSTURE` variable and the `blocklist`
posture are gone, as are `reject_undeclared` and `API_TOOLKIT_REJECT_UNDECLARED`; values left in a published config
or `.env` are ignored. An undeclared key on the root resource is always rejected with a `422` naming the key. The
`getSearchableColumns()` and `isSearchable()` methods have been removed from `SchemaIntrospectionProvider` and
`SchemaIntrospector`; a custom implementation of the contract must drop them.

**Sensitive columns.** `searchable_exclusions` is replaced by `api-toolkit.resources.sensitive_columns`, the columns
that may never be declared `filterable()`, `sortable()` or `searchable()`. Schema validation reports a resource that
declares one. The default list is `password`, `token`, `remember_token`, `two_factor_secret`,
`two_factor_recovery_codes`, `two_factor_confirmed_at` and `email_verified_at`. Entries are bare column names: the
`users.password` table-scoped form is no longer recognised, and `password` applies to every resource. A published
config without the key falls back to the default list.

### Changed: `filterable()` names the capability the column has

`Field::filterable()` takes a `SineMacula\ApiToolkit\Enums\Capability` naming the column's access path, and the
capability decides which operators it answers:

```php
use SineMacula\ApiToolkit\Enums\Capability;

Field::scalar('status')->filterable(Capability::ENUM);
```

| Column                                                 | Capability             | Answers                                                                   | Refuses                                                     |
|--------------------------------------------------------|------------------------|---------------------------------------------------------------------------|-------------------------------------------------------------|
| A key read by equality: an id, a foreign key, an email | `Capability::EXACT`    | `$eq`, `$in`, `$null`, `$notNull`                                         | `$neq`, `$gt`, `$ge`, `$lt`, `$le`, `$between`, `$contains` |
| A small closed set: a status, a type, a backed enum    | `Capability::ENUM`     | `$eq`, `$in`, `$neq`, `$null`, `$notNull`                                 | `$gt`, `$ge`, `$lt`, `$le`, `$between`, `$contains`         |
| An ordered column: a number, a date, a timestamp       | `Capability::RANGE`    | `$eq`, `$in`, `$gt`, `$ge`, `$lt`, `$le`, `$between`, `$null`, `$notNull` | `$neq`, `$contains`                                         |
| A JSON column behind a containment index               | `Capability::DOCUMENT` | `$contains`                                                               | every other shipped operator                                |
| A column whose access path you will not vouch for      | `Capability::OPAQUE`   | `$eq`                                                                     | every other shipped operator                                |

There is no text capability; partial matching is declared with `searchable()`.

Operators your application registers under new tokens are not gated and work on any declared column. Overriding a
shipped token keeps that token's row in the table.

**Action required.** Every `filterable()` call needs a capability; a call without one raises an `ArgumentCountError`
when the schema compiles. Pick the narrowest row that is true of the column; `Capability::OPAQUE` still allows
equality where the access path is unknown. Then audit clients: an operator the column does not answer is rejected
with a `422` naming the operator, the column and the operators it accepts, before any SQL is issued.

### Changed: a sortable declaration must be backed by an index

Schema validation now checks that an ordered index leads with every `sortable()` column. Being second in a composite
index does not count. Where the connection reports index kinds, only an ordered kind counts, so a full-text or
trigram index does not make a column sortable. The leading key must also hold the column's own order, so a MySQL
prefix index (`name(20)`), or a PostgreSQL index keyed with a pattern operator class (`text_pattern_ops`) or a
non-default collation, does not count either. A MySQL prefix key still backs an exact or prefix search, and a
PostgreSQL pattern-class key still backs an exact search, but a PostgreSQL key with a different collation backs
neither.

Two overrides cover what the catalogue cannot show:

```php
Field::scalar('name')->sortable()->indexed('users_lower_name_index');
Field::scalar('reference')->sortable()->unindexed('the table is bounded at a few hundred rows');
```

`indexed()` names the index behind the column, such as an expression or partial index; the name must exist on the
table, but what it covers is not checked. `unindexed()` records a deliberate exemption and requires a reason.

A connection that cannot be inspected (no database, or migrations not yet run) skips the check. The catalogue is read
during validation, never while a sort is served.

**Action required.** Run `php artisan api-toolkit:validate-schemas` after upgrading. For each reported column, add
the index in a migration, drop `sortable()`, or record why with `unindexed()`.

### Changed: the query layer no longer fails open

Four behaviours that quietly widened a result set now fail the request.

**`?order=random` is opt-in.** It used to bypass sortable-column enforcement. It is now disabled by default, and while
disabled `random` is rejected as an undeclared sort key. Re-enable it with:

```dotenv
API_TOOLKIT_ALLOW_RANDOM_ORDER=true
```

**A `filters` document must be a JSON object.** A document that cannot be decoded (malformed, or nested beyond the
decoder's 512-level limit), or that decodes to a scalar or a non-empty list, is rejected with a `422` naming
`filters`. It used to be treated as no filter.

**`$contains` no longer drops a clause the grammar rejects.** The grammar's exception now propagates instead of being
logged and the clause dropped.

**Filterable and sortable declarations are validated at boot.** A `filterable()` or `sortable()` marker on a computed
field, on a field whose accessor reads a different path from its column, or naming a column the table does not have,
is reported by schema validation instead of failing at request time with a database error. The last check needs a
connection that can be inspected. Run `php artisan api-toolkit:validate-schemas` after upgrading and drop the marker
or move it to the backing column.

### Added: structural caps bound the cost of a single query

A set of caps under `api-toolkit.query_cost` bounds the structural cost of one request. None existed in 1.x, so this
is the change most likely to refuse traffic that used to be answered: a deeply nested filter, a large `$in` list, a
six-key sort or a deep page now return a `422`. Defaults:

```text
max_bytes         8192   the byte length of the `filters` document as received
max_parse_depth   16     the object levels that document nests
max_depth         3      the levels a filter descends, counting a logical group or a relation as one
max_nodes         100    the keys a filter visits in total
max_in_items      500    the items a single operator value list carries, such as the one `$in` reads
max_order_keys    3      the columns one request may order by
max_aggregates    5      the relation counts, sums, and averages one request asks for, combined
max_offset        500000 the rows a paginated read may scan past to reach its page
```

Each is set from the environment as `API_TOOLKIT_QUERY_` plus the upper-cased cap name, so
`API_TOOLKIT_QUERY_MAX_IN_ITEMS` sets `max_in_items`. `0` (or `null`) disables a cap. A config file published before
this release has none of these keys, and every cap still applies at its default.

`max_bytes` and `max_parse_depth` are checked while the query string is validated, before the filter is interpreted;
a malformed document keeps its own validation error. The rest are checked as the criteria are applied, before any SQL
is issued. `max_in_items` counts the items an operator will read, so a delimited string is bounded the same as an
array. `max_aggregates` counts counts, sums and averages together. `max_offset` counts rows skipped (page number times
page size), applies only when a page number is requested, and does not apply to cursor pagination. The default allows
page 10,000 at the default page size of 50, and page 5,000 at the `max_limit` of 100. The refusal still names the
furthest page available, not a row count.

A request over a cap is answered with the standard error envelope:

```json
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
```

`parameter` is `filters`, `order`, `page`, or `aggregates` (for `counts`, `sums` and `averages` together). `pointer`
is a JSON pointer into the filter document, empty when the cap applies to the whole parameter. `reason` is the config
key of the cap, and `limit` and `actual` are the bound and the value supplied. The title and detail are the ones the
error catalogue lists for the code.

The defaults are calibrated against the package's own fixtures, not production traffic; tune them for your API.

**Action required.** Before upgrading, check what clients send (an access log with query strings is enough): filter
documents over 8192 bytes or nested past 16 levels, filters deeper than 3 levels or with more than 100 keys, `$in`
lists over 500 items, more than 3 sort columns, more than 5 aggregates across `counts`, `sums` and `averages`, and
pages beyond 10,000. Where a shape is legitimate, raise that cap to the largest query you intend to serve rather than
disabling it. The generated Query Surface Reference lists every cap's value and the shape of the rejection.

### Changed: `?limit` above the ceiling is rejected rather than clamped

A `?limit` above `api-toolkit.parser.max_limit` (default 100) used to be reduced to the ceiling. It is now rejected
with the same `422` as the query-cost caps:

```json
"meta": {
  "parameter": "limit",
  "pointer": "",
  "reason": "max_limit",
  "limit": 100,
  "actual": 500
}
```

The config key, the `API_PARSER_MAX_LIMIT` variable and the default are unchanged, and `0` (or `null`) still disables
the ceiling.

**Action required.** Clients asking for a page larger than the ceiling now get a `422` instead of a shorter page.
Raise the ceiling or have the client ask within it.

### Removed: the `$like` operator, replaced by `?search=`

The `$like` operator and `SineMacula\ApiToolkit\Repositories\Criteria\Operators\LikeOperator` are gone. A request
using `$like` is rejected as an unknown operator, and the token no longer appears in the OpenAPI document. `$like`
compiled to `column LIKE '%term%'`, a full table scan on every engine, and could be used inside relation subqueries.

Free-text matching now uses its own parameter, declared per field and served only from an index:

```http
GET /users?search=smith
```

```php
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Schema\Field;

public static function schema(): array
{
    return Field::set(
        Field::scalar('name')->searchable(SearchStrategy::SUBSTRING),
        Field::scalar('email')->searchable(SearchStrategy::SUBSTRING),
    );
}
```

The search covers the requested resource's own columns and never traverses a relation. Terms are bounded by the new
`api-toolkit.search` config block; a term outside the bounds is rejected with a `422`. The minimum word length is
three characters and can be raised but not lowered, and it applies to each word in the term.

**One strategy per resource on MySQL.** A full-text match OR-ed with any other predicate reads the whole table, so the
MySQL driver refuses a resource declaring `SUBSTRING` beside another strategy. Use one strategy for every searchable
column of the resource there. PostgreSQL has no such restriction.

**The indexes are yours to create.** Drivers ship for MySQL, PostgreSQL and SQLite, and each checks a declaration
against the live schema. MariaDB has no n-gram parser and no driver; a search there fails until you register one.

```sql
-- MySQL: SUBSTRING, over exactly the columns declared for it
ALTER TABLE users ADD FULLTEXT INDEX users_search_ngram (name, email) WITH PARSER ngram;

-- PostgreSQL: PREFIX and SUBSTRING, per declared column
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE INDEX users_name_trgm ON users USING gin (name gin_trgm_ops);
CREATE INDEX users_email_trgm ON users USING gin (email gin_trgm_ops);
```

On MySQL the full-text index must cover exactly the declared column set. `EXACT` needs only an ordinary index leading
with the column. SQLite has neither index kind, so it can serve every strategy but prove none.

`api-toolkit.search.unverified_connections` ships empty. **If you develop against SQLite and declare a searchable
field, you must list that connection**, or the application will not boot: schema validation is on by default outside
production and throws, which stops every route and Artisan command, including `php artisan migrate` on a fresh
checkout.

```php
// config/api-toolkit.php
'search' => [
    'unverified_connections' => ['sqlite'],
],
```

Pre-release 2.x builds shipped `sqlite` in that list; it is now empty, so name the connection yourself.

Entries are connection names as `config/database.php` keys them, not engines. A stock application names connections
after their engine, so listing `mysql` waives the connection that usually serves production. A listed connection may
scan the whole table on every request; only list development connections.

Run `php artisan api-toolkit:validate-schemas` in your build to catch a missing index. Because schema validation is
off in production by default, the proof is also taken at request time, so a missing index refuses the request instead
of scanning the table. The request-time answer is cached for `api-toolkit.search.index_proof_ttl` seconds (default
60, `0` for one request or job only), so an index changed outside a migration is picked up when that expiry lapses; a
migration or `php artisan api-toolkit:invalidate-metadata` retires it at once.

**Action required.** Replace `$like` filters with `?search=`, after declaring the searchable fields. If you genuinely
need an unindexed partial match on any filterable column, register the operator yourself in a service provider's
`boot()`:

```php
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
```

This brings back the full table scan, including inside relation subqueries.

### Changed: the exported OpenAPI document carries the query surface

Every documented operation now references the query parameters its action honours: the sparse fieldset and relation
aggregates wherever a resource is serialised, the selection and paging set on an index, and soft-delete visibility
(`trashed`) on the read actions of a model that soft deletes. Previously none were referenced, so generated clients
sent no query parameters.

The filter parameter component was emitted as `filter`, a bracketed deep object the parser rejects. It is now
`filters`, the single URL-encoded JSON document the parser accepts.

Each queryable resource property carries an `x-query-surface` extension (the key to send, the filter capability and
its operators, whether an index backs its sort, and its search strategy), and the schema lists the relations a
filter may descend through as `x-traversable-relations`. Both are read from the compiled schema and the bound
operator registry, so a column whose operators have all been removed carries no `filter` member.

A relation to a resource no registered model maps to now emits a bare object marked `x-undocumented`, with its
cardinality, instead of a reference to a component the document never defines.

`api-toolkit:docs:generate` writes the Query Surface Reference per audience, to
`<docs_path>/audiences/<audience>/60-query-surface-reference.md`. When assembling an audience, a shared file is read
only if the audience has no file of the same name.

**Action required.** Regenerate any client built from the exported document, and rename the `filter` parameter to
`filters` in anything that read the old component names; clients that build the query string themselves are
unaffected. Re-run `api-toolkit:docs:generate` and delete any `60-query-surface-reference.md` left at the root of the
docs directory, which would otherwise be read by any audience without its own file.

### Changed: enum components list their values

Each enum component in the exported document now lists its values under `enum`: the backing values of a backed enum,
or the lowercase case names of a pure enum, which is now how a pure enum is read from requests and written to
responses. A model attribute cast to a pure enum now references its enum component, as a backed-enum cast already
did. The enum reference written by `api-toolkit:docs:generate` shows the same lowercase name for a pure enum, where
it showed a hyphen.

**Action required.** Regenerate any client built from the exported document, and re-run `api-toolkit:docs:generate`.

### Changed: a pure-enum attribute is filtered and written by its lowercase name

A filter on an attribute cast to a pure enum now matches the lowercase case name, on the root model and through
relations; any other spelling, the declared case name included, matches nothing. `ApiRepository::persist()` resolves
a string for such an attribute from its lowercase name and throws a `ValueError` for one that names no case. Passing
an enum case works as before.

**Action required.** Send the lowercase name in filters, and pass a pure-enum attribute to `persist()` as its case or
its lowercase name.

### Changed: a falsy filter value is matched rather than dropped

The shorthand used to drop a field with a falsy value, so `{"name":"0"}` and `{"email":""}` returned the unfiltered
table, and `{"organization_id":0}` failed with a type error. It now compiles to `$eq` for every value: `"0"`, `0`,
`false` and `""` match rows holding that value, and `null` matches rows where the column is null. An empty document
(`{}`) and a field with no conditions (`{"name":{}}`) still apply no filter.

**Action required.** To apply no filter on a field, leave it out of the document instead of sending `""` or `null`.

### Removed: request macros in favour of the parsed query

The `includeTrashed()` and `onlyTrashed()` request macros are gone. Soft-delete visibility is parsed with the other
query parameters and read as a `TrashedState`. The export macros (`expectsExport()`, `expectsCsv()` and
`expectsXml()`) are gone too; exports live in `sinemacula/laravel-resource-exporter`.

```php
// Before
if ($request->includeTrashed()) {
    // ...
}

// After
use SineMacula\ApiToolkit\Enums\TrashedState;
use SineMacula\ApiToolkit\Facades\ApiQuery;

if (ApiQuery::getTrashed() === TrashedState::WITH) {
    // ...
}
```

You rarely need to read it: a read through `withApiCriteria()` applies it where the model uses `SoftDeletes` and the
resource opts in by overriding `allowsTrashed()`. A resource that has not opted in keeps soft-deleted records hidden.

### Exception handling changes

- New exception classes: `ConflictException` (409), `GoneException` (410), `PayloadTooLargeException` (413),
  `LockedException` (423), `ServiceUnavailableException` (503), and a generic `HttpException` carrying any
  `HttpStatus`.
- Unmapped HTTP-layer exceptions (any Symfony `HttpExceptionInterface`) keep their status via the generic
  `HttpException` instead of rendering as a `500`.
- Session token mismatches that Laravel converts to a generic `419` HTTP exception are mapped back to
  `TokenMismatchException` and render as `419`; in 1.x they rendered as a `500`.
- Maintenance-mode responses carry the `Retry-After` and `Refresh` headers from `php artisan down --retry` /
  `--refresh`; in 1.x they were dropped. The response is still always `503`, so `--status` is ignored.
- `PolymorphicResource` throws `ResourceMappingException` (a `\LogicException` subclass) instead of a bare
  `\LogicException`; existing `\LogicException` catches still work.
- The new `api-toolkit.exceptions` config section controls rendering (`render_strategy`) and debug metadata
  (`include_debug_info`). The defaults match 1.x.
- `ApiException` has a new instance-level `getStatusCode()`, which the handler uses when rendering; the static
  `getHttpStatusCode()` remains.

### Changed: caught failures are reported rather than logged

Failures the toolkit catches to protect a boundary or a committed outcome are now passed to the exception handler
with `report()` instead of being written to the log, so they reach your error tracker and follow the handler's
`dontReport`, throttling and `reportable` rules. This covers:

- an exception thrown by a service's `afterCommit()` or `onFailure()` hook;
- an Octane or queue worker cache flush failure;
- an unexpected failure inside the deferred-write boundary subscriber;
- a boundary flush that fails to persist non-disposable writes (the `collect` strategy, or `throw` without
  `rethrow_at_boundary`), reported as a `WritePoolFlushException`. Its report context carries the chunk and record
  counts and the failing table names, never the buffered records, and its previous exception is the first database
  failure.

The handler's rules apply to the reported exception, not its previous exception. To silence the boundary flush
report, add `$exceptions->dontReport(WritePoolFlushException::class)`; `dontReport(QueryException::class)` does not
suppress the wrapper. The chained database exception keeps its own message, which log formatters and error trackers
print, so the report can contain record values from the failed chunk. `mask_bindings_in_exception_messages` on the
connection only stops bindings being substituted into the SQL; the driver's own error text, such as a duplicate-key
message, can still quote the value.

If reporting itself fails, the original exception is written to the error log and the failure never escapes. The
`log` deferred-write strategy now logs at warning level instead of error.

### Changed: handled exceptions are no longer reported or logged

In 1.x every `ApiException` was written at `error` level to the `api-exceptions` channel (and to
`cloudwatch-api-exceptions` when CloudWatch logging was on), with the request method, path, user id and full request
input. In 2.x a handled exception is neither logged nor reported.

- The `api-exceptions` log channel is no longer defined. If a log stack or `Log::channel()` call references it,
  define it yourself.
- 1.x suppressed reports with `->stop()` in the report chain, so a reporter (an error tracker, say) registered before
  `ApiExceptionHandler::handles()` still received every handled exception. 2.x registers a don't-report filter, which
  applies whatever the order.
- An `UnhandledException` thrown by hand is now reported, as that same instance, to Laravel's default reporting and
  every error tracker. In 1.x it was written to `api-exceptions` and stopped there.
- Every other non-`ApiException` is still reported as itself, subject to Laravel's don't-report list and the
  exclusions below.
- `Illuminate\Validation\UnauthorizedException`, and any exception implementing Symfony's `HttpExceptionInterface`
  that does not extend Symfony's `HttpException` and whose status is a recognised client error (one the handler
  renders as a `4xx`) or `419`, are no longer reported. An unrecognised status such as `499` still renders as a `500`
  and is reported.
- `LockUnavailableException` is new and never reported; lock contention shows up as `429` responses.
- Reports no longer carry request input. Add fields with your own `$exceptions->context()` callback.

### Changed: the notification audit log writes to the default log channel

The toolkit no longer adds to or merges into the application's `logging` config. Its `notifications` daily-file
channel is gone, and a `notifications` channel the application defines no longer has the toolkit's keys merged in.
`Notification Sending` and `Notification Sent` lines go to the default log channel.

To keep a dedicated destination:

1. Define the channel in your application's `config/logging.php`.
2. Set `NOTIFICATION_LOG_CHANNEL=<channel name>`.
3. If you have published `config/api-toolkit.php`, add the new key to its `notifications` block. The package config
   is merged shallowly, so a published `notifications` array replaces the whole block, and without the key the
   default channel is used:

   ```php
   'notifications' => [
       // ...
       'log_channel' => env('NOTIFICATION_LOG_CHANNEL'),
   ],
   ```

The lines themselves have changed:

- `Notification Sending` is logged at `debug` (1.x used `info`); `Notification Sent` stays at `info`. A channel at
  `info` or above, as production default channels usually are, drops the Sending lines; use a dedicated `debug`
  channel to keep them.
- The `notifiable` context key is now `notifiable_type`, and a new `notifiable_id` carries the notifiable's key when
  it is an Eloquent model with one. Update log queries, filters and dashboards that match on `notifiable`.
- Lines are no longer mirrored to `cloudwatch-notifications` when `api-toolkit.logging.cloudwatch.enabled` is `true`;
  the channel and the setting are gone. To send the audit log to CloudWatch, define a CloudWatch channel (the driver
  now ships separately, see "Composer dependency changes") and point `NOTIFICATION_LOG_CHANNEL` at it.

### Changed: deferred writes are safe by default

The deferred-write pool (the `Deferrable` trait, backed by `WritePool`) no longer drops records on a flush failure.
The default `on_failure` strategy is now `collect` instead of `log`.

**Before:** a failed chunk insert was logged at error level and the **whole buffer was cleared**, discarding the
failed records. A configured `throw` was swallowed by the boundary subscriber and logged, and a memory-pressure
auto-flush switched `throw` to `collect`.

**After:**

- `collect` (default): every chunk failure is collected in the returned `WritePoolFlushResult` and the **failed
  records stay in the buffer** for the next flush. No record is dropped and no exception escapes. The boundary
  subscriber reports a `WritePoolFlushException` and dispatches `WritePoolFlushFailed`.
- `throw`: raises `WritePoolFlushException` on the first failure, carrying the partial result, and keeps the failed
  and unprocessed records. The memory-pressure auto-flush honours it, so `defer()` / `add()` may throw when the pool
  limit is crossed.
- `log`: logs a warning, continues, and **clears the buffer** (the old default). Failed records are dropped; use it
  only for disposable writes such as audit, analytics or telemetry.

To restore the previous behaviour:

```dotenv
DEFERRED_WRITES_ON_FAILURE=log
```

New config keys, both off by default:

```dotenv
# Wrap each table's chunk set in a transaction (all-or-nothing per table).
DEFERRED_WRITES_TRANSACTIONAL=true

# Re-throw a WritePoolFlushException at the lifecycle boundary instead of
# reporting it (only applies under the 'throw' strategy).
DEFERRED_WRITES_RETHROW_AT_BOUNDARY=true
```

**Boundary 500s.** The boundary flush runs on `RequestHandled`, after the response is built. Under `throw` the
subscriber catches and reports the exception, so the request keeps its response. Only with
`DEFERRED_WRITES_RETHROW_AT_BOUNDARY` enabled does it escape, turning one constraint violation into a 500 for a
request that already completed. If you want the exception, call `flushWrites()` yourself under `throw`. Do not rely on
the rethrow in queue workers: a rethrow from `JobProcessed` marks a completed job as failed, and a rethrow from
`JobFailed` replaces the job's own exception, which is then never reported. Call `flushWrites()` before the job
returns instead.

**Crash window.** Buffered writes live only in PHP memory until the boundary flush, so a crash, out-of-memory error
or SIGKILL before it loses them. Use a real queue for durability. Under Octane the pool is request-scoped, so
retained records are retried within the request and discarded when the scope resets; use `log` for fire-and-forget
writes that must never be retained.

**Observability.** `WritePoolFlushResult` exposes `flushedRecordCount()`, `failedRecordCount()`,
`retainedRecordCount()` and `droppedRecordCount()` alongside the chunk counters, `flushedTables()` listing every table
the flush attempted, and `firstException()` returning the first underlying failure. `firstException()` is not
serialised, so a queued `WritePoolFlushFailed` listener always gets `null` from it. Subscribe to
`WritePoolFlushFailed` to send retained failures to a dead-letter sink, alerting or metrics.

**Query cache invalidation.** The bulk insert bypasses the per-query cache invalidation a repository write triggers,
so the boundary flush now invalidates the per-query cache for every table it persisted. This covers `Cacheable`
repositories on the default cache configuration (the configured repository store, keyed by table name). A repository
with a custom store (`cacheStoreName`) or key prefix (`cacheKeyPrefix`) is not reached; call `flushCache()` after the
flush or rely on the TTL. To turn the invalidation off:

```dotenv
DEFERRED_WRITES_INVALIDATE_QUERY_CACHE=false
```

### Changed: the lifecycle metadata flush is on by default on serving runtimes

The lifecycle flush, which resets the toolkit's in-process state between requests and jobs, is **on by default** when
serving under Octane or running as a queue worker. PHP-FPM is unaffected; the runtime check does not fire there even
when Octane is installed.

- Octane: the flush engages only when `$_SERVER['LARAVEL_OCTANE']` is set, which a booted Octane worker does.
- Queue worker: `JobProcessed` and `JobFailed` trigger it, except on the `sync` driver, where those events fire inside
  the originating request.

The boundary resets:

- the process-static memos: the schema compile cache (`SchemaCompiler`), the serialisation, eager-load, field and
  field-to-column memos, the compiled search plans, and the per-operation index proofs;
- the `SchemaIntrospector` singleton's in-memory column listings, column definitions and index catalogues;
- the memoised metadata generation, so the worker picks up an invalidation made elsewhere;
- the bound query parser's state.

`CacheManager::flush()` resets all of it and is called by the Octane and queue listeners at every boundary. Octane
fires it after every request, task and tick.

The boundary does not touch the cache store. Toolkit metadata (schema columns, column definitions, index catalogues,
relation lookups, search index proofs, resources and repository model casts) is read and written through
`Cache::memo()` on the application's cache store, which is usually shared by every worker, and is retired only by
replacing the generation (see the metadata invalidation section below) or by its expiry. Any new toolkit metadata key
must go through `MetadataCacheWriter` so it is namespaced by the generation. Because stored metadata survives the
boundary, a request after a boundary does not re-query the schema. A column listing that reads empty, as before its
table exists, is never stored.

**Action required.** None for most applications.

**Opt out** (in-process memos then grow for the life of the worker, and an invalidation made elsewhere is not picked
up until it restarts):

```dotenv
API_TOOLKIT_LIFECYCLE_OCTANE=false
API_TOOLKIT_LIFECYCLE_QUEUE=false
```

Or in a published `config/api-toolkit.php`:

```php
'lifecycle' => [
    'octane' => false,
    'queue'  => false,
],
```

When a serving runtime is detected but the flush is off, the toolkit logs a one-line `info` message saying so.

### Added: metadata is invalidated across processes after migrations

Cached schema metadata lives in the shared cache store, so after a deploy that changed the schema every process
would keep serving the old entries. Every metadata key is now stored under a generation held in the same store, and
replacing the generation retires every entry in every process sharing the store:

- The generation is replaced when a migration run (`migrate`, `migrate:rollback`, `migrate:fresh` and the like)
  finishes. A run with nothing to migrate, or a `--pretend` run, keeps the metadata. If the store cannot be written,
  the hook logs a warning instead of failing the migration.
- `php artisan api-toolkit:invalidate-metadata` replaces it on demand, and exits with a failure if the store rejects
  the new generation.
- `CacheManager::invalidateMetadata()` is the programmatic entry point behind both.

The Octane and queue boundary flushes re-read the generation rather than replacing it, so a long-lived worker picks up
an invalidation at its next boundary.

**Multi-tenant applications.** Column listings, column definitions and index catalogues are keyed by the schema the
connection reads:

- its name (with any read or write suffix), effective database, table prefix, Postgres `search_path` (or `schema`)
  and database user;
- the server it is configured for: driver, host list (in any order), port, Unix socket, and the Postgres
  `connect_via_database` and `connect_via_port` or SQL Server ODBC data source name. Every host in one list must
  serve the same schema.

A tenancy switcher that repoints one connection name at another tenant's database, prefix or search path therefore
caches each tenant's schema separately, even within one job. The identity is read from configuration and the
connection object, never by a query, so:

- after changing the configuration, purge the connection (`DB::purge()`) or configure a fresh one; `DB::reconnect()`
  reuses the old configuration;
- a search path changed at runtime with `SET search_path` is not seen, so a switcher working that way needs a
  per-tenant cache prefix (which separates the shared cache but not one job's in-process memos);
- a read alias such as `tenant::read` is described by its write-side settings, including the write host, so repoint
  the write side along with the read side;
- with the list form of read and write settings (`'write' => [[...], [...]]`) the framework picks one entry per
  connection, so workers may cache under several correct keys that fill separately; prefer one entry with a host
  list.

Casts, relation lookups and the model-to-resource map come from code, so they are keyed by class and shared across
tenants. Where each tenant has its own cache prefix, each has its own generation, so run the invalidation per tenant.

The migration hook is not enough on its own: migrations run before the new release takes traffic, so workers still on
the old code can refill the new generation with their casts, resource mappings and relation lookups.

**Action required.** Run `php artisan api-toolkit:invalidate-metadata` on every deploy, after the new release is
live. Code that read toolkit metadata keys straight from the store must go through `MetadataCacheWriter`, since the
stored key now carries the generation. Metadata cached by 1.x is not read after the upgrade and is rebuilt on first
use.

**Opt out of the migration hook:**

```dotenv
API_TOOLKIT_LIFECYCLE_MIGRATIONS=false
```

Or in a published `config/api-toolkit.php`:

```php
'lifecycle' => [
    'migrations' => false,
],
```

Entries under a retired generation are no longer read but stay in the store until it evicts or clears them, so each
invalidation leaves at most one stale copy behind.

### Changed: an unavailable cache store no longer fails the request

A cache store that threw (an unreachable Redis or Valkey server, say) used to fail every request for the length of
the outage, and a store that rejected a write dropped it silently. The metadata cache is now optional:

- A store operation that throws while resolving the generation, reading or writing is reported as a
  `MetadataCacheUnavailableException`, whose previous exception is the store failure and whose report context names
  the operation, the store and the metadata key. The store is then bypassed for `api-toolkit.cache.retry_after`
  seconds (`API_TOOLKIT_CACHE_RETRY_AFTER`, default `30`), during which metadata is computed without the cache. With
  `0`, every failed call is retried and reported.
- A write the store rejects is reported at most once a window, without a previous exception, and bypasses nothing,
  so the store keeps serving its warm entries. The `null` driver rejects every write by design and is not reported.
- A time-to-live of zero or less, such as `relation_cache_ttl` set to `0`, stores nothing.
- An exception from the code computing a value is not a store failure and still propagates.
  `api-toolkit:invalidate-metadata` writes to the store directly, so it still fails if the store cannot be written.

The windows are per process, so an Octane or queue worker reports an outage once a window. Under PHP-FPM each request
reports it once; throttle it with a lottery, since a rate limit on the same unavailable store cannot throttle
(Laravel then reports every occurrence):

```php
use Illuminate\Support\Lottery;
use SineMacula\ApiToolkit\Exceptions\MetadataCacheUnavailableException;

$exceptions->throttle(fn (Throwable $e) => $e instanceof MetadataCacheUnavailableException
    ? Lottery::odds(1, 100)
    : null);
```

The bypass limits how many store calls fail, not how long each takes. Laravel's default Redis configuration sets no
timeout, so give the cache's Redis connection a short `timeout` and `read_timeout`. With the `database` cache driver on
the application's own connection, a failed cache query inside a transaction still aborts that transaction on
PostgreSQL, so give the cache store its own connection.

If you have published `config/api-toolkit.php`, add the new key to its `cache` block, or the environment variable has
no effect:

```php
'cache' => [
    'prefix'      => 'api-toolkit',
    'retry_after' => env('API_TOOLKIT_CACHE_RETRY_AFTER', 30),
],
```

Metadata is read and written with `get()` and `forever()` or `put()` on the memoised repository instead of
`remember()` or `rememberForever()`, so tests that mock `Cache::memo()` with those expectations need updating; an
unexpected call on such a mock is treated as a store failure.

Service locks, the `throttle` middleware and the query-cache invalidation after deferred writes
(`deferred_writes.invalidate_query_cache`) still use the cache store, or its Redis connection, directly and fail when
it is unavailable.

### Removed: ProvidesExclusiveLock listener trait

The `ProvidesExclusiveLock` listener trait has been removed. A listener that used it must provide its own locking,
for example with the toolkit's `Lockable` concern.
