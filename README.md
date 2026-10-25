<h1 align="center">Laravel API Toolkit</h1>

<div align="center">

[![Latest Stable Version](https://img.shields.io/packagist/v/sinemacula/laravel-api-toolkit.svg)](https://packagist.org/packages/sinemacula/laravel-api-toolkit)
[![Build Status](https://github.com/sinemacula/laravel-api-toolkit/actions/workflows/tests.yml/badge.svg?branch=master)](https://github.com/sinemacula/laravel-api-toolkit/actions/workflows/tests.yml)
[![Quality Gates](https://github.com/sinemacula/laravel-api-toolkit/actions/workflows/quality-gates.yml/badge.svg?branch=master)](https://github.com/sinemacula/laravel-api-toolkit/actions/workflows/quality-gates.yml)
[![Maintainability](https://qlty.sh/gh/sinemacula/projects/laravel-api-toolkit/maintainability.svg)](https://qlty.sh/gh/sinemacula/projects/laravel-api-toolkit)
[![Code Coverage](https://qlty.sh/gh/sinemacula/projects/laravel-api-toolkit/coverage.svg)](https://qlty.sh/gh/sinemacula/projects/laravel-api-toolkit)
[![Total Downloads](https://img.shields.io/packagist/dt/sinemacula/laravel-api-toolkit.svg)](https://packagist.org/packages/sinemacula/laravel-api-toolkit)

</div>

The Laravel API Toolkit is a comprehensive package designed to simplify the development of RESTful APIs in Laravel. It
provides tools to enhance API functionality, improve error handling, and ensure consistent data output, making API
development faster and more reliable.

## Features

- **Exception Handling**: Implements a custom exception handler that captures and formats all exceptions for consistent
  API error responses, preserving the intended HTTP status codes.
- **Queryable Resources**: Resource schemas give fine-tuned control over which fields, filters, relations, and
  orderings are exposed via your API endpoints under a fail-closed allowlist posture, enhancing security and
  customization.
- **Data Repositories**: Abstracts database interactions into repositories to promote a cleaner and more maintainable
  codebase, with safe-by-default deferred writes (failed flushes retain records rather than dropping them) and per-query
  caching (each query is cached against its own fingerprint, so a cache hit performs zero database queries and a filtered
  read never returns the full table).
- **Data Resources**: Schema-driven resources ensure consistent presentation of data across different API endpoints,
  simplifying client-side data integration.
- **Services**: A composable service layer with immutable configuration, cross-cutting concerns (transactions,
  locking), and self-describing results.

## Installation

To install the Laravel API Toolkit, run the following command in your project directory:

```bash
composer require sinemacula/laravel-api-toolkit
```

## Configuration

After installation, publish the package configuration to customize it according to your needs:

```bash
php artisan vendor:publish --provider="SineMacula\ApiToolkit\ApiServiceProvider" --tag=config
```

This publishes `config/api-toolkit.php` to your application's config directory. The file is documented inline
and covers exception rendering strategy, sensitive columns, query parser limits, deferred write behaviour,
middleware toggles, the notification audit log destination, and more. Per-query repository caching is configured
in the `sinemacula/laravel-repositories` package under `repositories.cache`.

## Usage

### API Query Parser

The `ApiQueryParser` sits behind the `ApiQuery` facade and is populated automatically by the `ParseApiQuery`
middleware, which the service provider registers globally by default (controlled via `api-toolkit.parser.register_middleware`).

**Sparse fieldsets** - request only the fields you need for a given resource type:

```http
GET /users?fields[user]=id,name,email
```

```php
use SineMacula\ApiToolkit\Facades\ApiQuery;

$fields = ApiQuery::getFields('user'); // ['id', 'name', 'email']
```

**Filtering** - apply column-level filters by passing a URL-encoded JSON object of operator tokens:

```http
GET /users?filters={"status":{"$eq":"active"},"created_at":{"$ge":"2024-01-01"}}
```

The `filters` value must be a JSON string; requests carrying a non-JSON value are rejected with a validation
error.

Available built-in operator tokens: `$eq`, `$neq`, `$gt`, `$lt`, `$ge`, `$le`, `$in`, `$between`,
`$contains`, `$null`, `$notNull`. Each is accepted only on a column whose declared capability answers it,
so a request pairing an operator with a column that cannot serve it is rejected with a 422 naming both and
listing the operators that column does accept. The bare shorthand `{"name":"Alice"}` is the `$eq` operator
written without its token, and is held to the same declaration.

**Free-text search** - match a term against the fields a resource declares searchable:

```http
GET /users?search=smith
```

The term is matched against the columns of the requested resource only; it never traverses a relation,
because a text predicate inside a relation subquery is paid once per candidate row. Each searchable field
declares the match shape it is served with - an exact match, a prefix match, or an anywhere-match - and
the connection's registered search driver refuses a shape it cannot serve from an index rather than
scanning the table. Terms are bounded by `api-toolkit.search`; one outside the bounds is rejected with a
422 rather than trimmed to fit.

The shortest word accepted is three characters, and configuration may raise that floor but never lower it.
It is measured rather than chosen: below three, MySQL matches nothing at all once the word is shorter than
the index token size, and PostgreSQL answers correctly but by reading the whole table. The floor is applied
to every word rather than to the term as a whole, because a word beneath it is dropped from a full-text
phrase - widening the match - while a pattern comparison keeps it, so the two engines would answer the same
request with different rows. Both failures are invisible in the response, which is the hazard the parameter
exists to close.

Drivers ship for MySQL, PostgreSQL, and SQLite, registered against the names those connections report. On
MySQL the proof asks whether the planner would actually use the index it found, which the catalogue reports
only from 8.0; below that the question cannot be asked and the proof stays as lenient as it was.
MariaDB reports its own name and has no n-gram parser, so it is not among them and a search on such a
connection fails until you register a driver for it yourself. The index each declaration is served from
belongs to your own migration:

- On MySQL, the columns declared for an anywhere-match are matched together through a single `MATCH`, which
  needs one `FULLTEXT` index over exactly that column list, created `WITH PARSER ngram`. A full-text match
  OR-ed with any other predicate loses the full-text access path and reads the whole table, so an
  anywhere-match may not be declared beside another strategy on this connection; the driver refuses that
  combination rather than serving it as a scan.
- On PostgreSQL, a prefix match and an anywhere-match each need a `gin_trgm_ops` index over their own
  column, and the `pg_trgm` extension installed. Several columns may be declared under different
  strategies, because the planner combines the index scans behind a disjunction.
- An exact match needs an ordinary index leading with the column on either engine. On PostgreSQL that B-tree
  must carry the column's own collation, because the planner never answers a comparison from a key collated
  otherwise.

The proof is read twice. `php artisan api-toolkit:validate-schemas` reports a declaration with no index behind
it, which is the cheapest place to find one - run it in CI. Because schema validation is disabled in
production by default, the same proof is also taken on the request path, so a missing index refuses the
request instead of quietly reading the table: on PostgreSQL a missing trigram index is not an error at all,
and the search would otherwise return the right rows out of a sequential scan for as long as the index stayed
missing.

That request-time answer is shared through the cache store for `api-toolkit.search.index_proof_ttl` seconds
(`API_TOOLKIT_SEARCH_INDEX_PROOF_TTL`, 60 by default), keyed by the connection's schema and the declaration,
so every request, job, and worker reading the same schema pays the catalogue reads once per expiry. The expiry
is fixed from the first read. An index a migration creates or drops is proved by the next search, because the
migration hook retires every shared answer; one created, dropped, or made unusable outside a migration, or by
a migration while that hook is switched off or failed to write the cache, is reflected once the expiry lapses,
or at once after `php artisan api-toolkit:invalidate-metadata`. Setting the
expiry to `0` keeps each answer for one request or job only. Within one operation the answer is also held in
process, so a single long-running job may keep it past the expiry.

The only way to switch that request-time proof off is `api-toolkit.search.unverified_connections`, which
lists the connections a driver may serve a search on without proving an index behind it. It ships empty, so
nothing is waived until an application names a connection. SQLite carries neither index kind and can never
prove a search is index-backed, so a development connection on it has to be named here before a searchable
field will be served:

```php
'search' => [
    'unverified_connections' => ['sqlite'],
],
```

Because `validate_schemas` is enabled by default outside production, an unnamed connection fails the
application boot rather than the search request alone: every route and every Artisan command, including
`php artisan migrate` on a fresh checkout with no database file yet. Name the connection, or set
`VALIDATE_SCHEMAS=false` while you do.

The list is read by connection name, as `config/database.php` keys it, and not by the engine behind the
connection, so an application naming its connections for itself waives one of them and leaves its siblings
on the same engine refusing an unprovable declaration. That is worth being plain about rather than reading
as a safety feature: a stock application names each connection after its engine, so writing `mysql` there
still waives the connection named `mysql`, which is usually the one serving production. Nothing stands
behind this list - a connection on it serves a search that may read the whole table on every request. Name
a development connection, never one that serves traffic.

**Sorting** - sort by one or more columns, with optional direction:

```http
GET /users?order=last_name,first_name:desc
```

**Page-size ceiling** - a client-supplied `?limit` above `api-toolkit.parser.max_limit` (default 100) is
rejected with a `422` naming the ceiling and the size asked for, rather than reduced to the ceiling. A page
quietly shortened cannot be told apart from the end of the result set. Set the ceiling to `0` to disable it:

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

Cursor-based pagination is enabled by adding `?pagination=cursor` (or including a `?cursor` token); offset
pagination is used otherwise.

---

### Schema-Driven ApiResource

Extend `ApiResource` and declare a `schema()` method using the `Field`, `Relation`, `Count`, `Sum`, and `Average`
schema helpers. The compiled schema drives field resolution, guard evaluation, and eager-load planning automatically.

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

Fields marked `filterable()` or `sortable()` are the only ones a client may query, and a field marked
`searchable()` is the only one the `?search=` term is matched against - the strategy it is given decides both
how the value is matched and which index has to back the column. Relations marked `traversable()` can be
targeted by nested filters. The `$default` static property declares the fields returned when the client sends
no `?fields` parameter.

**Query capabilities** - `filterable()` takes the access path the column is declared to have, and that
capability decides which filter operators the column answers. An operator outside its row is refused with a
`422` rather than served by a scan:

| Capability             | Declare it for                                                   | Operators the column answers                                              |
|------------------------|------------------------------------------------------------------|---------------------------------------------------------------------------|
| `Capability::EXACT`    | a keyed column read by equality - an id, a foreign key, an email | `$eq`, `$in`, `$null`, `$notNull`                                         |
| `Capability::ENUM`     | a small closed set, so the complement of one value stays bounded | `$eq`, `$in`, `$neq`, `$null`, `$notNull`                                 |
| `Capability::RANGE`    | an ordered column - a number, a date, a timestamp                | `$eq`, `$in`, `$gt`, `$ge`, `$lt`, `$le`, `$between`, `$null`, `$notNull` |
| `Capability::DOCUMENT` | a JSON column behind a containment index                         | `$contains`                                                               |
| `Capability::OPAQUE`   | a column with no access path the resource will vouch for         | `$eq`                                                                     |

`$neq` reaches only the closed set, because the complement of one value spans nearly the whole index
everywhere else; `$contains` reaches only the document, whose column is the only one an inverted index backs;
and the nullity pair travels with every case carrying a B-tree, since both halves read one contiguous
partition of it. A token no row names was registered by your own application against the operator registry,
and the pairing is left to the application that made both halves of it.

**Model binding and discovery** - the `#[ForModel(...)]` attribute binds a resource to its model, and may be
repeated to bind one resource to several models. By default (`api-toolkit.resources.paths` left null) resources
are discovered at boot from the application's own `Http/Resources` directory plus each module's, resolved from
`app_path()` so a modular application is covered with no configuration, and compiled into the model-to-resource
map automatically so no central map needs maintaining. Set `paths` to an explicit array to override the scanned
roots, or an empty array to disable discovery. An explicit `api-toolkit.resources.resource_map` entry always
wins over a discovered binding - use it as the canonical-resource tiebreak when a model has more than one
resource, or to bind resources living outside the scanned paths. When two discovered resources claim the same model and no
explicit entry resolves it, the first (in sorted file order) wins and a warning is logged; with
`validate_schemas` enabled the conflict fails the boot instead.

Without a compiled map the scan runs on every runtime boot, which is meant for development. In production, run
`php artisan optimize` (or `php artisan api-toolkit:resources:cache`) on every deploy: it compiles the map to
`bootstrap/cache/api-toolkit-resources.php`, and `php artisan optimize:clear` (or
`php artisan api-toolkit:resources:clear`) removes it. While the file exists it is served as-is, so rebuild or
clear it after adding, moving, or re-binding a resource, or after changing `paths` (including to an empty
array). These four commands boot without merging discovered resources, so a stale map naming a resource that
no longer exists never blocks its own rebuild or removal. With `validate_schemas` enabled the file is ignored
and the scan runs on every boot, so every diagnostic fires. Discovery never uses the cache store, so with
`validate_schemas` disabled boot and `route:cache` work without a reachable store; schema validation reads its
metadata through the store.

**Eager-load planning** - the resource builds `with()`/`withCount()`/`withSum()`/`withAvg()` maps from the
resolved field set, so relations are loaded precisely and automatically:

```php
// Build a with() map for the active field set
$with = UserResource::eagerLoadMapFor(UserResource::resolveFields());
```

**Field-set control** at instantiation:

```php
new UserResource($user, loadMissing: true);          // eager-loads missing relations
new UserResource($user, included: ['id', 'email']);   // explicit field set
new UserResource($user, excluded: ['email']);          // field set minus exclusions
(new UserResource($user))->withAll();                 // all schema fields
```

**Schema validation** - `api-toolkit.resources.validate_schemas` has all registered schemas validated
during application boot. It defaults to enabled outside production (set `VALIDATE_SCHEMAS=false` to opt
out) and off in production, where the boot cost is not worth paying. The `api-toolkit:validate-schemas`
Artisan command runs the same validation on demand - independently of the flag - so it can also gate CI.

What validation proves about the query surface:

- every `filterable()` and `sortable()` declaration names a column that exists. A computed field and an
  accessor reading a different path are answered from the schema alone; the rest is answered by the table's
  own column listing.
- an ordered index leads with every `sortable()` column, unless the field carries an `indexed()` or
  `unindexed()` override.
- an index the connection carries serves every `searchable()` declaration, in the shape the declared
  strategy needs.
- no declaration names a column configured as sensitive.

The checks that read the connection stay silent where it cannot be read - a boot with no database behind it,
or one whose migrations have not run, proves nothing either way rather than failing.

Reading the index catalogue belongs to validation: no filter and no sort asks the connection about an index
while a request is served. Two reads of schema metadata do sit on the request path, both held in the shared
cache store after the first: column narrowing intersects its projection with the table's column listing, and
the search surface takes its index proof, which is shared for a short expiry rather than kept until the schema
changes (see the search section above).

---

### Repositories

Extend `ApiRepository` to get a repository wired to the API query parser, eager-load planning, and
pagination out of the box:

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

Compose `withApiCriteria()` into a read to apply the parsed filters, sorts, eager loads, and limit from the
current request automatically. It returns a copy carrying the composition and leaves the repository it was
called on untouched, so the read has to run through the value it returns:

```php
$users = $repository->withApiCriteria()->paginate();
```

Where the composition is conditional, keep the handle rather than calling and discarding:

```php
$scoped = $repository->withApiCriteria();

if ($caller->isExternal()) {
    $scoped = $scoped->scopeById($id);
}

$users = $scoped->paginate();
```

**Allowlist posture** - only schema fields declared `filterable()`, `sortable()`, or `traversable()` are
accepted, and every undeclared key is rejected with a validation error. There is no opt-out: a resource that
declares nothing is queryable by nothing. A filterable declaration also carries the capability the column is
queried through, so the surface records not just which columns may be filtered but how, and each filter is
gated as a (column, operator) pair rather than as a column alone. The refusal is decided from the compiled
declaration before the operator handler is resolved, so no statement is issued for a query that will be
refused.

**Sensitive columns** - the columns listed in `api-toolkit.resources.sensitive_columns` may never be declared
`filterable()`, `sortable()`, or `searchable()`. Schema validation refuses a resource that declares one, so a
credential or verification column cannot become an oracle a client narrows on without ever reading the value.
The default covers the stock Laravel and Fortify auth column family.

**Index backing** - a `sortable()` declaration offers to order the whole table by that column on request, so
schema validation asks the connection whether an index leads with it. Leading is the whole test: a column named
second in a composite index is covered by that index and still cannot be ordered by on its own, so checking
mere membership would pass exactly the declaration the database cannot serve. Where the connection names index
kinds, only a kind that holds an order counts, so a full-text or trigram index over a column does not make it
sortable. The leading key must also hold the column's own order: a MySQL prefix index (`name(20)`), or a
PostgreSQL index keyed with a pattern operator class (`text_pattern_ops`) or a collation other than the column's,
is passed over for sorting. A MySQL prefix key still backs an exact or prefix search, and a PostgreSQL
pattern-class key still backs an exact search, but a PostgreSQL key collated apart from its column backs neither
a sort nor a search. `indexed()` still vouches for any of them. A connection that cannot be inspected at all
reports nothing rather than reporting nothing found, so booting with no database behind the application skips
the check instead of failing it. The catalogue is read during validation and never while a sort is served.

Two narrow overrides exist for what reading the catalogue cannot show. `indexed('users_lower_name_index')`
names the index behind the column - an expression or partial index, say. The name is looked up on the
connection, so naming an index the table does not carry is itself a defect; what that index covers is not read
back, since the catalogue cannot describe the expression behind it, so the override vouches for the column
rather than proving it. `unindexed('the table is bounded at a few hundred rows')` records a deliberate
exemption, and the reason is required so an exemption is never silent.

**Random ordering** - `?order=random` is disabled by default because it sorts the whole table to return one
page. Enable it with `api-toolkit.repositories.allow_random_order` (`API_TOOLKIT_ALLOW_RANDOM_ORDER=true`);
while it is disabled the keyword is treated as an ordinary sort key and rejected like any undeclared column.

**Query cost caps** - a request whose parts are each cheap and each declared can still multiply into an
expensive query. The `api-toolkit.query_cost` caps bound the filter document's size and nesting, the keys and
value-list items it dispatches, the sort keys and relation aggregates it asks for, and how deep it pages. A
request over a cap is rejected before any SQL is issued, with a `422` naming the parameter, the position
within it, the cap, the limit, and the value supplied, so the client can correct the query itself. Every cap
is tunable, and setting one to `0` disables it.

**Cacheable trait** - add per-query transparent caching to any `ApiRepository` subclass:

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

Read results are keyed by query fingerprint; write operations invalidate the table automatically. Call
`withoutCache()` to bypass the cache for a single read, or `flushCache()` to invalidate immediately.

**ReferenceCache** is enabled by setting `protected bool $cacheReferenceTable = true` on a `Cacheable`
repository. In reference mode the full table is loaded once and memoised in-process; single-record lookups
resolve in O(1) without touching the database. Use this only for small, rarely-changing lookup tables.

**Deferrable trait** - buffer insert operations in memory and flush them as bulk `INSERT` statements at the
lifecycle boundary (`RequestHandled`, `CommandFinished`, `JobProcessed`, or `JobFailed`):

```php
use SineMacula\ApiToolkit\Repositories\Concerns\Deferrable;

class AuditRepository extends ApiRepository
{
    use Deferrable;
}

// In your service:
$auditRepository->defer(['user_id' => 1, 'action' => 'login']);
```

The default `on_failure = 'collect'` strategy retains failed records for the next boundary flush rather than
dropping them. The `'throw'` strategy raises a `WritePoolFlushException` for callers that own an explicit flush
site. The `'log'` strategy is best-effort only - use it solely for genuinely disposable writes such as
telemetry.

When a boundary flush fails under `'collect'` or `'throw'`, the failure is reported through the exception handler
as a `WritePoolFlushException` and a `WritePoolFlushFailed` event is dispatched. Under `'log'` the failure is
written as a warning rather than reported, and the event is still dispatched.

The `WritePool` is a scoped singleton, so after a deferred write you can call
`app(WritePool::class)->lastAutoFlushResult()` to observe the outcome of the most recent automatic flush
(`null` if none has occurred). Under the non-throwing `'collect'` and `'log'` strategies this accessor is the
only in-process signal that an auto-flush failed.

---

### Filter-Operator Registry

The `OperatorRegistry` singleton maps token strings to `FilterOperator` handler instances. Register custom
operators in a service provider's `boot()` method:

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

A `FilterOperator` is any class implementing `SineMacula\ApiToolkit\Contracts\FilterOperator`, or a closure
with the same signature. Operators registered via `register()` throw `InvalidArgumentException` if the token
is already taken; use `override()` to replace unconditionally.

An operator that fans a single value out into one predicate per item should also implement
`SineMacula\ApiToolkit\Contracts\ExpandsValueList` and report that item count from `countValueItems()`. The
dispatcher measures the reported count against the `max_in_items` cap, so a list spelled as a delimited
string is bounded the same way as one spelled as an array. An operator that does not implement the contract
is measured as one item per non-list value.

The capability matrix governs the operators the package ships and whose SQL it wrote, so a token registered
under a name none of them uses is not held to it and applies on any declared column. The package cannot say
which access path such a token needs, and refusing it would leave `register()` an extension point able to
produce only tokens every column rejects. A token that no one registered is not an operator at all: it is
read as a column name and rejected by the allowlist. Overriding a shipped token keeps that token's place in
the matrix, so replacing the `$contains` handler leaves it served from document columns alone.

---

### Exception Handling

Register the exception handler once in `bootstrap/app.php`. The call is required: it registers both the JSON
rendering and the reporting filter, and without it handled exceptions reach every reporter:

```php
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;

->withExceptions(function (Exceptions $exceptions): void {
    ApiExceptionHandler::handles($exceptions);
})
```

All exceptions are mapped to typed `ApiException` subclasses and rendered as consistent JSON error responses
with appropriate HTTP status codes. The rendering strategy is configurable:

- `'auto'` (default) - renders JSON unless the request does not expect JSON and debug mode is on.
- `'always_json'` - always renders JSON.
- `'json_when_expected'` - renders JSON only when the request expects a JSON response (Laravel's
  `expectsJson()`, e.g. `Accept: application/json`).

**Handled and unhandled exceptions** - an `ApiException` is a deliberate, handled response and is never
reported or logged, whatever reporters (error trackers included) are registered and in whatever order. This
matches Laravel's treatment of its own HTTP exceptions; the response status remains the signal for
request-level error rates.

- A deliberately thrown 5xx such as `ServiceUnavailableException` is handled too, so report its underlying
  cause where you catch it.
- An `UnhandledException` thrown by hand is still reported.
- Laravel's `Illuminate\Validation\UnauthorizedException` (rendered as `403`) is not reported.
- An exception implementing Symfony's `HttpExceptionInterface` is not reported when its status is a
  recognised client error (one the handler renders as a `4xx`) or `419`, even when it does not extend
  Symfony's `HttpException`. An unrecognised status such as `499` renders as a generic `500` and is reported.
- `LockUnavailableException` (lock contention, rendered as `429`) is never reported, with or without
  `handles()`.

Other framework exceptions the handler renders as client errors, such as `ModelNotFoundException` (`404`) or
`ValidationException` (`422`), are kept out of reports by Laravel's own don't-report list.

An exception the handler does not map is unhandled. Laravel reports the original exception, with its own class
and trace and subject to Laravel's own don't-report list, before the toolkit renders a generic `500` body, so
with debug info disabled its internals never reach the client.

The filter applies wherever Laravel reports, queued jobs and console commands included. It does not change
retries, failed-job storage or console output. Reporting is decided on the exception after any
`$exceptions->map()` has been applied.

The toolkit does not attach request input to exception reports. Add the fields you want with your own
`$exceptions->context()` callback.

---

### Middleware

The service provider registers the following middleware automatically. Each registration can be disabled
independently in `api-toolkit.middleware`:

- **`maintenance_mode_swap`** - JSON `503` maintenance responses with an `except` URI allowlist. `Retry-After` and
  `Refresh` from `php artisan down --retry` / `--refresh` are forwarded; the JSON response is always `503`, so
  `--status` is not honoured. Non-JSON requests that Laravel redirects (`--redirect`) or renders a template for
  (`--render`) are left untouched.
- **`json_pretty_print`** - opt-in pretty-printed JSON responses via a query parameter.
- **`throttle`** - API-friendly rate-limit responses; auto-selects the Redis variant when Redis is the cache driver.

`json_pretty_print` accepts `'scope': 'global'` (default, pushed to the global stack) or `'scope': 'api'`
(appended to the `api` middleware group only). Neither `maintenance_mode_swap` nor `throttle` takes a scope:
the maintenance middleware replaces Laravel's in its existing position in the global stack, after `HandleCors`, so
maintenance `503` responses still carry CORS headers (nothing is added if Laravel's has been removed), and
`throttle` is registered as the router's `throttle` alias.

Soft-delete visibility is resolved from the request by the query parser rather than by a request macro:
`ApiQuery::getTrashed()` returns a `TrashedState`, and the criteria layer applies it only where the model uses
`SoftDeletes` and the resource has opted in by overriding `allowsTrashed()`. A resource that has not opted in
keeps its soft-deleted records hidden whatever the request asks.

**Request throttling and rate-limit keying** - each request is keyed by method, host, path, and caller
identity. Authenticated requests are keyed by the user identifier; guests are keyed by their client IP
(`$request->ip()`), matching Laravel's stock `ThrottleRequests`. Guests are deliberately not pooled into a
single shared bucket, which would let one anonymous caller exhaust the rate limit for every other guest.

Behind a shared-IP proxy, load balancer, CDN, or NAT, per-IP guest keying can over-throttle many distinct
callers that share one egress IP. Configure Laravel's `TrustProxies` middleware so that `$request->ip()`
resolves the real client IP rather than the proxy's.

To key guests by an application-specific identifier (for example an API key) instead of their IP, set
`api-toolkit.middleware.throttle.class` to your own middleware that uses the `ThrottleRequestsTrait` and
overrides `resolveRequestSignature()`. That config option is the supported customisation point.

---

### Schema Introspection and OpenAPI Export

The schema compiler resolves filterable columns, sortable columns, traversable relations, and all field keys
for any registered resource without instantiating it. A complementary database-schema introspector resolves
model columns, their type and nullability, and relations; it is used internally by `ApiCriteria` and is
available for injection:

```php
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;

public function __construct(private SchemaIntrospectionProvider $introspector) {}
```

An OpenAPI 3.1 document is generated from the registered resource map, the routes the application exposes,
and the live operator grammar:

```bash
php artisan api-toolkit:export-openapi
php artisan api-toolkit:export-openapi --output=openapi.json
```

Each resource becomes a component schema and each documented route an operation carrying the query
parameters that action honours, so a generated client sends the grammar instead of discovering it from a
422. Every property answering a query carries an `x-query-surface` extension naming the key to send it
under, the capability it is filterable with together with the operators that capability answers, whether an
index backs its sort and the reason recorded where none does, and the strategy a search matches it by; the
relations a filter may descend through are named on the schema itself. The surface and the relations alike
are read from the compiled schema the request-time gates read, and the operator vocabulary from the bound
operator registry, so the document cannot offer a column the request would reject or an operator the
package no longer ships. A column whose every operator has left the registry is documented as answering no
filter at all.

A second command writes the generated reference sections - the error catalogue, the enum reference, and the
query surface reference, which tables the queryable columns of the resources an audience documents beside
the bounds a request is held to - into the Markdown docs directory assembled into the document's
description. The surface reference is written once per audience, under `audiences/<audience>/`, so what a
resource may be asked reaches only the documents that already carry its schema:

```bash
php artisan api-toolkit:docs:generate
```

---

### Metadata Invalidation

Schema metadata - column listings and definitions, index catalogues, cast maps, relation lookups, and the
model-to-resource map - is cached in the application's cache store and shared by every process that uses it.
The Octane and queue boundary flushes reset each worker's in-process state only, so every worker keeps serving
what any worker has already read, and a schema or mapping change needs the shared entries retired explicitly.
Every metadata key is stored under a generation held in the same store, and replacing that generation makes
every earlier entry unreachable in every process at once. A column listing or set of column definitions that
reads empty, as one does before its table exists, is never stored, so the table's columns are read as soon as
it exists, with no migration event or invalidation needed.

The metadata cache is optional. If a cache store operation throws, the failure is reported as a
`MetadataCacheUnavailableException` and the store is bypassed for `api-toolkit.cache.retry_after` seconds (env
`API_TOOLKIT_CACHE_RETRY_AFTER`, default `30`), so metadata is computed without the cache and the request is still
served. A write the store rejects is reported at most once a window but bypasses nothing, so a store that rejects
writes keeps serving its warm entries; the `null` driver's rejections are not reported. The windows are held for
the process, so an Octane or queue worker reports an outage once a window. Under PHP-FPM no state outlives a
request, so there an outage is reported once per request; throttle it in the exception handler with a lottery,
since a rate limit on the same unavailable store cannot throttle:

```php
use Illuminate\Support\Lottery;
use SineMacula\ApiToolkit\Exceptions\MetadataCacheUnavailableException;

$exceptions->throttle(fn (Throwable $e) => $e instanceof MetadataCacheUnavailableException
    ? Lottery::odds(1, 100)
    : null);
```

The bypass bounds how many store calls fail, not how long each takes, so give the cache's Redis connection a
short `timeout` and `read_timeout`. With `retry_after` set to `0`, every failed call is retried and reported.

Schema entries are keyed by the schema the connection actually reads - its name, database, table prefix,
Postgres search path, database user, and configured server - so a tenancy switcher that repoints one connection
name reads each tenant's schema apart, provided it purges the connection when it switches. Casts, relation
lookups, and the resource map come from code and are shared across tenants. Where each tenant has its own cache
prefix, each also has its own generation, so invalidate per tenant.

The generation is replaced automatically when a migration run finishes, which covers schema changes at
migrate time. A run with nothing to migrate, or a `--pretend` run, leaves the metadata warm. If the cache store
cannot be written, the hook logs a warning rather than failing the migration. Set
`API_TOOLKIT_LIFECYCLE_MIGRATIONS=false` to switch the hook off.

The hook does not cover a deploy on its own. Migrations run before the new release takes traffic, so workers
still on the old code can refill the new generation with their casts, resource mappings, and relation lookups,
cached forever. Run the command on every deploy, after the new release is live:

```bash
php artisan api-toolkit:invalidate-metadata
```

It exits with a failure if the cache store rejects the new generation.

The build itself should run `php artisan optimize`, which compiles the discovered resource map along with the
framework's own caches. Reload PHP workers after a rebuild, as with those caches, since OPcache with
`validate_timestamps=0` keeps serving the previous file.

Entries written under a retired generation are no longer read but stay in the store until it evicts or
clears them. Each invalidation leaves at most one copy of the metadata behind.

---

### Notification Audit Log

When `illuminate/notifications` is installed and `api-toolkit.notifications.enable_logging` is `true` (the
default), the toolkit logs every `NotificationSending` event at `debug` and every `NotificationSent` event at
`info`. Each line records the notification class, the notifiable type, the notifiable id (only when the notifiable
is an Eloquent model with a key) and the delivery channel. List notification classes in
`api-toolkit.notifications.excluded_classes` to leave them out.

The lines are written to the application's default log channel, so they go wherever the rest of the application's
logs go and keep any trace correlation applied to them. Whether the `debug` lines are kept depends on that
channel's level. For a dedicated destination, define the channel in the application's `config/logging.php`:

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

---

### Upgrading

See [UPGRADE.md](UPGRADE.md) for version-by-version migration guides, including breaking changes and
the steps required to move from 1.x to 2.x.

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

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a list of notable changes, and [UPGRADE.md](UPGRADE.md) for version upgrade
guides.

## Contributing

Contributions are welcome. Please read [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines on branching, commits, code
quality, and pull requests.

## Security

If you discover a security vulnerability, please report it responsibly. See [SECURITY.md](SECURITY.md) for the
disclosure policy and contact details.

## License

Licensed under the [Apache License, Version 2.0](https://www.apache.org/licenses/LICENSE-2.0).
