---
name: laravel-api-toolkit
description: >-
  Use when adding or changing JSON API endpoints in an application that uses sinemacula/laravel-api-toolkit:
  exposing a model, making fields filterable, sortable or searchable, writing repositories, services, API exceptions
  or OpenAPI attributes, or fixing a schema validation or 422 query error. Covers the SineMacula\ApiToolkit\
  namespace, ApiResource, Field::, #[ForModel], ApiRepository, Service, config/api-toolkit.php and the api-toolkit:*
  commands.
---

# Laravel API Toolkit

These rules depart from stock Laravel and from toolkit 1.x. Breaking one fails boot with `InvalidSchemaException`,
answers a `422`, or silently drops behaviour. Each rule ends with the quoted heading of the section holding its detail,
in `vendor/sinemacula/laravel-api-toolkit/README.md` (README) or `vendor/sinemacula/laravel-api-toolkit/UPGRADE.md`
(UPGRADE), both matching the installed version.

## Namespaces

```php
use Illuminate\Routing\Attributes\Controllers\Authorize;
use SineMacula\ApiToolkit\Contracts\ErrorCodeInterface;
use SineMacula\ApiToolkit\Exceptions\ApiException;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Routing\Attributes\AuthorizesResource;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use SineMacula\ApiToolkit\Http\Routing\Controller;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseSchema;
use SineMacula\ApiToolkit\Repositories\ApiRepository;
use SineMacula\ApiToolkit\Services\Actors\AnonymousActor;
use SineMacula\ApiToolkit\Services\Actors\EloquentActor;
use SineMacula\ApiToolkit\Services\Input\Payload;
use SineMacula\ApiToolkit\Services\Service;
use SineMacula\ApiToolkit\Services\ServiceContext;
use SineMacula\Http\Enums\HttpStatus;
use SineMacula\Repositories\Concerns\Cacheable;
```

## Default to the toolkit

Extend the application's existing resources, repositories, services and controllers. An endpoint over a model that an
`ApiResource` exposes returns that resource or another `ApiResource`: a plain `JsonResource` or a hand-built array
bypasses the field allowlist, eager-load planning, row visibility and the OpenAPI component. A route with no resource
is legitimate and documents its body with `#[ResponseSchema]`.

## Worked example: orders filterable and sortable by total

`php artisan api-toolkit:make:resource OrderResource` drafts the resource. Back the sort with an index in a migration:

```php
Schema::table('orders', fn (Blueprint $table) => $table->index('total'));
```

```php
use App\Models\Order;
use SineMacula\ApiToolkit\Attributes\ForModel;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

#[ForModel(Order::class)]
class OrderResource extends ApiResource
{
    public const string RESOURCE_TYPE = 'order';

    protected static array $default = ['id', 'total'];

    public static function schema(): array
    {
        return Field::set(
            Field::scalar('id')->filterable(Capability::EXACT),
            Field::scalar('total')->filterable(Capability::RANGE)->sortable(),
        );
    }
}
```

Then run `php artisan migrate` and `php artisan api-toolkit:validate-schemas`.

## Resources

- Extend `ApiResource`, bind the model with `#[ForModel]`, return `Field::set(...)` from a static `schema()` and list
  the default fields in `$default`. With dynamic morph mapping on (the default), `RESOURCE_TYPE` is the model's morph
  alias, so renaming it orphans polymorphic rows. README "Schema-Driven ApiResource".
- Resources under `app/Http/Resources` and `app/*/Http/Resources` are discovered unless `api-toolkit.resources.paths`
  says otherwise; bind any other in `api-toolkit.resources.resource_map`. README "Schema-Driven ApiResource".
- A model relation method needs a `Relation` return type (`HasMany`, `BelongsTo`, ...); without one it is silently not
  a relation. UPGRADE "Changed: relation detection requires return type declarations".
- `schema()` runs once per worker and is shared by every request, so it must not read the request, user, tenant, clock
  or config. Read them when a guard, accessor or compute closure runs; each receives the request. README
  "Schema-Driven ApiResource".
- Limit what a caller may see with a static `restrictRows(Builder $query, Request $request)` that adds only wheres on
  qualified columns and fails closed: `whereIn($query->qualifyColumn('team_id'), $request->user()?->teamIds() ?? [])`.
  Route-model binding bypasses it, so a restricted show, update or destroy re-reads the bound model through the
  criteria: `$orders->withApiCriteria()->scopeById($order->getKey())->firstOrFail()`. Keep the binding, since an
  `AuthorizedController` gate given a bare id denies. README "API Query Parser".

