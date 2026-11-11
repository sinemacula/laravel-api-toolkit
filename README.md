<h1 align="center">Laravel API Toolkit</h1>

<div align="center">

[![Latest Stable Version](https://img.shields.io/packagist/v/sinemacula/laravel-api-toolkit.svg)](https://packagist.org/packages/sinemacula/laravel-api-toolkit)
[![Build Status](https://github.com/sinemacula/laravel-api-toolkit/actions/workflows/tests.yml/badge.svg?branch=master)](https://github.com/sinemacula/laravel-api-toolkit/actions/workflows/tests.yml)
[![Code Coverage](https://qlty.sh/gh/sinemacula/projects/laravel-api-toolkit/coverage.svg)](https://qlty.sh/gh/sinemacula/projects/laravel-api-toolkit)
[![License](https://img.shields.io/github/license/sinemacula/laravel-api-toolkit.svg)](LICENSE)

</div>

Building blocks for JSON APIs in Laravel: a request query parser, schema-driven resources with an allowlisted query
surface, repositories, a JSON exception handler, a service layer and an OpenAPI 3.1 exporter.

## Installation

```bash
composer require sinemacula/laravel-api-toolkit
```

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --provider="SineMacula\ApiToolkit\ApiServiceProvider" --tag=config
```

`config/api-toolkit.php` is documented inline. Per-query repository caching is configured separately, in the
`sinemacula/laravel-repositories` package under `repositories.cache`.

The Markdown templates for the generated API manual are published with `--tag=api-toolkit-docs`.

## Usage

### API Query Parser

`ApiQueryParser` sits behind the `ApiQuery` facade and is populated by the `ParseApiQuery` middleware, which the
service provider pushes onto the global middleware stack unless `api-toolkit.parser.register_middleware` is `false`.

**Sparse fieldsets** - request only the fields you need for a resource type:

```http
GET /users?fields[user]=id,name,email
```

```php
use SineMacula\ApiToolkit\Facades\ApiQuery;

$fields = ApiQuery::getFields('user'); // ['id', 'name', 'email']
```

**Filtering** - pass a URL-encoded JSON object of operator tokens:

```http
GET /users?filters={"status":{"$eq":"active"},"created_at":{"$ge":"2024-01-01"}}
```

A `filters` value that is not a JSON object is rejected with a validation error.

Built-in operators: `$eq`, `$neq`, `$gt`, `$lt`, `$ge`, `$le`, `$in`, `$between`, `$contains`, `$null`, `$notNull`.
Each is accepted only on a column whose declared capability allows it (see
[Query capabilities](#schema-driven-apiresource)); any other pairing is rejected with a 422 naming the operator, the
column and the operators that column accepts. The shorthand `{"name":"Alice"}` is `$eq` and is gated the same way.

**Free-text search** - match a term against the fields a resource declares `searchable()`:

```http
GET /users?search=smith
```

The term is matched against the requested resource's own columns, never through a relation. Each searchable field
declares a match strategy (`SearchStrategy::EXACT`, `PREFIX` or `SUBSTRING`), and the connection's search driver
refuses a strategy it cannot serve from an index. Terms are bounded by `api-toolkit.search`; a term outside the bounds
is rejected with a 422, not trimmed.

The minimum word length is three characters. Configuration can raise it but not lower it: below three, MySQL matches
nothing once a word is shorter than the n-gram token size, and PostgreSQL answers by reading the whole table. The
minimum applies to each word, because a short word is dropped from a MySQL full-text phrase but kept by a PostgreSQL
pattern match, so the two engines would return different rows.

Drivers ship for MySQL, PostgreSQL and SQLite, registered under the driver name each connection reports. On MySQL the
proof also checks that the planner can use the index, which the catalogue only reports from 8.0; on older versions
that check is skipped. MariaDB reports its own driver name and has no n-gram parser, so a search on a MariaDB
connection fails until you register a driver for it on the `SearchDriverRegistry`.

The indexes belong in your own migrations:

- On MySQL, the columns declared `SUBSTRING` are matched together through a single `MATCH`, which needs one `FULLTEXT`
  index over exactly that column list, created `WITH PARSER ngram`. A full-text match OR-ed with any other predicate
  reads the whole table, so the driver refuses a `SUBSTRING` declaration beside any other strategy on the same
  resource.
- On PostgreSQL, `PREFIX` and `SUBSTRING` each need a `gin_trgm_ops` index on their own column, with the `pg_trgm`
  extension installed. Columns may mix strategies.
- `EXACT` needs an ordinary index leading with the column on either engine. On PostgreSQL that B-tree must use the
  column's own collation.

`php artisan api-toolkit:validate-schemas` reports a searchable declaration with no index behind it; run it in CI.
Because schema validation is off in production by default, the proof is also taken at request time, so a missing
index refuses the request instead of scanning the table. On PostgreSQL a missing trigram index is not an error, so
without this the search would silently run as a sequential scan.

The request-time proof is cached in the cache store for `api-toolkit.search.index_proof_ttl` seconds
(`API_TOOLKIT_SEARCH_INDEX_PROOF_TTL`, default 60), keyed by the connection's schema and the declaration, and shared
by every request, job and worker. The expiry runs from the first read. A migration retires every cached proof through
the migration hook. An index changed outside a migration, or while the hook is off or failed to write, is picked up
when the expiry lapses or after `php artisan api-toolkit:invalidate-metadata`. `0` keeps each answer for one request
or job. Within one operation the answer is also held in memory, so a long-running job may keep it past the expiry.

The only way to turn the request-time proof off is `api-toolkit.search.unverified_connections`, which lists the
connections a driver may serve a search on without proving an index. It ships empty. SQLite cannot prove any index,
so a SQLite development connection must be listed before a searchable field is served:

```php
'search' => [
    'unverified_connections' => ['sqlite'],
],
```

With `validate_schemas` on (the default outside production), an unlisted connection fails application boot, not just
the search request: every route and every Artisan command, including `php artisan migrate` on a fresh checkout with
no database file yet. List the connection, or set `VALIDATE_SCHEMAS=false` until you do.

Entries are connection names as `config/database.php` keys them, not engines. A stock application names each
connection after its engine, so listing `mysql` waives the connection that usually serves production. A listed
connection may scan the whole table on every request; only list development connections.

**Sorting** - sort by one or more columns, with optional direction:

```http
GET /users?order=last_name,first_name:desc
```

**Page-size ceiling** - a `?limit` above `api-toolkit.parser.max_limit` (default 100) is rejected with a `422` naming
the ceiling and the requested size, not reduced to the ceiling. Set the ceiling to `0` to disable it:

```http
GET /users?limit=200   // 422, the ceiling is 100
GET /users?limit=25    // honoured as-is
```

**Relation aggregates** - request counts, sums, or averages over declared relations:

```http
GET /users?counts[user]=memberships,posts
GET /accounts?sums[account][transaction]=amount
GET /accounts?averages[account][order]=total
```

```php
ApiQuery::getCounts('user');           // ['memberships', 'posts']
ApiQuery::getSums('account');          // ['transaction' => ['amount']]
ApiQuery::getAverages('account');      // ['order' => ['total']]
```

**Pagination** - `?pagination=cursor` (or any `?cursor` token) selects cursor pagination; offset pagination is used
otherwise.

**Soft deletes** - `?trashed=with` or `?trashed=only`, read as a `TrashedState` from `ApiQuery::getTrashed()`. The
criteria layer applies it only where the model uses `SoftDeletes` and the resource opts in by overriding
`allowsTrashed()`; otherwise soft-deleted records stay hidden.

### Schema-Driven ApiResource

Extend `ApiResource` and declare a `schema()` method using the `Field`, `Relation`, `Count`, `Sum`, and `Average`
helpers. The compiled schema drives field resolution, guards and eager-load planning.

```php
use App\Models\User;
use SineMacula\ApiToolkit\Attributes\ForModel;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Count;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;
use SineMacula\ApiToolkit\Schema\Sum;

#[ForModel(User::class)]
class UserResource extends ApiResource
{
    const RESOURCE_TYPE = 'user';

    protected static array $default = ['id', 'name', 'email', 'created_at'];

    public static function schema(): array
    {
        return Field::set(
            Field::scalar('name')->filterable(Capability::EXACT)->sortable()->searchable(SearchStrategy::SUBSTRING),
            Field::scalar('email')->filterable(Capability::EXACT)->searchable(SearchStrategy::SUBSTRING),
            Field::timestamp('created_at')->sortable(),
            Field::compute('full_name', 'getFullName'),
            Relation::to('organization', OrganizationResource::class)->traversable(),
            Count::of('memberships'),
            Sum::of('orders', 'total'),
        );
    }
}
```

Only fields marked `filterable()` or `sortable()` can be queried, and `?search=` matches only fields marked
`searchable()`. Relations marked `traversable()` can be targeted by nested filters. `$default` lists the fields
returned when the client sends no `?fields`.

**Query capabilities** - `filterable()` takes the access path the column has, and the capability decides which
operators the column answers. Any other operator is refused with a `422`:

| Capability             | Declare it for                                                   | Operators the column answers                                              |
|------------------------|------------------------------------------------------------------|---------------------------------------------------------------------------|
| `Capability::EXACT`    | a keyed column read by equality - an id, a foreign key, an email | `$eq`, `$in`, `$null`, `$notNull`                                         |
| `Capability::ENUM`     | a small closed set, so the complement of one value stays bounded | `$eq`, `$in`, `$neq`, `$null`, `$notNull`                                 |
| `Capability::RANGE`    | an ordered column - a number, a date, a timestamp                | `$eq`, `$in`, `$gt`, `$ge`, `$lt`, `$le`, `$between`, `$null`, `$notNull` |
| `Capability::DOCUMENT` | a JSON column behind a containment index                         | `$contains`                                                               |
| `Capability::OPAQUE`   | a column with no access path the resource will vouch for         | `$eq`                                                                     |

Operators your application registers under new tokens are not gated by capability (see
[Filter-Operator Registry](#filter-operator-registry)).

**Model binding and discovery** - `#[ForModel(...)]` binds a resource to its model, and may be repeated to bind one
resource to several models. With `api-toolkit.resources.paths` left `null` (the default), resources are discovered at
boot from `app_path('Http/Resources')` and each module's `app_path('*/Http/Resources')`. Set `paths` to an array to
choose the roots, or to an empty array to disable discovery. An explicit `api-toolkit.resources.resource_map` entry
always wins over a discovered binding: use it to pick the canonical resource when a model has more than one, or to
bind a resource outside the scanned paths. When two discovered resources claim the same model and no explicit entry
resolves it, the first in sorted file order wins and a warning is logged; with `validate_schemas` enabled the boot
fails instead.

Without a compiled map the scan runs on every boot, which is fine for development. In production run
`php artisan optimize` (or `php artisan api-toolkit:resources:cache`) on every deploy. It compiles the map to
`bootstrap/cache/api-toolkit-resources.php`; `php artisan optimize:clear` (or `php artisan api-toolkit:resources:clear`)
removes it. While the file exists it is served as-is, so rebuild or clear it after adding, moving or re-binding a
resource, or after changing `paths`. These four commands boot without merging discovered resources, so a stale map
never blocks its own rebuild. With `validate_schemas` enabled the file is ignored and the scan runs on every boot.
Discovery never uses the cache store, so with `validate_schemas` disabled, boot and `route:cache` work without a
reachable store; schema validation reads its metadata through the store.

**Polymorphic aliases** - with `api-toolkit.resources.enable_dynamic_morph_mapping` on (the default), each
resource's `RESOURCE_TYPE` is registered as its model's morph alias. The aliases merge with your own
`Relation::morphMap()`, and yours always win: an alias you register, or a model you have already mapped, is left
alone whatever the boot order. An alias claimed by more than one model is registered for none of them. A morph map is
never required, so a model without a resource (a vendor model, say) keeps morphing to its class name; call
`Relation::requireMorphMap()` in your own service provider to make mapping strict. A later
`Relation::morphMap([...], merge: false)` replaces the whole map, derived aliases included. Polymorphic rows store the
alias, so renaming a `RESOURCE_TYPE`, or binding a second model to a resource (which withholds its alias), orphans
rows already stored under the old alias.

**Eager-load planning** - the resource builds `with()`/`withCount()`/`withSum()`/`withAvg()` maps from the resolved
field set:

```php
$with = UserResource::eagerLoadMapFor(UserResource::resolveFields());
```

**Field-set control** at instantiation:

```php
new UserResource($user, loadMissing: true);          // eager-loads missing relations
new UserResource($user, included: ['id', 'email']);   // explicit field set
new UserResource($user, excluded: ['email']);          // field set minus exclusions
(new UserResource($user))->withAll();                 // all schema fields
```

**Schema validation** - `api-toolkit.resources.validate_schemas` validates every registered schema at boot. It is on
by default outside production (`VALIDATE_SCHEMAS=false` turns it off) and off in production. The
`api-toolkit:validate-schemas` Artisan command runs the same validation regardless of the flag, so it can gate CI.

Validation checks that:

- every `filterable()` and `sortable()` declaration names a column that exists. A computed field, or an accessor
  reading a different path, is caught from the schema alone; the rest is checked against the table's column listing.
- an ordered index leads with every `sortable()` column, unless the field carries `indexed()` or `unindexed()`.
- an index on the connection serves every `searchable()` declaration in the shape its strategy needs.
- no declaration names a column configured as sensitive.

Checks that read the connection are skipped where it cannot be read, such as a boot with no database or before
migrations have run.

The index catalogue is read only during validation, never while a filter or sort is served. Two schema reads do sit
on the request path, both cached in the shared store: column narrowing (`api-toolkit.resources.narrow_columns`) reads
the table's column listing, and search reads its index proof.

### Repositories

Extend `ApiRepository` for a repository wired to the query parser, eager-load planning and pagination:

```php
use SineMacula\ApiToolkit\Repositories\ApiRepository;

class UserRepository extends ApiRepository
{
    public function model(): string
    {
        return User::class;
    }
}
```

`withApiCriteria()` applies the request's filters, sorts, eager loads and limit. It returns a copy and leaves the
repository it was called on untouched, so run the read through the returned value:

```php
$users = $repository->withApiCriteria()->paginate();
```

Where the composition is conditional, keep the handle:

```php
$scoped = $repository->withApiCriteria();

if ($caller->isExternal()) {
    $scoped = $scoped->scopeById($id);
}

$users = $scoped->paginate();
```

**Allowlist** - only schema fields declared `filterable()`, `sortable()`, or `traversable()` are accepted, and any
other key is rejected with a validation error. There is no opt-out: a resource that declares nothing is queryable by
nothing. Each filter is checked as a (column, operator) pair against the declared capability, before any operator
handler runs or any SQL is issued.

**Sensitive columns** - the columns in `api-toolkit.resources.sensitive_columns` may never be declared `filterable()`,
`sortable()`, or `searchable()`, and schema validation rejects a resource that does. The default list covers the
stock Laravel and Fortify auth columns.

**Index backing** - schema validation checks that an index leads with each `sortable()` column. The column must be
the leading key; being second in a composite index does not count. Where the connection reports index kinds, only an
ordered kind counts, so a full-text or trigram index does not make a column sortable. The leading key must also hold
the column's own order, so a MySQL prefix index (`name(20)`), or a PostgreSQL index keyed with a pattern operator
class (`text_pattern_ops`) or a non-default collation, does not count for sorting. A MySQL prefix key still backs an
exact or prefix search, and a PostgreSQL pattern-class key still backs an exact search, but a PostgreSQL key with a
different collation backs neither. A connection that cannot be inspected skips the check rather than failing it.

Two overrides cover what the catalogue cannot show:

- `indexed('users_lower_name_index')` names the index behind the column, such as an expression or partial index. The
  name must exist on the table; what it covers is not checked.
- `unindexed('the table is bounded at a few hundred rows')` records a deliberate exemption. The reason is required.

**Random ordering** - `?order=random` is disabled by default. Enable it with
`api-toolkit.repositories.allow_random_order` (`API_TOOLKIT_ALLOW_RANDOM_ORDER=true`); while disabled, `random` is
treated as an ordinary sort key and rejected like any undeclared column.

**Query cost caps** - `api-toolkit.query_cost` bounds the filter document's size and nesting, the keys and value-list
items it dispatches, the sort keys and relation aggregates requested, and how deep a request pages. A request over a
cap is rejected before any SQL is issued, with a `422` naming the parameter, the position within it, the cap, the
limit and the value supplied. Set a cap to `0` to disable it.

**Cacheable trait** - per-query caching for any `ApiRepository` subclass:

```php
use SineMacula\Repositories\Concerns\Cacheable;

class UserRepository extends ApiRepository
{
    use Cacheable;

    protected int $cacheTtl = 3600;
    protected ?string $cacheStoreName = null;   // uses app default
    protected bool $cacheReferenceTable = false; // whole-table reference mode
}
```

Reads are keyed by query fingerprint, and writes invalidate the table. Call `withoutCache()` to bypass the cache for
one read, or `flushCache()` to invalidate immediately.

With `$cacheReferenceTable = true`, the full table is loaded once and memoised in-process, and single-record lookups
never touch the database. Use it only for small lookup tables that rarely change.

**Deferrable trait** - buffer inserts in memory and flush them as bulk `INSERT` statements at the lifecycle boundary
(`RequestHandled`, `CommandFinished`, `JobProcessed`, or `JobFailed`):

```php
use SineMacula\ApiToolkit\Repositories\Concerns\Deferrable;

class AuditRepository extends ApiRepository
{
    use Deferrable;
}

$auditRepository->defer(['user_id' => 1, 'action' => 'login']);
```

`api-toolkit.deferred_writes.on_failure` picks what happens when a flush fails:

- `collect` (default) - failed records are kept for the next flush.
- `throw` - raises a `WritePoolFlushException`, for callers that flush explicitly.
- `log` - failed records are dropped. Use it only for disposable writes such as telemetry.

When a boundary flush fails under `collect` or `throw`, a `WritePoolFlushException` is reported through the exception
handler and a `WritePoolFlushFailed` event is dispatched. Under `log` the failure is logged as a warning instead, and
the event is still dispatched.

`WritePool` is a scoped singleton, so after a deferred write `app(WritePool::class)->lastAutoFlushResult()` returns the
outcome of the most recent automatic flush (`null` if none has run). Under `collect` and `log` this is the only
in-process signal that an auto-flush failed.

### Filter-Operator Registry

The `OperatorRegistry` singleton maps tokens to `FilterOperator` handlers. Register custom operators in a service
provider's `boot()` method:

```php
use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;
use SineMacula\ApiToolkit\Contracts\FilterOperator;

class AppServiceProvider extends ServiceProvider
{
    public function boot(OperatorRegistry $registry): void
    {
        $registry->register('$regex', new RegexOperator);

        // Override an existing operator
        $registry->override('$contains', new StrictContainsOperator);
    }
}
```

An operator is a class implementing `SineMacula\ApiToolkit\Contracts\FilterOperator`, or a closure with the same
signature. `register()` throws `InvalidArgumentException` if the token is already taken; `override()` replaces it
unconditionally.

An operator that expands one value into a predicate per item should also implement
`SineMacula\ApiToolkit\Contracts\ExpandsValueList` and return the item count from `countValueItems()`. That count is
checked against the `max_in_items` cap, so a delimited string is bounded the same as an array. An operator without
the contract counts as one item per non-list value.

The capability gate covers only the shipped tokens. A token registered under a new name is allowed on any declared
column, since the package cannot know which access path it needs. Overriding a shipped token keeps that token's
capability rules, so a replacement `$contains` is still served only on `DOCUMENT` columns. An unregistered token is
read as a column name and rejected by the allowlist.

### Exception Handling

Register the exception handler in `bootstrap/app.php`. This is required: it registers both the JSON rendering and the
reporting filter, and without it handled exceptions reach every reporter:

```php
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;

->withExceptions(function (Exceptions $exceptions): void {
    ApiExceptionHandler::handles($exceptions);
})
```

Exceptions are mapped to `ApiException` subclasses and rendered as JSON error responses with the matching HTTP status.
`api-toolkit.exceptions.render_strategy` controls when JSON is rendered:

- `'auto'` (default) - JSON unless the request does not expect JSON and debug mode is on.
- `'always_json'` - always JSON.
- `'json_when_expected'` - JSON only when the request expects it (Laravel's `expectsJson()`, e.g.
  `Accept: application/json`).

**Reporting** - an `ApiException` is a handled response and is never reported or logged, whatever reporters are
registered and in whatever order. This matches Laravel's treatment of its own HTTP exceptions; use the response
status for request-level error rates.

- A deliberately thrown 5xx such as `ServiceUnavailableException` is handled too, so report its underlying cause
  where you catch it.
- An `UnhandledException` thrown by hand is still reported.
- Laravel's `Illuminate\Validation\UnauthorizedException` (rendered as `403`) is not reported.
- An exception implementing Symfony's `HttpExceptionInterface` is not reported when its status is a recognised client
  error (one the handler renders as a `4xx`) or `419`, even when it does not extend Symfony's `HttpException`. An
  unrecognised status such as `499` renders as a generic `500` and is reported.
- `LockUnavailableException` (lock contention, rendered as `429`) is never reported, with or without `handles()`.

Other framework exceptions rendered as client errors, such as `ModelNotFoundException` (`404`) or
`ValidationException` (`422`), are kept out of reports by Laravel's own don't-report list.

An exception the handler does not map is unhandled. Laravel reports the original exception, with its own class and
trace and subject to its don't-report list, and the toolkit renders a generic `500` body, so with debug info disabled
its internals never reach the client.

The filter applies wherever Laravel reports, including queued jobs and console commands. It does not change retries,
failed-job storage or console output. Reporting is decided after any `$exceptions->map()` has been applied.

Request input is not attached to exception reports. Add the fields you want with your own `$exceptions->context()`
callback.

### Middleware

The service provider registers the following middleware. Each can be disabled in `api-toolkit.middleware`:

- **`maintenance_mode_swap`** - JSON `503` maintenance responses. URIs listed in `api-toolkit.maintenance_mode.except`
  stay reachable. `Retry-After` and `Refresh` from `php artisan down --retry` / `--refresh` are forwarded; the response
  is always `503`, so `--status` is ignored. Non-JSON requests that Laravel redirects (`--redirect`) or renders a
  template for (`--render`) are left alone.
- **`json_pretty_print`** - pretty-printed JSON when the request carries `?pretty=true`.
- **`throttle`** - API-friendly rate-limit responses; uses the Redis variant when Redis is the default cache store.

`json_pretty_print` takes `'scope' => 'global'` (default, pushed to the global stack) or `'scope' => 'api'` (appended
to the `api` middleware group only). The maintenance middleware replaces Laravel's in its existing position in the
global stack, after `HandleCors`, so maintenance responses still carry CORS headers (nothing is added if Laravel's has
been removed). `throttle` is registered as the router's `throttle` alias.

**Rate-limit keys** - each request is keyed by method, host, path and caller. Authenticated requests are keyed by
user identifier; guests by client IP (`$request->ip()`), as Laravel's stock `ThrottleRequests` does, so one guest
cannot exhaust the limit for every other guest.

Behind a proxy, load balancer, CDN or NAT, many callers can share one IP and be throttled together. Configure
Laravel's `TrustProxies` middleware so `$request->ip()` resolves the real client IP.

To key guests by something else, such as an API key, set `api-toolkit.middleware.throttle.class` to your own
middleware that uses `ThrottleRequestsTrait` and overrides `resolveRequestSignature()`.

### Schema Introspection and OpenAPI Export

The schema compiler resolves the filterable and sortable columns, traversable relations and field keys of any
registered resource without instantiating it. The database-schema introspector resolves model columns, their type and
nullability, and relations; `ApiCriteria` uses it internally and it can be injected:

```php
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;

public function __construct(private SchemaIntrospectionProvider $introspector) {}
```

An OpenAPI 3.1 document is generated from the resource map, the application's routes and the registered operators:

```bash
php artisan api-toolkit:export-openapi
php artisan api-toolkit:export-openapi --output=openapi.json
php artisan api-toolkit:export-openapi --audience=internal
php artisan api-toolkit:export-openapi --all
```

Without options the default audience is written to `api-toolkit.openapi.output`. `--audience` exports one named
audience. `--all` exports every configured audience, each to its own file with the audience inserted before the
extension, so `openapi.json` becomes `openapi.public.json` and `openapi.internal.json`.

Resources are optional: a route documented only through `#[RequestSchema]` and `#[ResponseSchema]` is exported with
an empty resource map. Operations are not. Every target audience is built before anything is written, and if any of
them has no operations (no route reaches it after the framework namespace exclusions and its own filters), the command
names each empty audience, writes nothing, and exits non-zero. Pass `--allow-empty` to write the documents anyway and
exit successfully:

```bash
php artisan api-toolkit:export-openapi --all --allow-empty
```

List the hosts the API is served from under `api-toolkit.openapi.servers`, so interactive viewers call the API rather
than the docs host and generated clients get a base URL. Each entry is an OpenAPI Server Object with a `url`, an
optional `description` and optional `variables`, exported in order. An audience may set its own `servers` to replace
the list, or an empty list to export none. With none configured the document has no `servers`. An invalid entry fails
the export and is named:

```php
'servers' => [
    ['url' => 'https://api.example.com', 'description' => 'Production'],
    ['url' => 'https://api.staging.example.com', 'description' => 'Staging'],
],
```

Each resource becomes a component schema, and each documented route an operation carrying the query parameters that
action honours. Every queryable property carries an `x-query-surface` extension naming the key to send, the filter
capability and the operators it answers, whether an index backs its sort (with the recorded reason where none does),
and its search strategy. The relations a filter may descend through are listed on the schema itself. All of this is
read from the same compiled schema the request-time checks use, and the operators from the bound registry, so the
document cannot offer a column the request would reject or an operator that is no longer registered. A column whose
operators have all been removed from the registry is documented as answering no filter.

Every operation also lists the errors it can return, derived from the route: `401` behind auth middleware, `403`
behind an authorization check (a `can:` gate, an `#[Authorize]` attribute, or an `AuthorizedController` action),
`404` when the path has parameters, `422` when the operation accepts a request body, `429` behind a rate limit, and
`500` always. Middleware is read as the router resolves it, so a guard or limit applied through a group counts and
one removed with `withoutMiddleware()` does not. Each `ApiException` subclass named in the action's `@throws` tags is
added under its status with its error code as an example; other throwables in those tags are ignored. Class names in
`@throws` resolve as PHP would in the file declaring the action, so imported, aliased, same-namespace and fully
qualified names all work, and a union such as `@throws A|B` names both.

Every enum the document references, through a model cast or a request field validated by `Rule::enum()` or
`PureEnum`, becomes one named component listing its values under `enum`: the backing values of a backed enum, or the
lowercase case names of a pure enum. Fields reference the component, except a field narrowed by `only()` or
`except()`, which lists its accepted values inline (an empty list when it accepts none).

A request field's presence rules shape its object's schema:

- A `confirmed` field documents its confirmation field (`<field>_confirmation`, or the one `confirmed:` names), required
  always when the field is required and through `dependentRequired` when it is optional.
- `required_with`, `required_with_all`, `required_without` and `required_without_all` are expressed with
  `dependentRequired`, `if`/`then` and `anyOf`. They reference only fields declared in the same object or array item:
  any other name is left out, and a `_all` rule naming one is dropped.
- A field with an `exclude` rule requires nothing, since the validator may skip it.
- A present but empty value, such as `null`, `""` or `[]`, counts as missing to these rules but as sent to the schema.

A route outside the resource pipeline documents its success body with `#[ResponseSchema]` on the action. Name a
registered resource to reference its component, or a class implementing `DefinesSchema` to inline the JSON Schema its
`schema()` returns; `collection: true` wraps the shape in the collection envelope. Name several classes to document a
response that may be any one of them, exported as a `oneOf` under `data` (or under each item of a collection). Give
each member a required property holding its own constant, such as a `status`, so clients can tell them apart. If any
named class is neither a registered resource nor a `DefinesSchema`, the body is left undocumented.

On a resource controller's `index`, `show`, `store`, `update` or `destroy`, declaring the controller's own resource
(as a collection on `index`, a single item elsewhere) changes nothing: the action keeps its full resource
documentation, including an `index`'s query parameters, both pagination shapes and the `Total-Count` header. Any other
declared body replaces the resource body. Such an action keeps its status, headers, request body, tag and error
responses, but lists none of the resource's query parameters:

```php
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseSchema;
use SineMacula\ApiToolkit\OpenApi\Contracts\DefinesSchema;

final class PendingSignIn implements DefinesSchema
{
    public static function schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'status'  => ['const' => 'pending'],
                'choices' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['status', 'choices'],
        ];
    }
}

#[ResponseSchema(UserResource::class)]
public function me(): JsonResponse {}

#[ResponseSchema(UserResource::class, collection: true)]
public function team(): JsonResponse {}

#[ResponseSchema([SignedIn::class, PendingSignIn::class])]
public function signIn(): JsonResponse {}
```

Document response headers with `#[ResponseHeader]`, repeated once per header:

- On an action, it adds the header to the success response.
- On a controller class, it adds it to the success response of every action the controller handles; an action's own
  declaration wins.
- On an `ApiException` subclass, it documents a header the exception may set, added to the error response of every
  action whose `@throws` tags name it. Subclasses inherit their parents' headers, and exceptions sharing a status
  contribute every header they declare, the first declaration of a name winning.

The schema defaults to a string and the description is optional. A header the export already emits, such as a
collection's `Total-Count`, cannot be declared again: the export fails. The toolkit's `TooManyRequestsException`
documents the `Retry-After` and `X-RateLimit-*` headers, and `MaintenanceModeException` the `Retry-After` and
`Refresh` headers:

```php
use SineMacula\ApiToolkit\Exceptions\ApiException;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseHeader;

#[ResponseSchema(SignedIn::class)]
#[ResponseHeader('Set-Cookie', 'The refresh token, HttpOnly, Secure, SameSite=Strict.')]
public function signIn(): JsonResponse {}

#[ResponseHeader('Retry-After', 'The number of seconds to wait before retrying.', ['type' => 'integer'])]
final class QuotaExceededException extends ApiException
{
    public const ErrorCodeInterface CODE = AppErrorCode::QUOTA_EXCEEDED;

    public const HttpStatus HTTP_STATUS = HttpStatus::TOO_MANY_REQUESTS;
}
```

The success status is derived from the action name: `201` for `store`, `204` with no body for `destroy`, and `200`
otherwise. Declare `#[ResponseStatus]` on an action that answers otherwise. It accepts success statuses only. `204`
and `205` have no body, so declaring a `#[ResponseSchema]` beside either fails the export. Any other status keeps the
body the action would otherwise document, and a `destroy` declaring one gains its `#[ResponseSchema]` body or the
undocumented envelope. Declared headers attach to the declared status:

```php
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseStatus;
use SineMacula\Http\Enums\HttpStatus;

#[ResponseStatus(HttpStatus::NO_CONTENT)]
public function update(ChangePasswordRequest $request): Response {}

#[ResponseSchema(ExportQueued::class)]
#[ResponseStatus(HttpStatus::ACCEPTED)]
public function export(): JsonResponse {}
```

`api-toolkit:docs:generate` writes the generated reference sections into the Markdown docs directory
(`api-toolkit.openapi.docs_path`) that is assembled into the document's description: the error catalogue, the enum
reference, and the query surface reference, which tables each resource's queryable columns alongside the request
bounds. The surface reference is written per audience, under `audiences/<audience>/`, so it only reaches documents
that already carry the resource's schema:

```bash
php artisan api-toolkit:docs:generate
```

### Metadata Invalidation

Schema metadata (column listings and definitions, index catalogues, cast maps, relation lookups and the
model-to-resource map) is cached in the application's cache store and shared by every process. The Octane and queue
boundary flushes reset only each worker's in-process state, so a schema or mapping change needs the shared entries
retired explicitly. Every metadata key is stored under a generation held in the same store; replacing the generation
makes every earlier entry unreachable in every process at once. A column listing that reads empty, as it does before
its table exists, is never stored.

Entries are stored as arrays and scalars, never objects, so the cache works under Laravel's default
`cache.serializable_classes => false`. An entry that does not read back in the expected shape is treated as a miss
and overwritten.

The metadata cache is optional. If a cache store operation throws, the failure is reported as a
`MetadataCacheUnavailableException` and the store is bypassed for `api-toolkit.cache.retry_after` seconds
(`API_TOOLKIT_CACHE_RETRY_AFTER`, default `30`); metadata is computed without the cache and the request is still
served. A write the store rejects is reported at most once a window but bypasses nothing, so the store keeps serving
its warm entries; the `null` driver's rejections are not reported. With `retry_after` set to `0`, every failed call is
retried and reported. The windows are per process, so an Octane or queue worker reports an outage once a window.
Under PHP-FPM nothing outlives a request, so an outage is reported once per request; throttle it with a lottery, since
a rate limit backed by the same unavailable store cannot:

```php
use Illuminate\Support\Lottery;
use SineMacula\ApiToolkit\Exceptions\MetadataCacheUnavailableException;

$exceptions->throttle(fn (Throwable $e) => $e instanceof MetadataCacheUnavailableException
    ? Lottery::odds(1, 100)
    : null);
```

The bypass limits how many store calls fail, not how long each takes, so give the cache's Redis connection a short
`timeout` and `read_timeout`.

Schema entries are keyed by the schema the connection actually reads (its name, database, table prefix, Postgres
search path, database user and configured server), so a tenancy switcher that repoints one connection name reads each
tenant's schema separately, provided it purges the connection when it switches. Casts, relation lookups and the
resource map come from code and are shared across tenants. Where each tenant has its own cache prefix, each has its
own generation, so invalidate per tenant.

The generation is replaced automatically when a migration run finishes. A run with nothing to migrate, or a
`--pretend` run, leaves the metadata warm. If the cache store cannot be written, the hook logs a warning instead of
failing the migration. Set `API_TOOLKIT_LIFECYCLE_MIGRATIONS=false` to turn the hook off.

The hook is not enough on its own: migrations run before the new release takes traffic, so workers still on the old
code can refill the new generation with their casts, resource mappings and relation lookups. Run this on every
deploy, after the new release is live:

```bash
php artisan api-toolkit:invalidate-metadata
```

It exits with a failure if the cache store rejects the new generation.

The build should run `php artisan optimize`, which compiles the resource map along with the framework's caches.
Reload PHP workers after a rebuild, since OPcache with `validate_timestamps=0` keeps serving the previous file.

Entries under a retired generation are no longer read but stay in the store until it evicts or clears them, so each
invalidation leaves at most one stale copy behind.

### Services

A service is generic over its input and output. Declare both with `@extends Service<TInput, TOutput>` and
`$this->input` is typed as `TInput` in every hook. The input is readonly, so a subclass with its own constructor
passes it to `parent::__construct($input)` rather than redeclaring or assigning `$input`.

```php
/**
 * @extends Service<SignInPayload, User>
 */
final class SignIn extends Service
{
    protected function handle(): mixed
    {
        return User::query()->where('email', $this->input->email)->firstOrFail();
    }
}

$user = SignIn::make(SignInPayload::from($request))->runOrFail();
```

#### Running a Service

`runOrFail()` returns the output (typed as `TOutput`) or throws, and is the usual choice in a controller: the failure
reaches the application's exception handler. A failed result with no captured exception throws a
`ServiceFailedException`, so a failure never comes back as output.

`run()` never throws for a business failure. It returns a `ServiceResult` for callers that branch on the outcome:

```php
$result = SignIn::make(SignInPayload::from($request))->run();

if ($result->failed()) {
    // $result->exception is the captured failure, or null when none was thrown
    return response()->json(['message' => 'The credentials are invalid.'], 401);
}

$user = $result->output();
```

`runOrFail()` is `run()->throw()->output()`. Both use the context given through `withContext()` and fire the same
lifecycle events.

`dispatch()` queues the service, and the worker runs it with a `QUEUE` context. A failed run fails the job with the
captured exception, so the worker retries it per `--tries` and `--backoff` and finally records it in the failed job
store. Each attempt runs the whole lifecycle, firing `onFailure()` and `ServiceFailed` every time it fails. On the
`sync` connection there is no worker to retry, so the failure is thrown to the dispatching code.

#### Reading the Run Context

During a run, `$this->context()` returns the `ServiceContext` in use: the one given through `withContext()`, the
`INTERNAL` context `run()` builds when none was given, or the `QUEUE` context rebuilt on the worker. Every hook,
`concerns()` and `lockId()` see the same instance, and the `ServiceCompleted` and `ServiceFailed` events report it.
During a run `actor()` returns the context's actor. Calling `context()` outside a run throws a `LogicException`.

At an HTTP edge, `ServiceContext::fromRequest()` builds an `HTTP` context, so the service can read request details
without seeing the request:

```php
$order = PlaceOrder::make(OrderPayload::from($request))
    ->withContext(ServiceContext::fromRequest($request, EloquentActor::for($request->user())))
    ->runOrFail();

// Inside PlaceOrder
$ip = $this->context()->metadata[ServiceContext::METADATA_IP];
```

The client IP is stored under `ServiceContext::METADATA_IP` and the user agent under
`ServiceContext::METADATA_USER_AGENT`; both keys are always present and either may be `null`. Metadata passed as the
third argument is merged over them. The correlation id is read from the `X-Request-Id` header when it holds 1 to 128
printable ASCII characters with no whitespace, and generated otherwise. Set `api-toolkit.services.correlation_header`
(`API_TOOLKIT_SERVICES_CORRELATION_HEADER`) to read another header, or to `null` to always generate one.

The context is only bound during the run, so capture it for work that runs later:

```php
protected function afterCommit(mixed $output): void
{
    $context = $this->context();

    defer(static fn () => logger()->info('Order placed.', ['correlation_id' => $context->correlationId]));
}
```

The context is held on the service instance, so don't run one instance concurrently. Build a fresh instance per call,
as `make()` does.

#### Input Summaries

`ServiceCompleted` and `ServiceFailed` carry the input's `toArray()` as `inputSummary`, unchanged, so anything an
audit log or error tracker writes from it is only as safe as that array.

A `Payload` builds the summary from its public promoted properties. Mark a constructor parameter with PHP's
`#[\SensitiveParameter]` to leave it out; the attribute also hides the value from stack traces:

```php
final class SignInPayload extends Payload
{
    public function __construct(
        public readonly string $email,
        #[\SensitiveParameter]
        public readonly string $password,
    ) {}
}

SignInPayload::from(['email' => 'a@example.com', 'password' => 'secret'])->toArray();
// ['email' => 'a@example.com']
```

The key is omitted rather than masked, and the value stays readable on the instance. A payload that overrides
`toArray()` controls its summary entirely. Any other `ServiceInput` must keep secrets out of its own `toArray()`; an
`ArrayInput` returns its array as given, so leave secrets out of the array it wraps.

Body keys map to properties by their camelCase form: a `client_name` rule hydrates `$clientName`, and `toArray()`
returns it as `client_name`, matching the body and the request schema. A property named exactly after a rule key takes
precedence, and a property no rule maps keeps its own name. The map is read from the `rules()` keys once per class, so
those keys must be static:

```php
final class StoreClientPayload extends Payload
{
    public function __construct(
        public readonly string $clientName,
    ) {}

    public static function rules(): array
    {
        return ['client_name' => ['required', 'string']];
    }
}

StoreClientPayload::from(['client_name' => 'Acme'])->clientName; // 'Acme'
StoreClientPayload::from(['client_name' => 'Acme'])->toArray();  // ['client_name' => 'Acme']
```

#### Enum Input

A promoted property typed as an enum is cast from the body. A backed enum is read from its value; validate it with
Laravel's `Rule::enum()`. A pure enum has no value, so it is read and written by its case name in lowercase, matched
exactly (`CONSOLE` and `Console` are refused). Validate it with `PureEnum`, which accepts only those names, supports
`only()` and `except()` like `Rule::enum()`, and fails with the same `validation.enum` message:

```php
use SineMacula\ApiToolkit\Validation\Rules\PureEnum;

enum Application
{
    case PLATFORM;
    case CONSOLE;
    case WORKFORCE;
}

final class StartSessionPayload extends Payload
{
    public function __construct(
        public readonly Application $application,
    ) {}

    public static function rules(): array
    {
        return ['application' => ['required', (new PureEnum(Application::class))->except(Application::WORKFORCE)]];
    }
}

StartSessionPayload::from(['application' => 'console'])->application; // Application::CONSOLE
```

A backed enum is read only from its canonical form: a string for a string-backed enum, and an integer or its plain
decimal string for an int-backed one. So `"3"` and `"-3"` are read, while `"03"`, `"+3"` and `"3.0"` are refused even
where `Rule::enum()` passes them. A value that matches no case fails as a `422` keyed by its body key, even when
`rules()` leaves the enum rule out. A resource field holding a pure enum case is serialised as the same lowercase
name, so it can be sent back unchanged. An enum whose case names collide once lowercased, such as `case Web` and
`case WEB`, throws a `LogicException`.

A model attribute cast to a pure enum also uses the lowercase name in `filters` and in repository writes, though
Eloquent stores the declared case name. `{"platform": {"$in": ["web", "mobile_app"]}}` matches rows storing `WEB` and
`MOBILE_APP`, on the root model or through a relation, and any other spelling, `MOBILE_APP` included, matches nothing.
`persist()` accepts the lowercase name or the case itself, and throws a `ValueError` for a string that names no case.

### Notification Audit Log

When `illuminate/notifications` is installed and `api-toolkit.notifications.enable_logging` is `true` (the default),
every `NotificationSending` event is logged at `debug` and every `NotificationSent` event at `info`. Each line records
the notification class, the notifiable type, the notifiable id (when the notifiable is an Eloquent model with a key)
and the channel. List classes in `api-toolkit.notifications.excluded_classes` to skip them.

Lines go to the application's default log channel, so whether the `debug` lines are kept depends on that channel's
level. For a dedicated destination, define a channel in `config/logging.php`:

```php
'channels' => [
    'notifications' => [
        'driver' => 'daily',
        'path'   => storage_path('logs/notifications.log'),
        'level'  => 'debug',
    ],
],
```

Then point `api-toolkit.notifications.log_channel` at it:

```dotenv
NOTIFICATION_LOG_CHANNEL=notifications
```

## Requirements

- PHP ^8.3
- Laravel 13.2+
- MySQL 8.0+, where the search layer is used

## Testing

```bash
composer test
composer test:coverage
composer check
composer format
composer smells
```

## Upgrading

See [UPGRADE.md](UPGRADE.md) for the breaking changes and migration steps from 1.x to 2.x.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Security

Report vulnerabilities privately; see [SECURITY.md](SECURITY.md).

## License

Licensed under the [Apache License, Version 2.0](https://www.apache.org/licenses/LICENSE-2.0).