## Query surface

Filtering, sorting and search are allowlist-only with no opt-out: a key no field declares is a `422`. UPGRADE
"Changed: filtering and sorting are allowlist-only".

`filterable()` takes the column's access path, which decides the operators it answers; any other operator is a `422`.
Choose the narrowest row that is true of the column. README "Schema-Driven ApiResource".

| Capability             | Declare it for                  | Shipped operators                                                         |
|------------------------|---------------------------------|---------------------------------------------------------------------------|
| `Capability::EXACT`    | an id, a foreign key, an email  | `$eq`, `$in`, `$null`, `$notNull`                                         |
| `Capability::ENUM`     | a status, a type, a backed enum | `$eq`, `$in`, `$neq`, `$null`, `$notNull`                                 |
| `Capability::RANGE`    | a number, a date, a timestamp   | `$eq`, `$in`, `$gt`, `$ge`, `$lt`, `$le`, `$between`, `$null`, `$notNull` |
| `Capability::DOCUMENT` | JSON behind a containment index | `$contains`                                                               |
| `Capability::OPAQUE`   | no access path to vouch for     | `$eq`                                                                     |

- `sortable()` needs an ordered index leading with the column, created in a migration. Otherwise name an expression or
  partial index with `indexed('index_name')`, or record a deliberate exemption with `unindexed('reason')`. UPGRADE
  "Changed: a sortable declaration must be backed by an index".
- A sortable aggregate takes the reason it may run unindexed, `Count::of('posts')->sortable('reason')`, as does a
  sortable relation, which must be a `BelongsTo` other than `MorphTo`, or a `HasOne`, `MorphOne` or `HasOneThrough`
  declared with `latestOfMany()` or `ofMany()`. README "API Query Parser".
- `searchable()` takes a `SearchStrategy` (`EXACT`, `PREFIX` or `SUBSTRING`) and answers `?search=` only from the
  engine's index, created in a migration: on MySQL one `FULLTEXT ... WITH PARSER ngram` index over exactly the
  `SUBSTRING` columns, with no other strategy on the resource; on PostgreSQL a `gin_trgm_ops` index per `PREFIX` or
  `SUBSTRING` column; otherwise an ordinary index leading with the column. SQLite proves no index, so a development
  SQLite connection is listed by name in `api-toolkit.search.unverified_connections`, or boot fails while schema
  validation is on; never list a production connection. README "API Query Parser".
- Columns in `api-toolkit.resources.sensitive_columns` are never filterable, sortable or searchable. README
  "Repositories".
- A relation is filtered through only when declared `traversable()`, against its own resource's declarations. README
  "API Query Parser".
- There is no `$like`: partial matching is `?search=`. UPGRADE "Removed: the `$like` operator, replaced by `?search=`".
- A `?limit` over `api-toolkit.parser.max_limit` is a `422`, not clamped. A resource lowers it with a static
  `maxLimit()`. UPGRADE "Added: `?limit` has a ceiling".
- `?trashed` is refused unless the model soft deletes and the resource overrides `allowsTrashed()`. README "API Query
  Parser".

## Repositories

- Extend `ApiRepository`, return the model class from `model()` and inject it. README "Repositories".
- `withApiCriteria()` returns a copy, so read through the value it returns: `$orders->withApiCriteria()->paginate()`.
  A bare `$orders->withApiCriteria();` statement drops the criteria. UPGRADE "Raised: laravel-repositories 3.2.1 is
  now the floor".
- `withApiFilters()` applies the client's `filters`, `search` and `trashed` but not its order, limit or fields, for a
  bulk action over the filtered set. Criteria applied outside a request, as in a job, throw unless run inside
  `ApiQuery::using()`. README "Repositories".
- Write with `persist($model, $attributes)`, which applies no `$fillable` or `$guarded`, so pass only validated
  input. README "Repositories".
- The `Cacheable` trait keys each read by its SQL: scopes and constraints must be deterministic, `paginate()` is never
  cached, and writes made outside the repository stay stale until the TTL or `flushCache()`. README "Repositories".

## Services

- Declare `@extends Service<TInput, TOutput>` and implement `handle(): mixed`, returning the output and throwing on
  failure. A controller calls `runOrFail()`; `run()` returns a `ServiceResult` to branch on. README "Services".
- The input is a `ServiceInput`, usually a `Payload` with static `rules()` and promoted readonly properties. Mark
  secrets `#[\SensitiveParameter]`, which drops them from the lifecycle events' input summary and encrypts a queued
  run. A payload built from the request reads its body and uploaded files, never the query string. README "Services".
- Transactions and locks are properties: `$transactional`, `$transactionAttempts` and `$lockable`, with `lockId()`
  returning the lock identity when lockable. UPGRADE "Services: transactions and locking move to class properties".
- Pass the caller with `withContext(ServiceContext::fromRequest($request, EloquentActor::for($request->user())))`,
  with `new AnonymousActor` in place of the `EloquentActor` for a guest; services never read `Auth` or the request.
  README "Services".
- Work outside the transaction goes in `afterCommit()`; cross-cutting wrappers implement `ServiceConcern`. UPGRADE
  "Services: cross-cutting concerns implement ServiceConcern".

## Controllers and authorization

- Extend `Controller`, or `AuthorizedController` with `#[AuthorizesResource(Order::class)]` to gate the resource
  actions by policy, and render with `respondWithItem()` or `respondWithCollection()`. README "Controllers and
  Authorization".
- Every other action on an `AuthorizedController` is gated by `#[Authorize]` or a route `can:`, is marked
  `#[CustomAuthorization]` or `#[SkipAuthorization]`, or is listed in `except`; any other fails closed with a `500`.
  README "Controllers and Authorization".

## Errors and OpenAPI

- `ApiExceptionHandler::handles($exceptions)` is registered in `bootstrap/app.php`. README "Exception Handling".
- An `ApiException` subclass declares `public const ErrorCodeInterface CODE` and `public const HttpStatus
  HTTP_STATUS`. Name it in the action's `@throws` tag to document its status. README "Schema Introspection and OpenAPI
  Export".
- `#[ResponseSchema]`, `#[ResponseHeader]`, `#[ResponseStatus]` and `DefinesSchema` document behaviour and implement
  none of it. A `204` or `205` never carries a `#[ResponseSchema]`. README "Schema Introspection and OpenAPI Export".

## Removed in 2.x, never reintroduce

- `BaseResource`: extend `ApiResource`. UPGRADE "Removed: BaseResource; ApiResource internals decomposed".
- `setAttributes()`: call `persist()`. UPGRADE "Renamed: ApiRepository::setAttributes() to persist()".
- `RepositoryResolver` and `HasRepositories`: inject the repository. UPGRADE "Removed: RepositoryResolver and
  HasRepositories".
- The `includeTrashed()` and `onlyTrashed()` request macros: read `ApiQuery::getTrashed()`. UPGRADE "Removed: request
  macros in favour of the parsed query".
- Implicit service trait hooks: use `prepare()`, `afterCommit()` or a `ServiceConcern`. UPGRADE "Removed: implicit
  trait lifecycle hooks on services".
- The toolkit's own `HttpStatus` enum: import `SineMacula\Http\Enums\HttpStatus`. UPGRADE "Removed: HttpStatus enum".

## Verify

- After changing `config/api-toolkit.php` or upgrading the toolkit, run `php artisan api-toolkit:config:validate`. A
  failure names the settings to remove or correct. README "Configuration".
- After a schema or migration change, run `php artisan api-toolkit:validate-schemas` against a migrated, reachable
  database. A failure names the declaration and its missing backing: fix the migration or the declaration, never pass
  `--allow-empty` or `--allow-unverified` or turn off `api-toolkit.resources.validate_schemas`. README "Schema-Driven
  ApiResource".
- After adding, moving or rebinding a resource while a compiled map exists, run
  `php artisan api-toolkit:resources:clear` or `php artisan optimize`. README "Schema-Driven ApiResource".
- After changing a documented route, run `php artisan api-toolkit:export-openapi` and
  `php artisan api-toolkit:docs:generate`. README "Schema Introspection and OpenAPI Export".
- On deploy, run `php artisan optimize`, then `php artisan api-toolkit:invalidate-metadata` once the release is live,
  and reload PHP, Octane and queue workers. README "Metadata Invalidation".
