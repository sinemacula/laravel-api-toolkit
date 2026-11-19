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
php artisan vendor:publish --provider="SineMacula\ApiToolkit\ApiServiceProvider" --tag=api-toolkit-config
```

`config/api-toolkit.php` is documented inline. Per-query repository caching is configured separately, in the
`sinemacula/laravel-repositories` package under `repositories.cache`.

Each setting read from the environment is named `API_TOOLKIT_` followed by its key, upper-cased with dots as
underscores: `deferred_writes.on_failure` is `API_TOOLKIT_DEFERRED_WRITES_ON_FAILURE`. A boolean also accepts `1`, `0`,
`on`, `off`, `yes` and `no`, and an integer a string of digits.

Every setting is validated once the application boots to serve requests or queue jobs, and when `config:cache` or
`optimize` builds the config cache. An invalid value, or a key the toolkit does not read, throws
`InvalidConfigurationException` naming the key; nothing is defaulted, clamped or ignored. Other console commands fail
only where they read the invalid setting, which for a setting read at boot is every command. Under a cached
configuration the message names the cache file to delete before rebuilding it.

The Markdown templates for the generated API manual are published with `--tag=api-toolkit-docs`.

### Translations

The toolkit's own client-facing messages are translation lines in the `api-toolkit` namespace: `exceptions.php` holds
the error titles and details, keyed by integer error code, and `validation.php` holds the 422 messages for rejected
query parameters. Publish them with `--tag=api-toolkit-lang`, which copies them to `lang/vendor/api-toolkit` (or
`resources/lang/vendor/api-toolkit` when the application keeps its translations there).

Laravel merges a published file over the package's own, so keep only the lines you change. A full copy freezes the
wording of every other line against later releases. Placeholders such as `:key` and `:operator` carry the parameter
names exactly as the client sent them, and do not pass through `validation.attributes`.

To add a locale, create `lang/vendor/api-toolkit/<locale>/validation.php` (and `exceptions.php`) with the lines you
translate. Missing lines fall back to English as long as `app.fallback_locale` is `en`.

A message is worded in the locale current when it is raised. `ParseApiQuery` is global middleware, so a malformed
`page`, `limit`, `filters` or `search` value is rejected before any route middleware runs, while an undeclared key or
operator is rejected later, in the controller. An application that sets the locale in route middleware, for example
from the authenticated user, sets `api-toolkit.parser.register_middleware` to `false` and adds `ParseApiQuery` to its
routes after its locale middleware.

A Payload enum value is checked with the other rules and fails with Laravel's `validation.enum` line, so it honours
`validation.attributes` and the locale like any other field.

## Usage

### API Query Parser

`ApiQueryParser` sits behind the `ApiQuery` facade and is populated by the `ParseApiQuery` middleware, which the
service provider pushes onto the global middleware stack unless `api-toolkit.parser.register_middleware` is `false`.
Parameters are read from the query string only; a request body is never parsed. Every key and value in `page`, `limit`,
`cursor`, `fields`, `aggregates`, `filters`, `search`, `order` and `trashed` must be valid UTF-8, or the request is
rejected with a 422 on that parameter.

**Sparse fieldsets** - request only the fields you need for a resource type:

```http
GET /users?fields[users]=id,name,email
```

```php
use SineMacula\ApiToolkit\Facades\ApiQuery;

$fields = ApiQuery::getFields('users'); // ['id', 'name', 'email']
```

A type is the lower-cased `RESOURCE_TYPE` of a resource that is mapped or lives under `resources.paths`. Names are
checked against the type they are keyed by, whatever the endpoint: an unknown type or an undeclared name is rejected
with a 422 on `fields.{type}` naming each. `?fields=name`, with no type, is rejected too, and `?fields[users]=` returns
the fixed fields only.

**Filtering** - pass a URL-encoded JSON object of operator tokens:

```http
GET /users?filters={"status":{"$eq":"active"},"created_at":{"$ge":"2024-01-01"}}
```

A `filters` value that is not a JSON object is rejected with a validation error.

Built-in operators: `$eq`, `$neq`, `$gt`, `$lt`, `$ge`, `$le`, `$in`, `$between`, `$contains`, `$null`, `$notNull`.
Each is accepted only on a column whose declared capability allows it (see
[Query capabilities](#schema-driven-apiresource)); any other pairing is rejected with a 422 naming the operator, the
column and the operators that column accepts. The shorthand `{"name":"Alice"}` is `$eq` and is gated the same way.

Each built-in operator takes a value of one shape:

| Operator                   | Value                 | Matches rows                                   |
|----------------------------|-----------------------|------------------------------------------------|
| `$eq`, `$neq`              | one value             | equal, or not equal, to it                     |
| `$gt`, `$ge`, `$lt`, `$le` | one value             | above or below it                              |
| `$in`                      | a list, empty allowed | equal to any item; an empty list matches none  |
| `$between`                 | `[low, high]`         | from low to high inclusive                     |
| `$null`, `$notNull`        | `true`                | missing, or holding, a value                   |
| `$contains`                | any JSON              | whose JSON document contains it                |

`null` is never a value: a missing value is matched by `{"name":{"$null":true}}`, so `{"name":null}` is refused. Every
value is also checked against the column's type, read from the schema catalogue. An integer column takes an integer, a
digit string or a boolean, a boolean column `true`, `false`, `0` or `1`, a number column a finite number, a `uuid`
column a UUID, a date `YYYY-MM-DD`, and a date-time `YYYY-MM-DDTHH:MM[:SS[.fraction]]` with an optional `Z` or offset.
A date-time with an offset is converted to the application timezone, so a timestamp a resource emitted can be sent
back as it is. A column cast to an enum takes only its cases' values, or a pure enum's lowercase names. A value outside
its shape or its column's type is rejected with a 422 at its position, naming the form expected. Where the catalogue
cannot be read, only the shape is checked.

The entries of a filter document AND together, at every depth. `$and` and `$or` take a non-empty list of filter
documents, each grouped on its own and joined by AND or OR:

```json
{"status":"active","$or":[{"name":"a"},{"email":"b"}]}
{"$or":[{"role":"admin","status":"active"},{"role":"owner"}]}
{"$and":[{"$or":[{"a":1},{"b":2}]},{"$or":[{"c":3},{"d":4}]}]}
{"$or":[{"name":"a"},{"posts":{"user":{"name":"b"}}}]}
```

A relation declared `traversable()` takes a filter document for the related resource, checked against that
resource's own declarations, and compiles to `whereHas()`. `$has` and `$hasnt` take a relation name, a list of names,
or relation names keyed to filter documents, and compile to `whereHas()` and `whereDoesntHave()`. Every condition in
one relation document must hold for the same related row: `{"posts":{"a":1,"b":2}}` needs one post matching both,
while `{"$and":[{"posts":{"a":1}},{"posts":{"b":2}}]}` may match different posts.

The key is the Eloquent relation name, whatever alias the field renders under. The related level is governed by the
resource the relation declares, so `Relation::to('author', PublicUserResource::class)->traversable()` accepts only what
`PublicUserResource` filters, even where the related model's `resource_map` entry filters more. A relation declaring
no resource falls back to that entry, and a `MorphTo` is governed by the entry of each concrete type, so a key is
rejected unless every type stored in the table permits it. A level no resource governs rejects every key. A `MorphTo`
with no type stored is never descended, so its nested keys go unchecked, `$has` matches nothing and `$hasnt` matches
every row.

A filter that cannot narrow the query is rejected with a 422 keyed by its position, such as `filters.$or.1.name`,
rather than dropped: a group that is not a non-empty list, an empty group document, a condition with no column, a
column given an empty object, a list, or a key that is not a registered condition, and any entry that adds no
condition, such as an empty `$has` list.

**Free-text search** - match a term against the fields a resource declares `searchable()`:

```http
GET /users?search=smith
```

The term is matched against the requested resource's own columns, never through a relation. Each searchable field
declares a match strategy (`SearchStrategy::EXACT`, `PREFIX` or `SUBSTRING`), and the connection's search driver
refuses a strategy it cannot serve from an index. Terms are bounded by `api-toolkit.search`; a term outside the bounds
is rejected with a 422, not trimmed.

The minimum word length is three characters. Configuration can raise it, and refuses a value below three: below three,
MySQL matches nothing once a word is shorter than the n-gram token size, and PostgreSQL answers by reading the whole
table. The minimum applies to each word, because a short word is dropped from a MySQL full-text phrase but kept by a
PostgreSQL pattern match, so the two engines would return different rows.

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

`php artisan api-toolkit:validate-schemas` reports a searchable declaration with no index behind it; run it in CI
against a migrated database. Because schema validation is off in production by default, the proof is also taken at
request time, so a missing index refuses the request instead of scanning the table. On PostgreSQL a missing trigram
index is not an error, so without this the search would silently run as a sequential scan.

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
no database file yet. List the connection, or set `API_TOOLKIT_RESOURCES_VALIDATE_SCHEMAS=false` until you do.

Entries are connection names as `config/database.php` keys them, not engines. A stock application names each
connection after its engine, so listing `mysql` waives the connection that usually serves production. A listed
connection may scan the whole table on every request; only list development connections.

`?search=smith&order=relevance` orders the matches by the best way each row matches the term, best first:

| Rank | The term matches                    | Reached by                     |
|------|-------------------------------------|--------------------------------|
| 1    | the whole value                     | `EXACT`, `PREFIX`, `SUBSTRING` |
| 2    | the start of the value              | `PREFIX`, `SUBSTRING`          |
| 3    | the start of a space-delimited word | `SUBSTRING`                    |
| 4    | anywhere else                       | `SUBSTRING`                    |

A row ranks by its best searchable column, compared with `=` for `EXACT` and otherwise `like`, or `ilike` on
PostgreSQL, so case folds as it does in matching. A MySQL n-gram match no literal pattern sees ranks last. An
`EXACT`-only resource adds no order, since every match ranks first. `relevance` applies at its position in the order
list, so `order=relevance,name` breaks ties by name before the appended key. It needs `search`, offset pagination and
the `asc` direction; anything else is a `422` on `order.relevance`. A custom driver implements `rank()` to order its
own matches.

Relevance is a computed sort no index serves, so it reads every matching row. Behind a MySQL full-text or PostgreSQL
trigram lookup that costs one `CASE` per match; where the planner could otherwise stop after one page, it reads them
all. It is unsupported beside a repository scope that adds `distinct`, `group by` or a union.

**Sorting** - sort by one or more columns, with optional direction:

```http
GET /users?order=last_name,first_name:desc
```

A direction is `asc` (the default) or `desc`, in any case; anything else, including an empty suffix, is rejected with
a `422` on `order.{column}` rather than ignored. `paginate()` appends the model key in the last direction when the
order does not already include it, so rows tied on the requested columns never repeat or go missing between pages. The
appended key needs no `sortable()` declaration and does not count against `max_order_keys`. A grouped read takes its
group columns instead; a read grouped by an expression, filtered by `having` without groups, `distinct` on columns, or
`distinct` without selecting the columns it would take gets nothing appended and needs a unique order of its own.

A cursor can only seek by a column that holds a value in every row, so a cursor-paginated read rejects `random` and
any column the cached column catalogue reports as nullable, such as the `created_at` that `timestamps()` creates;
declare the column `NOT NULL` to cursor-page by it. Offset pagination sorts by either. Columns a repository scope
orders by must be `NOT NULL` for cursor pages too. A cursor that does not decode, does not carry a value for every
ordered column (the client changed `?order` and kept `?cursor`), or carries a value its column cannot hold is rejected
with a `422` on `cursor`; restart from the first page.

**Page-size ceiling** - a `?limit` above `api-toolkit.parser.max_limit` (default 100) is rejected with a `422` naming
the ceiling and the requested size, not reduced to the ceiling. Set the ceiling to `0` to disable it:

```http
GET /users?limit=200   // 422, the ceiling is 100
GET /users?limit=25    // honoured as-is
```

**Relation aggregates** - request declared aggregates per resource type by their keys. Requesting any aggregate replaces
every default of that type, as `?fields` replaces `$default`. A type's aggregates apply wherever it renders, at the root
or embedded, and render under its `aggregates` field when its field selection includes it:

```http
GET /users?fields[users]=name,aggregates&aggregates[users]=posts_count,orders_sum_total,last_order_at
```

```php
ApiQuery::getAggregates('users');      // ['posts_count', 'orders_sum_total', 'last_order_at']
```

An aggregate key the type does not declare is rejected with a 422 on `aggregates.{type}`, and `?aggregates[users]=`
selects none. A resource embedded through a `MorphTo` relation or a `PolymorphicResource` loads no aggregates.

```json
{
    "_type": "users",
    "name": "Ada",
    "aggregates": {"posts_count": 3, "orders_sum_total": 120.5, "last_order_at": "2026-01-01T10:00:00.000000Z"}
}
```

**Pagination** - `?pagination=cursor` (or any `?cursor` token) selects cursor pagination; offset pagination is used
otherwise. A non-empty offset page shorter than `limit`, or an empty first page, derives its total without a count.
Any other page counts at most the rows through the last page `query_cost.max_offset` allows: past that, `meta.total` is
those rows, `meta.total_capped` is `true` and `Total-Count` is absent, so read further with cursor pagination. That
bounds the rows the count admits, not the rows it examines; set `max_offset` to `0` for exact, unbounded totals. A
capped page is a `CappedLengthAwarePaginator`, which a container binding of `LengthAwarePaginator` does not replace. A
`simplePaginate()` result renders the cursor envelope. Offset pages do not support an ungrouped aggregate projection
such as `selectRaw('max(id) as id')`, which is one row rather than a pageable set, nor a `distinct` or union over a join
without an explicit projection, which MySQL refuses to count.

**Soft deletes** - `?trashed=with` or `?trashed=only`, read as a `TrashedState` from `ApiQuery::getTrashed()`. The
criteria layer applies it only where the model uses `SoftDeletes` and the resource opts in by overriding
`allowsTrashed()`; anywhere else the request is refused with a 422 on `trashed`, as is any value other than `with` or
`only`, so a client never mistakes live records for the ones it asked for. On a read through the criteria, an included
relation follows the state where its own resource opts in and otherwise stays live; a read that applies no criteria,
such as implicit route-model binding, only checks the value.

### Schema-Driven ApiResource

Extend `ApiResource` and declare a `schema()` method using the `Field` and `Relation` helpers and the relation aggregate
helpers. The compiled schema drives field resolution, guards and eager-load planning.

```php
use App\Models\User;
use SineMacula\ApiToolkit\Attributes\ForModel;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Count;
use SineMacula\ApiToolkit\Schema\Exists;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Max;
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
            Field::can('restore'),
            Relation::to('organization', OrganizationResource::class)->traversable(),
            Count::of('memberships')->default(),
            Sum::of('orders', 'total'),
            Max::of('orders', 'created_at', 'last_order_at'),
            Exists::of('messages', 'has_unread_messages')->constrain(fn ($query) => $query->whereNull('read_at')),
        );
    }
}
```

**Relation aggregates** - six helpers load an aggregate over a relation in the same query, through Laravel's
`withAggregate()`. Each has one key, which names it in the declaration, the `?aggregates` request, the response and the
exported schema. The key defaults to Laravel's own attribute name and `as()` or the last argument of `of()` replaces it:

| Helper                            | Default key             | Value                                 |
|-----------------------------------|-------------------------|---------------------------------------|
| `Count::of('posts')`              | `posts_count`           | integer, `0` without related rows     |
| `Exists::of('posts')`             | `posts_exists`          | boolean, `false` without related rows |
| `Sum::of('orders', 'total')`      | `orders_sum_total`      | number                                |
| `Average::of('orders', 'total')`  | `orders_avg_total`      | number                                |
| `Min::of('variants', 'price')`    | `variants_min_price`    | the column's value                    |
| `Max::of('orders', 'created_at')` | `orders_max_created_at` | the column's value                    |

`constrain()` narrows the related rows, `default()` includes the aggregate when the request names none, and `guard()`,
`visibleTo()` and `transform()` work as on fields. A minimum or maximum is read the way the related model reads that
column, so a timestamp renders as ISO 8601 and a cast enum as its value. Sum, average, minimum and maximum over zero
related rows are omitted. `api-toolkit:schemas:validate` rejects an aggregate over a sensitive or missing column, a sum
or average over a non-numeric column, a minimum or maximum the related model would read back through an accessor or a
non-scalar cast, a `MorphTo` relation, and an `aggregate_<key>` attribute the owner model already uses as a column, cast
or setter.

Only fields marked `filterable()` or `sortable()` can be queried, and `?search=` matches only fields marked
`searchable()`. Relations marked `traversable()` can be targeted by nested filters. A column declared filterable with
two capabilities fails to compile. `$default` lists the fields returned when the client sends no `?fields`, and the
static `$fixed` the fields added to every selection, after those in `resources.fixed_fields`. Schema validation rejects
a default or fixed field the resource does not declare. A tabular export has a column for each selected field, in the
order selected: the requested fields, every field for `:all`, or the defaults. Relations are left out and fixed fields
are not added.

**Guards** - `guard(fn ($resource, $request) => ...)` is a row guard and `visibleTo(fn ($request) => ...)` a caller
guard; either hides the field, aggregate or relation when it returns exactly `false`. A query cannot honour a row guard
without revealing what it hides, so a row-guarded field that is `filterable()`, `sortable()`, `searchable()` or
`traversable()` fails to compile. A caller guard reads only the request, so its field stays queryable by the callers it
admits; any other caller is refused with the same 422 as an undeclared key, and the OpenAPI export marks the key
`conditional`. A search matches the same columns for every caller, so a caller-guarded field may not be `searchable()`.
A column everyone may query but only some may see is declared queryable on an unguarded field as well. A tabular export
refuses a selected row-guarded field with `PerItemGuardedFieldException` and drops a caller-guarded column it hides.

**Schema lifetime** - `schema()` runs once per resource class per worker, and its result is shared by every request
the worker serves, under Octane and on queue workers alike. It must not read or capture the request, the user, the
tenant, the query parser, the clock or runtime config, and the closures it declares must not retain them. Read
per-request values when a closure runs instead: row guards, accessors and compute closures receive the resource and
the request, caller guards the request, constraints the query, and transformers the resource and the value.

**Declared column reads** - `needs()` names the base-table columns a field reads. Declare it on any field whose value
does not come straight from the column of the same name, such as one over an Eloquent accessor or custom cast, or an
accessor closure:

```php
// The model's fullName(): Attribute reads first_name and last_name
Field::scalar('full_name')->needs('first_name', 'last_name'),
Field::accessor('initials', fn ($resource) => $resource->first_name[0] . $resource->last_name[0])
    ->needs('first_name', 'last_name'),
```

**Ability flags** - `Field::can('restore')` renders whether the current user passes a policy ability on each record, as
the boolean `can_restore`. The key is `can_` and the snake-cased ability, so `Field::can('forceDelete')` is
`can_force_delete`; pass an alias as the second argument for an ability with dots or hyphens, or one whose key would
collide with another's. The flag renders only when the field set includes it, so a page runs the policy only when asked.
The request's user answers, a guest request answers as a guest, and a resource resolved without a request answers for
the authenticated user. Load the relations the policy reads with `extras()`. Without `needs()` the flag leaves the
select unnarrowed; with it, the list must cover every column the policy and any `before()` hook read. The flag is
computed, so it cannot be filtered, sorted or searched.

**Query capabilities** - `filterable()` takes the access path the column has, and the capability decides which
operators the column answers. Any other operator is refused with a `422`:

| Capability             | Declare it for                                                   | Operators the column answers                                              |
|------------------------|------------------------------------------------------------------|---------------------------------------------------------------------------|
| `Capability::EXACT`    | a keyed column read by equality - an id, a foreign key, an email | `$eq`, `$in`, `$null`, `$notNull`                                         |
| `Capability::ENUM`     | a small closed set, so the complement of one value stays bounded | `$eq`, `$in`, `$neq`, `$null`, `$notNull`                                 |
| `Capability::RANGE`    | an ordered column - a number, a date, a timestamp                | `$eq`, `$in`, `$gt`, `$ge`, `$lt`, `$le`, `$between`, `$null`, `$notNull` |
| `Capability::DOCUMENT` | a JSON column behind a containment index                         | `$contains`                                                               |
| `Capability::OPAQUE`   | a column with no access path the resource will vouch for         | `$eq`                                                                     |

These are the shipped defaults. An operator your application registers names the capabilities that admit it (see
[Filter-Operator Registry](#filter-operator-registry)).

**Model binding and discovery** - `#[ForModel(...)]` binds a resource to its model, and may be repeated to bind one
resource to several models. With `api-toolkit.resources.paths` left `null` (the default), resources are discovered at
boot from `app_path('Http/Resources')` and each module's `app_path('*/Http/Resources')`. Set `paths` to an array to
choose the roots, or to an empty array to disable discovery. An explicit `api-toolkit.resources.resource_map` entry
always wins over a discovered binding: use it to pick the canonical resource when a model has more than one, or to
bind a resource outside the scanned paths. When two discovered resources claim the same model and no explicit entry
resolves it, the first in sorted file order wins and a warning is logged; with `validate_schemas` enabled the boot
fails instead. Every resource under the paths is recorded, bound or not, so its type can be selected and schema
validation covers it; a relation that renders a resource outside both the map and the paths is a schema defect.

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

**Generating a resource** - `php artisan api-toolkit:make:resource OrderResource` writes
`app/Http/Resources/OrderResource.php`, bound by `#[ForModel]` to the model guessed from the class name
(`App\Models\Order`). Pass `--model=` to name another model, including one outside your application, `--type=` to
choose the `RESOURCE_TYPE` (the snake-cased model name by default), and `--force` to overwrite an existing file. The
command reads no database. The draft exposes only the model key, as its one field and its default, so nothing else is
returned and nothing is filterable, sortable or searchable: declare each further field and its query surface by hand.
The command refuses a type another mapped resource already returns, and a model another discovered resource already
binds without an explicit `resource_map` entry. It warns when discovery will not see the file (outside `paths`, or a
compiled map in place), and notes the morph alias a model not yet mapped will gain. Run `php artisan stub:publish` to
customise `stubs/api-toolkit.resource.stub`.

**Eager-load planning** - the resource builds `with()` and `withAggregate()` maps from the resolved field set:

```php
$with = UserResource::eagerLoadMapFor(UserResource::resolveFields());
```

**Relation constraints and limits** - `constrain()` receives the relation itself, as a Laravel `with()` closure does, so
`where()`, local scopes, `latest()` and pivot clauses such as `wherePivot()` all apply. A constraint may filter and
order the relation but never bound it: a `limit()`, `take()` or `skip()` inside it that changes the relation's bound
throws a `LogicException`. Bound a to-many relation with `limit()` instead:

```php
Relation::to('posts', PostResource::class)->limit(5)->constrain(fn ($query) => $query->latest()),
```

Each parent renders at most `limit` related records. The relation loads in one windowed query, ordered last by the
related key so the window is deterministic. A relation declaring no limit takes `api-toolkit.resources.relation_limit`
(`API_TOOLKIT_RESOURCES_RELATION_LIMIT`, default `25`; `0` or `null` leaves it unbounded). A record whose relation was
cut lists the relation's response key in `_truncated`, and the exported schema documents the bound as `maxItems`.
`HasMany`, `MorphMany`, `BelongsToMany` and `HasManyThrough` are bounded in SQL unless their query has unions; any other
loaded collection, including one your own code loaded, is cut when it renders. A relation bounded in SQL keeps up to
`limit + 1` rows on the model after the repository returns, the extra row being how a cut is detected; one with unions
keeps every row and is cut only in the response. A collection your application loaded below the bound never carries
`_truncated`, so its absence proves nothing about a relation you limited yourself.

A constraint shapes the rendered rows only: filters, `$has`, `$hasnt` and aggregates never run it. A row predicate
belongs in a relation method, which Eloquent applies everywhere:

```php
public function publishedPosts(): HasMany
{
    return $this->hasMany(Post::class)->where('published', true);
}

Relation::to('publishedPosts', PostResource::class)->alias('posts')->traversable(),
```

A relation loads once per path, so the schema compiler rejects a constrained relation any field makes `traversable()`,
fields over one relation where any is constrained or they render different resources, and a relation name holding a
dot. Fields over one relation may differ in `fields()`, and an accessor-only field may share any relation.

**Field-set control** at instantiation:

```php
new UserResource($user, loadMissing: true);          // eager-loads missing relations
new UserResource($user, included: ['id', 'email']);   // explicit field set
new UserResource($user, excluded: ['email']);          // field set minus exclusions
(new UserResource($user))->withAll();                 // all schema fields
```

**Schema validation** - `api-toolkit.resources.validate_schemas` validates every registered schema at boot. It is on
by default outside production (`API_TOOLKIT_RESOURCES_VALIDATE_SCHEMAS=false` turns it off) and off in production. The
`api-toolkit:validate-schemas` Artisan command runs the same validation regardless of the flag, so it can gate CI.

Validation checks that:

- every `filterable()` and `sortable()` declaration names a column that exists. A computed field, or an accessor
  reading a different path, is caught from the schema alone; the rest is checked against the table's column listing.
- every `needs()` column exists on the table.
- an ordered index leads with every `sortable()` column, unless the field carries `indexed()` or `unindexed()`.
- an index on the connection serves every `searchable()` declaration in the shape its strategy needs.
- no declaration names a column configured as sensitive.
- every `limit()` sits on a to-many relation rendered through a child resource, every field over one relation shares
  one limit, and no field is named `_truncated`.

Every resource a filter reaches through a `traversable()` relation is validated too, once per related model, whether or
not the resource map lists it.

At boot, checks that read the connection are skipped where it cannot be read, such as a boot with no database or
before migrations have run.

The command is stricter, because it gates deployment. It fails when the resource map is empty, and when any check
could not read the connection: each declaration it could not verify is listed under why its table's catalogue was
unreadable, whether the connection cannot be resolved, the catalogue cannot be read, or the table is not migrated.
Pass `--allow-empty` to accept an empty map, or `--allow-unverified` to accept declarations it could not verify;
neither flag lets a defect pass. A searchable declaration on a connection listed under
`api-toolkit.search.unverified_connections` is not reported, since that listing already waives its index proof. Boot
skips schema validation while this command runs, so the command reports everything itself.

The index catalogue is read only during validation, never while a filter or sort is served. Three schema reads do sit
on the request path, all cached in the shared store: column narrowing (`api-toolkit.resources.narrow_columns`) reads
the table's column listing, search reads its index proof, and a cursor-paginated sort reads the column definitions to
refuse a nullable key.

**Column narrowing** - with `api-toolkit.resources.narrow_columns` on (the default), the base-table select is narrowed
to the columns the resolved fields read, plus the primary key, the soft-delete column, the parent keys of eager-loaded
relations and the order columns. A field reads the columns it declares through `needs()`, each of which must exist on
the table. A plain field without `needs()` is taken to read the column of its own key, but only when the table carries
that column and the model reads it without an accessor, a public instance property of the same name or a cast that could
consult other attributes; Eloquent's built-in cast types, enums that do not name their own caster, and the casts under
`Illuminate\Database\Eloquent\Casts` qualify. Any other field, such as one over an accessor or custom cast, or a
computed, row-guarded or transformed field, leaves the select unnarrowed until it declares `needs()`.

An incomplete `needs()` returns wrong data rather than failing. Call
`Model::preventAccessingMissingAttributes(! app()->isProduction())` in a service provider to catch it outside
production: it throws on a read of an unselected attribute that is plain or carries a built-in or enum cast, though
not on one with an accessor or a class cast, nor on a raw `$attributes[...]` read inside a cast. Disable narrowing if
code reads attributes outside the rendered fields.

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

**Outside a request** - criteria apply only under a parsed query: the one `ParseApiQuery` parsed, or a snapshot given
to `ApiQuery::using()`. Applied under none, as in a queued job, a console command or a route without the middleware,
they throw a `LogicException` rather than read the unfiltered table, and so do criteria composed under one query and
applied under another. Code with no client query runs under `ApiQuery::using(new ApiQuerySnapshot([]), ...)`.

`ApiQuery::snapshot()` captures the `fields`, `aggregates`, `filters`, `search`, `order` and `trashed` the request
carried, as sent. It never carries `page`, `limit`, `cursor` or `pagination`, so a replayed read is not cut to one
page. A snapshot serialises with a job, and its `parameters` store as JSON for `new ApiQuerySnapshot($row->query)`.
`ApiQuery::using()` validates the snapshot as it would a request, runs the callback under it and restores the previous
query:

```php
ArchiveInvoices::make(new ArchiveInvoicesInput(ApiQuery::snapshot()))
    ->withContext(ServiceContext::fromRequest($request, EloquentActor::for($request->user())))
    ->dispatch();

// ArchiveInvoices::handle()
return ApiQuery::using($this->input->query->without('order', 'fields'), function (): int {
    $archived = 0;

    foreach ($this->invoices->withApiCriteria()->lazyById() as $invoice) {
        $invoice->archive();
        $archived++;
    }

    return $archived;
});
```

- Read and render inside the callback. A `LazyCollection` keeps its filters after `using()` returns, but a resource
  rendered later reads the restored query, and a resource resolves its fields once, so build it where it renders.
- Work that changes which rows match iterates with `lazyById()` on a snapshot `without('order')`. Keyset iteration
  after a client order skips rows, and so does offset `lazy()` as the set changes.
- `allowsTrashed()` and field guards see the worker's console request, which is not reconstructed, so a carried
  `trashed` the worker's gate refuses, or a carried key a caller guard hides, fails the job.

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
Because pages end on the model key, a composite `(column, key)` index serves the whole order; the leading-column rule
is unchanged.

Two overrides cover what the catalogue cannot show:

- `indexed('users_lower_name_index')` names the index behind the column, such as an expression or partial index. The
  name must exist on the table; what it covers is not checked.
- `unindexed('the table is bounded at a few hundred rows')` records a deliberate exemption. The reason is required.

**Random ordering** - `?order=random` is disabled by default. Enable it with
`api-toolkit.repositories.allow_random_order` (`API_TOOLKIT_REPOSITORIES_ALLOW_RANDOM_ORDER=true`); while disabled,
`random` is treated as an ordinary sort key and rejected like any undeclared column. `relevance` is always the
search relevance order, so a `sortable()` column named `relevance` cannot be ordered by; schema validation reports it.

**Query cost caps** - `api-toolkit.query_cost` bounds the filter document's size and nesting, the keys and value-list
items it dispatches, the sort keys and relation aggregates requested across every resource type, and how deep a request
pages. A request over a cap is rejected before any SQL is issued, with a `422` naming the parameter, the position within
it, the cap, the limit and the value supplied. Set a cap to `0` to disable it.

**Writes** - `persist($model, $attributes)` creates or updates the model from an array or collection of attributes.
Each key is written through Eloquent's `setAttribute()`, so casts, both mutator styles and JSON paths such as
`options->theme` apply. A key naming a relation is written through it: a `BelongsTo` from a model, its key or `null`,
a `MorphTo` from a model or `null`, and a `BelongsToMany` or `MorphToMany` from models or their keys, synced once the
model is saved. A key naming any other relation throws an `InvalidArgumentException` before any key is assigned, and
a key that is neither a column nor a mutator fails at save with the database's `QueryException`. A set mutator named
like a relation takes precedence over it. `$fillable` and `$guarded` are not applied, so pass only validated input.
The save and the syncs run in one transaction on the model's connection, so a sync that fails rolls the save back and
leaves the model instance stale; discard it. A save that a `saving`, `creating` or `updating` listener cancels syncs
nothing and returns `false`.

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

A read is keyed by the SQL it runs: the root query with its global scopes applied, and each eager-loaded relation's
query with its `constrain()` closure and the related model's global scopes. `?trashed`, tenant scopes and constraints
that read the authenticated user are therefore cached per value. A read with a `MorphTo` include, a relation class other
than the framework's own, an `afterQuery()` callback other than `chaperone()`'s, a callable `withDefault()` or eager
loads nested more than 16 levels deep runs uncached. Scopes and constraints run once more per read to build the key, so
they must be deterministic and free of side effects. `paginate()` is never cached. Writes, `persist()` included,
invalidate the table. Eager-loaded rows are cached with their parent, so a write to a related table leaves them stale
until the TTL. Call `withoutCache()` to bypass the cache for one read, or `flushCache()` to invalidate immediately.

With `$cacheReferenceTable = true`, the table is loaded once per global-scope variant and memoised in-process, and
`get()` and `find()` are served from that snapshot. A read with criteria, repository scopes or a column list other than
`*` runs a real query, and a write clears every variant. Use it only for small lookup tables that rarely change.

**Deferrable trait** - buffer inserts in memory and flush them as bulk `INSERT` statements at the lifecycle boundary
(`RequestHandled`, `CommandFinished`, `JobAttempted`, or `JobTimedOut`):

```php
use SineMacula\ApiToolkit\Repositories\Concerns\Deferrable;

class AuditRepository extends ApiRepository
{
    use Deferrable;
}

$auditRepository->defer(['user_id' => 1, 'action' => 'login']);
```

Every queue job attempt flushes, including one that fails, times out or is released for a retry, so a retried job keeps
the rows of its earlier attempts.

Each row goes to its model's connection, resolved when the row is deferred, so a later change of the default connection
does not redirect it. Timestamps follow the model: none when it has timestamps disabled, otherwise its own
`CREATED_AT` and `UPDATED_AT` columns in its date format, and a timestamp you pass is kept. Rows are inserted in
consecutive runs of one column set, so a row may leave out optional columns and they keep their database default.
Each connection flushes its tables in the order they joined the buffer, and a table keeps its place while it holds rows,
so defer a parent before the rows that reference it. An `INSERT` carries at most `deferred_writes.chunk_size` rows and
never more than 32766 bound values, so a wide table is written in smaller chunks.

Reaching `deferred_writes.pool_limit` flushes automatically, but never on a connection with an open transaction: those
rows wait for the next deferral outside a transaction or for the boundary flush, so rolling the transaction back does
not undo them. A flush that does run inside an open transaction joins it: `flushWrites()` called there, or a boundary
flush while an outer transaction is still open, such as a sync-queued job dispatched inside one. Each chunk then runs in
its own savepoint, or each table with `deferred_writes.transactional`, so a failure rolls back only that chunk or table
and the transaction stays usable. A deadlock is the exception: the database may already have rolled back the whole
transaction.

`api-toolkit.deferred_writes.on_failure` picks what happens when a flush fails:

- `collect` (default) - failed records are kept for the next flush. They do not count toward `pool_limit`, so they are
  retried when their connection next flushes automatically, or at the boundary, rather than on every deferral.
- `throw` - raises a `WritePoolFlushException`, for callers that flush explicitly.
- `log` - failed records are dropped. Use it only for disposable writes such as telemetry.

When a boundary flush fails under `collect` or `throw`, a `WritePoolFlushException` is reported through the exception
handler and a `WritePoolFlushFailed` event is dispatched. Under `log` the failure is logged as a warning instead, and
the event is still dispatched.

`WritePool` is a scoped singleton, so after a deferred write `app(WritePool::class)->lastAutoFlushResult()` returns the
outcome of the most recent automatic flush (`null` if none has run). Under `collect` and `log` this is the only
in-process signal that an auto-flush failed.

### Filter-Operator Registry

The `OperatorRegistry` singleton maps tokens to `FilterOperator` handlers and the capabilities that admit each. Register
custom operators in a service provider's `boot()` method:

```php
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;

class AppServiceProvider extends ServiceProvider
{
    public function boot(OperatorRegistry $registry): void
    {
        $registry->register('$starts', new StartsWithOperator, Capability::RANGE);

        // Replace a registered operator's handler
        $registry->override('$contains', new StrictContainsOperator);
    }
}
```

An operator is a class implementing `SineMacula\ApiToolkit\Contracts\FilterOperator`, or a closure with the same
signature, `apply(Builder $query, string $column, mixed $value): void`. A token is `$` followed by a letter, then
letters, digits or underscores, and may not be `$and`, `$or`, `$has` or `$hasnt`. `register()` throws
`InvalidArgumentException` for any other token, or one already taken.

A token is answered only on columns declaring one of the capabilities it was registered with, so `$starts` above is
refused with a `422` on an `EXACT` or `OPAQUE` column. The shipped tokens are registered with the defaults in
[Query capabilities](#schema-driven-apiresource). `override()` replaces the handler of a registered token and keeps its
capabilities, so a replacement `$contains` still serves only `DOCUMENT` columns; it throws `InvalidArgumentException`
for an unregistered token. To change a token's capabilities, shipped or not, `remove()` it and `register()` it again.
The bare shorthand `{"name":"Alice"}` runs the registered `$eq` handler, so overriding `$eq` changes both spellings,
and removing it refuses both. A column whose capability admits no registered token refuses every filter on it.

An operator adds AND-joined predicates for one column; the applier owns the grouping, so an operator never chooses the
connective, and clauses an operator joins with OR are grouped on their own. One that refuses its operand throws a
`ValidationException`, and one that adds no clause is rejected with a 422. Operators are predicate-only: a scope
removed inside one leaks to the outer query.

An operator whose value has a fixed shape can implement `SineMacula\ApiToolkit\Contracts\DeclaresValueShape` and
return a `FilterValueShape` from `valueShape()`. Its value is then checked against that shape and the column's type,
and resolved as the built-in operators' values are, before `apply()` runs. A closure, or an operator without the
contract, receives the value as sent.

An operator that expands one value into a predicate per item should also implement
`SineMacula\ApiToolkit\Contracts\ExpandsValueList` and return the item count from `countValueItems()`. That count is
checked against the `max_in_items` cap, so a delimited string is bounded the same as an array. An operator without
the contract counts as one item per non-list value.

An unregistered token is rejected with a 422, under a column or on its own. The operators each column answers in the
exported document and the generated docs are the registered tokens its capability admits, custom ones included.

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

Every error renders as one envelope:

```json
{
  "error": {
    "status": 422,
    "code": 10106,
    "title": "Invalid Input",
    "detail": "The information supplied was invalid",
    "meta": {"email": ["The email field must be a valid email address."]}
  }
}
```

`detail` is always present, empty when the code has no detail translation, and `title` is left out when none resolves.
`meta` is the exception's own meta, always an object, and is left out when empty. With
`api-toolkit.exceptions.include_debug_info` on (null follows `app.debug`), an error with an underlying exception adds
that exception's message, class, file, line and trace under `error.debug`, beside the meta rather than inside it.

An `ApiException` declares the shape of its meta by implementing `DefinesErrorMeta`, and the exported document types
`error.meta` for its code with it. A subclass inherits the declaration with the code:

```php
use SineMacula\ApiToolkit\Exceptions\ApiException;
use SineMacula\ApiToolkit\OpenApi\Contracts\DefinesErrorMeta;

final class SlotTakenException extends ApiException implements DefinesErrorMeta
{
    public const ErrorCodeInterface CODE = AppErrorCode::SLOT_TAKEN;

    public const HttpStatus HTTP_STATUS = HttpStatus::CONFLICT;

    public static function metaSchema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => ['next_free' => ['type' => 'string', 'format' => 'date-time']],
            'required'             => ['next_free'],
            'additionalProperties' => false,
        ];
    }
}
```

On a precognitive request a `ValidationException` renders as Laravel's `{message, errors}` body, which Precognition
clients read, rather than the error envelope.

Malformed UTF-8 in an error body, such as a client's key echoed in a validation message, is rendered as U+FFFD.

`api-toolkit.exceptions.map` renders an exception as an `ApiException` of your own:

```php
use Illuminate\Http\Exceptions\ThrottleRequestsException;

'map' => [
    ThrottleRequestsException::class => QuotaExceededException::class,
],
```

A key naming a built-in replaces it, any other is matched just before the first built-in mapping whose key it extends,
else after every built-in, and the first match wins. So `ThrottleRequestsException` wins over the built-in
`TooManyRequestsHttpException` it extends, and `RuntimeException` or `Throwable` catches only what no built-in maps.
The response keeps the headers of the exception it replaces.

Laravel converts these exceptions before the handler sees them, so a key naming one would never match and is rejected.
Map the exception it becomes:

- `ModelNotFoundException`, `RecordNotFoundException`, `RecordsNotFoundException` and `BackedEnumCaseNotFoundException`
  become `NotFoundHttpException`.
- `RequestExceptionInterface`, such as `SuspiciousOperationException`, becomes `BadRequestHttpException`.
- `AuthorizationException` becomes `AccessDeniedHttpException`, or an `HttpException` with its status when it has one.
- `OriginMismatchException` and Laravel's `TokenMismatchException` become an `HttpException` with `403` and `419`.

An `HttpException` carrying only a status, from `abort()` or a denial such as `denyAsNotFound()`, renders as the key
its status stands for, so a hidden record answers exactly as a missing one: `400` as `BadRequestHttpException`, `401` as
`AuthenticationException`, `403` as `AccessDeniedHttpException`, `404` as `NotFoundHttpException`, `405` as
`MethodNotAllowedHttpException`, `413` as `PostTooLargeException`, `429` as `TooManyRequestsHttpException` and `503` as
`ServiceUnavailableHttpException`. `419` renders as a token mismatch, any other status as a generic HTTP error, and an
unrecognised status such as `499` as a generic `500`. A key naming `HttpException` or `HttpExceptionInterface` itself
takes precedence over all of these.

A target must be an instantiable `ApiException` with a `CODE`, a 4xx or 5xx status, and `ApiException`'s own constructor
and `getStatusCode()`. The map is validated with every other setting, and an invalid one throws
`InvalidConfigurationException`. An invalid map or rendering setting found while rendering is reported, and Laravel
renders the exception itself. The map does not change reporting: silence a mapped exception with
`$exceptions->dontReport()`.

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
- `LockUnavailableException` (lock contention, rendered as `429`) is never reported, with or without `handles()`. The
  `429` carries no `Retry-After` or `X-RateLimit-*` header, since how long the holder keeps the lock is unknown.

Other framework exceptions rendered as client errors, such as `ModelNotFoundException` (`404`) or
`ValidationException` (`422`), are kept out of reports by Laravel's own don't-report list.

An exception the handler does not map is unhandled. Laravel reports the original exception, with its own class and
trace and subject to its don't-report list, and the toolkit renders a generic `500` body, so with debug info disabled
its internals never reach the client.

The filter applies wherever Laravel reports, including queued jobs and console commands. It does not change retries,
failed-job storage or console output. Reporting is decided after any `$exceptions->map()` has been applied.

Request input is not attached to exception reports. Add the fields you want with your own `$exceptions->context()`
callback.

### Controllers and Authorization

`SineMacula\ApiToolkit\Http\Routing\Controller` provides the response helpers: `respondWithData()` wraps an array in
`data`, and `respondWithItem()` and `respondWithCollection()` render a resource or a collection, or the export the
request negotiates. Each takes an optional `HttpStatus` and headers, which an export carries too.

`AuthorizedController` extends it with resource authorization. Declare the model with `#[AuthorizesResource]`:

```php
use SineMacula\ApiToolkit\Http\Routing\Attributes\AuthorizesResource;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;

#[AuthorizesResource(Order::class)]
final class OrderController extends AuthorizedController {}
```

Each resource action is gated by a `can:` middleware for its policy ability:

| Action         | Ability       | Checked against |
|----------------|---------------|-----------------|
| `index`        | `viewAny`     | the model class |
| `store`        | `create`      | the model class |
| `show`         | `view`        | the bound model |
| `update`       | `update`      | the bound model |
| `destroy`      | `delete`      | the bound model |
| `restore`      | `restore`     | the bound model |
| `forceDestroy` | `forceDelete` | the bound model |

The bound model is read from the route parameter named after the model's snake-cased basename, so `LedgerEntry` reads
`{ledger_entry}`, as `Route::apiResource('ledger-entries', ...)` names it. `parameter` names another, used verbatim. A
parameter the route does not bind passes the gate no model, so the model's policy is never consulted. `except` lists
actions to leave ungated.

`apiResource()` does not route `restore` or `forceDestroy`. Route them with `withTrashed()`, or implicit binding answers
`404` for a trashed row before the gate runs:

```php
Route::post('orders/{order}/restore', [OrderController::class, 'restore'])->withTrashed();
Route::delete('orders/{order}/force', [OrderController::class, 'forceDestroy'])->withTrashed();
```

Any other routed action declares its own authorization, by default with Laravel's `#[Authorize]` on the method or the
class. `->can()` on the route and a `can:` in its group count too:

```php
use Illuminate\Routing\Attributes\Controllers\Authorize;

#[Authorize('update', 'order')]
public function publish(Order $order): JsonResponse
```

Two markers take an action out of the toolkit's gating:

- `#[CustomAuthorization]` - the action authorizes itself, through `$this->authorize()`, a form request or a service.
  The export documents its `403`. Nothing verifies the check.
- `#[SkipAuthorization]` - the action is open to any caller past authentication, and adds no `403` to the export. A
  gate the route declares itself still runs.

An action that declares none of these, and is not in `except`, fails closed: the request is refused before the action
runs with a `LogicException`, reported and rendered as a `500`. So is a resource action whose gate is removed with
`withoutMiddleware('can')`. The guard checks that a gate is declared, not that it is the right one, and only Laravel's
`Authorize` middleware counts: mark an action behind authorization middleware of your own `#[CustomAuthorization]`.

The controller authorizes but does not authenticate: a guest reaches the gate, and a policy that does not accept guests
denies it with a `403`. Put the routes behind `auth` to answer `401` first.

### Middleware

The service provider registers the following middleware. Each can be disabled in `api-toolkit.middleware`:

- **`maintenance_mode_swap`** - JSON `503` maintenance responses. URIs listed in `api-toolkit.maintenance_mode.except`
  stay reachable. `Retry-After` and `Refresh` from `php artisan down --retry` / `--refresh` are forwarded; the response
  is always `503`, so `--status` is ignored. Non-JSON requests that Laravel redirects (`--redirect`) or renders a
  template for (`--render`) are left alone.
- **`json_pretty_print`** - pretty-printed JSON when the request carries `?pretty=true`.
- **`throttle`** - API-friendly rate-limit responses; uses the Redis variant when the default cache store uses the
  `redis` driver.

`json_pretty_print` takes `'scope' => 'global'` (default, pushed to the global stack) or `'scope' => 'api'` (appended
to the `api` middleware group only). The maintenance middleware replaces Laravel's in its existing position in the
global stack, after `HandleCors`, so maintenance responses still carry CORS headers (nothing is added if Laravel's has
been removed). `throttle` is registered as the router's `throttle` alias.

**Rate-limit keys** - `throttle:{max},{minutes}` keys each request by method, host, route and caller. The route is the
matched URI pattern, so `/users/1` and `/users/2` draw on one `/users/{id}` budget and walking ids does not escape the
limit. Authenticated requests are keyed by user identifier; guests by client IP (`$request->ip()`), as Laravel's stock
`ThrottleRequests` does, so one guest cannot exhaust the limit for every other guest. A named limiter
(`throttle:{name}`) uses the key its `Limit` defines.

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
extension, so `openapi.json` becomes `openapi.public.json` and `openapi.internal.json`. Each file is staged beside its
target and moved into place only once all are staged, so one that cannot be staged replaces none of them.

Resources are optional: a route documented only through `#[RequestSchema]` and `#[ResponseSchema]` is exported with
an empty resource map. Operations are not. Every target audience is built before anything is written, and if any of
them has no operations (no route reaches it after the framework namespace exclusions and its own filters), the command
names each empty audience, writes nothing, and exits non-zero. Pass `--allow-empty` to write the documents anyway and
exit successfully:

```bash
php artisan api-toolkit:export-openapi --all --allow-empty
```

Routes handled under a framework or tooling namespace (`api-toolkit.openapi.exclude.namespaces`) and `Route::fallback()`
routes are left out. A closure in an application route file is documented, whether or not the routes are cached.
Responses are documented as JSON; the formats the resource exporter negotiates, such as CSV, are not listed.

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

A route on a domain, such as `{account}.example.com`, lists servers of its own: each of the audience's servers with its
host replaced by the domain, or `https://<domain>` when it has none, over `http` or `https` when the route is restricted
to one. Each domain parameter is a server variable rather than a path parameter, listing the values of a `whereIn()`
constraint or a bound string-backed enum and defaulting to the first, or else defaulting to its own name. A server that
uses a variable named like a domain parameter outside its host fails the export. One controller action routed on several
domains, with nothing else setting the routes apart, is one operation listing the servers of each.

Each resource becomes a component schema, and each documented route an operation carrying the query parameters that
action honours. Every queryable property carries an `x-query-surface` extension naming the key to send, the filter
capability and the operators it answers, whether an index backs its sort (with the recorded reason where none does), its
search strategy, and `conditional` on a filter or sort only some callers may use. The relations a filter may descend
through are listed on the schema itself. All of this is read from the same compiled schema the request-time checks use,
and each column's operators from the tokens the bound registry admits on its capability, so the document cannot offer a
column or an operator the request would reject. A column whose capability admits no registered token is documented as
answering no filter.

A resource schema requires only what every response carries: `_type`, and each fixed field read straight from its
column, with no guard or transformer. Its `x-default-fields` extension lists the fields returned when a request names
none for that type and no parent relation pins them: the declared `$default` fields, then the fixed ones.

A field read straight from its column is typed from the value Eloquent returns: by the model's cast when it has one,
else by the column type. Date casts and the model's timestamps are a `date-time`, a `decimal:N` cast a string, and any
other date-time or time column the driver's string. A value with no fixed shape is marked `x-undocumented`: an `array`,
`json`, `collection` or `object` cast, a cast class other than an enum, `AsStringable` or `AsUri`, and an uncast
decimal, binary or JSON column. On PostgreSQL before PHP 8.4, a float column is a string, as the driver returns it.
Declare the field's `openapi()` schema to document any of these.

A relation rendered through a child resource is typed from its Eloquent relation: a to-many relation as an array of the
related schema, and a to-one relation as the related schema or `null`, since it renders `null` when the related row is
absent. A to-one relation with a default model from `withDefault()` never does, so it is the related schema alone. A
relation's `openapi()` declaration replaces the inferred schema and its `maxItems`, and a transformed relation without
one is left undocumented; either still lists the relation in `_truncated` when it is cut.

Every operation also lists the errors it can return, derived from the route: an unauthenticated request behind auth
middleware, a denied one behind an authorization check (a `can:` gate, an `#[Authorize]` attribute, an
`AuthorizedController` resource action, or an action marked `#[CustomAuthorization]`), a missing record when the route
binds a parameter, a validation failure when the operation accepts a request body or the toolkit's query parameters, a
refusal behind a rate limit, and an unhandled exception always. Each is documented as the exception map renders it: by
default a `401`, `403`, `404`, `422`, `429` and `500`. A list operation also documents the query cost refusal under
`422` while any query cost cap is enabled. The unhandled exception is looked up as a `Throwable`, so a `Throwable` key
changes it and a narrower catch-all such as `RuntimeException` does not. A denial with a status of its own is documented
only where the route already lists the error that status stands for, as a path with parameters does for
`denyAsNotFound()`. Middleware is read as the router resolves it, so a guard or limit applied through a group counts and
one removed with `withoutMiddleware()` does not.

Each `ApiException` subclass named in the `@throws` tags of the action, or of the `handle()` (else `__invoke()`) of any
middleware the route runs, read as the router resolves it, is added under its status too; other throwables in those tags
are ignored. Global middleware is not read, and a middleware the container rebinds is read from the class the route
names. Class names in `@throws` resolve as PHP would in the file declaring the method, so imported, aliased,
same-namespace and fully qualified names all work, and a union such as `@throws A|B` names both. Each error code is
documented once, as the `components.examples` entry `Error<code>` (such as `Error10107`) carrying the code's catalogue
title and detail, and as the `components.schemas` envelope `Error<code>`, which pins `error.code` and types `error.meta`
as the code's exception declares it, else as an open object. `InvalidInputException` declares its field errors and
`QueryTooExpensiveException` the limit it exceeded. Every error response references the examples of its exceptions, and
its schema is the envelope of its code, or a `oneOf` of the envelopes where the status carries several codes. The export
fails when a documented exception has no `CODE`, which the handler cannot render, when its code is not in the error
catalogue (add its namespace to `api-toolkit.openapi.exception_namespaces`), or when it shares a code with an exception
answering another status or declaring another meta.

Each path parameter is typed from what the router enforces on its segment. A `where` constraint comes first:
`whereUuid()` exports a `uuid`, `whereNumber()` a non-negative integer, `whereIn()` or a plain alternation such as
`draft|published` an `enum`, and any other pattern its anchored `pattern`. Without one, the parameter takes the
string-backed enum or Eloquent model the action binds: the enum's component, or the type of the column the model is
looked up by, which is its route key or the field a `{post:slug}` segment names. The `openapi()` schema of a resource
field that emits that column unchanged, with no model accessor, takes precedence over the column type. A `HasUuids` or
`HasUlids` key is typed without a database, unless the model replaces the trait's `isValidUniqueId()`. An explicit
`Route::bind()` binder, an overridden `resolveRouteBinding()`, a model bound in the container or a key cast to a pure
enum leaves the parameter a string. A constraint outside the syntax PHP and JavaScript read alike is not exported, so
the binding types the parameter, or it stays a string: `\d`, `\w` and an unescaped `.` match more characters in PHP, so
write `[0-9]` or `whereNumber()` to export the constraint.

A route with optional parameters exports a path for each URL it matches, every parameter in it required:
`reports/{period?}` exports `/reports` and `/reports/{period}`. As the router matches it, only the trailing run of
optional parameters can be left out, so `{locale?}/reports` exports `/{locale}/reports` alone. A shorter path without
parameters documents a missing record only when the route's domain has parameters or a route default fills one it leaves
out. Any shorter path is left to another route exporting it under the same method.

Every enum the document references, through a model cast, a request field validated by `Rule::enum()` or `PureEnum`, or
a string-backed enum bound to a route parameter, becomes one named component listing its values under `enum`: the
backing values of a backed enum, or the lowercase case names of a pure enum. Fields reference the component, except a
field narrowed by `only()` or `except()`, which lists its accepted values inline (an empty list when it accepts none).

A request field's presence rules shape its object's schema:

- A `confirmed` field documents its confirmation field (`<field>_confirmation`, or the one `confirmed:` names), required
  always when the field is required and through `dependentRequired` when it is optional.
- `required_with`, `required_with_all`, `required_without` and `required_without_all` are expressed with
  `dependentRequired`, `if`/`then` and `anyOf`. They reference only fields declared in the same object or array item:
  any other name is left out, and a `_all` rule naming one is dropped.
- A field with an `exclude` rule requires nothing, since the validator may skip it.
- A present but empty value, such as `null`, `""` or `[]`, counts as missing to these rules but as sent to the schema.

A route outside the resource pipeline documents its success body with `#[ResponseSchema]` on the action. Name a
registered resource to reference its component, or a class implementing `DefinesSchema` to export the JSON Schema its
`schema()` returns as a component named after the class, or after its `#[SchemaName]`. The class is defined once and
referenced wherever it is named. `collection: true` wraps the shape in the collection envelope. Name several classes to
document a response that may be any one of them, exported as a `oneOf` of their references under `data` (or under each
item of a collection). Give each member an object schema (`type` exactly `object`) with a required property holding its
own string `const`, such as a `status`. When every member pins the same property to a distinct value, the `oneOf`
carries a `discriminator` mapping each value to its member. Resources pin `_type`, so a union of resources with distinct
types is discriminated on it. If any named class is neither a registered resource nor a `DefinesSchema`, the body is
left undocumented. `#[SchemaName]` on a resource, enum or `DefinesSchema` sets its component name, and two classes
deriving one name fail the export. An enum named as a shape fails the export when a registered resource's field or a
documented request rule also uses the enum.

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
  action whose `@throws` tags, or those of its route middleware, name it, or whose route facts the handler answers with
  it. A route fact also documents the headers of its built-in target, which the handler forwards whatever the configured
  target, so a remapped rate limit keeps `Retry-After` and `X-RateLimit-*`. Subclasses inherit their parents' headers,
  and exceptions sharing a status contribute every header they declare, the first declaration of a name winning.

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
bounds, and names the resource governing each traversable relation. The surface reference is written per audience,
under `audiences/<audience>/`, so it only reaches documents that already carry the resource's schema, and the
resources their filters reach through a relation that is not a `MorphTo`:

```bash
php artisan api-toolkit:docs:generate
```

#### Operation Ids, Summaries and Descriptions

Set `api-toolkit.openapi.operation_ids` to `true` to export an `operationId` on every operation. The id is the first
of:

1. the `id` of the action's `#[Operation]`;
2. the route name, unless it is empty, `'0'`, ends in `.`, or contains `generated::`, the marker `route:cache` names
   unnamed routes with. A name ending in `.` is the bare prefix of a `->name('admin.')` group and is never an id;
3. for a controller, its basename without `Controller` and the action, kebab-cased with acronyms kept whole:
   `APIKeyController@showAll` gives `api-key.show-all`, and an invokable `APIKeyController` gives `api-key`;
4. for a closure, the method and the path segments without braces: `GET /users/{user}/avatar` gives
   `get.users.user.avatar`.

A route with several methods keeps the id on its first method and appends each later method to it, in the order the
route declares them: a `PUT|PATCH` route named `users.update` exports `users.update` and `users.update.patch`, so
reordering a `match()` changes its ids. An id taken from the method and path already starts with its method, and is not
suffixed. The paths of a route with optional parameters share its id: the shortest keeps it and each longer path appends
the optional parameters it adds, ahead of any method, so a `GET|POST` route named `reports.show` on `reports/{period?}`
exports `reports.show`, `reports.show.post`, `reports.show.period` and `reports.show.period.post`. Ids must be unique
across every audience; two operations claiming one id fail the export, naming both.

`#[Operation]` declares an action's `id`, its `summary`, or both. The summary is exported whether or not ids are on:

```php
use SineMacula\ApiToolkit\OpenApi\Attributes\Operation;

#[Operation(summary: 'Update a user')]
public function update(UpdateUserRequest $request): JsonResponse {}
```

An operation's description is read from `<docs_path>/operations/<id>.md`, and `audiences/<name>/operations/<id>.md`
replaces it for that audience; an empty file blanks it. A later method without its own file takes the first method's, so
`users.update.md` describes both methods unless `users.update.patch.md` exists. Every path of a route with optional
parameters falls back the same way to its shortest path's first method, unless its ids are taken from the method and
path. Filenames are matched byte for byte, so ids differing only in case cannot share a case-insensitive filesystem.
Files and directories whose names start with `.` are ignored. Every export checks the files of every audience, and fails
on an operation file while ids are off, on one matching no exported operation, and on one under the directory of an
audience that is not configured.

#### Tags

An operation's tag is the action's `#[Tag]`, else its controller's, else the resource's schema name. Failing those, a
controller route takes the controller basename without `Controller`, and a closure the first URI segment that is not a
parameter, else `default`.

The document carries a top-level `tags` list once `api-toolkit.openapi.tags` names a tag or a `tags/` directory, shared
or an audience's, holds a file. It lists the tags the audience's operations use: those named in
`api-toolkit.openapi.tags` first, in that order, then the rest in natural, case-insensitive order:

```php
'tags' => ['User', 'Article'],
```

A tag's description is read from `<docs_path>/tags/<Tag>.md`, with the same per-audience override. In a filename,
`%`, `/`, `\`, `:`, `*`, `?`, `"`, `<`, `>`, `|`, control characters and a leading `.` are written as `%XX`, so
`Admin/Users` reads `tags/Admin%2FUsers.md`; everything else, spaces included, is kept. A configured tag that no
audience uses fails the export, as does a tag file that matches no tag, under the same rules as operation files.

### Metadata Invalidation

Schema metadata (column listings and definitions, index catalogues, relation lookups and the
model-to-resource map) is cached in the application's cache store and shared by every process. The Octane and queue
boundary flushes reset only each worker's per-operation state, so a schema or mapping change needs the shared entries
retired explicitly. Every metadata key is stored under a generation held in the same store; replacing the generation
makes every earlier entry unreachable in every process at once. A column listing that reads empty, as it does before
its table exists, is never stored.

Under Octane the boundary is the end of every request, task and tick; on a queue worker it is the end of every job
attempt, including one that fails or is released for a retry. It drops the memoised generation, the per-operation
index proofs, the in-memory column listings, column definitions and index catalogues, the field and eager-load memos
and the query parser's state. The compiled schemas, search plans, field-to-column maps and cast reflections come from
code alone, so they live for the worker and are rebuilt only when it restarts; reload Octane and queue workers on
every deploy. An application that runs its own reset can call `CacheManager::flush()`; a second reset is harmless.

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
tenant's schema separately, provided it purges the connection when it switches. Relation lookups and the resource map
come from code and are shared across tenants. Where each tenant has its own cache prefix, each has its own generation,
so invalidate per tenant.

The generation is replaced automatically when a migration run finishes. A run with nothing to migrate, or a
`--pretend` run, leaves the metadata warm. If the cache store cannot be written, the hook logs a warning instead of
failing the migration. Set `API_TOOLKIT_LIFECYCLE_MIGRATIONS=false` to turn the hook off.

The hook is not enough on its own: migrations run before the new release takes traffic, so workers still on the old
code can refill the new generation with their resource mappings and relation lookups. Run this on every deploy,
after the new release is live:

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
passes it to `parent::__construct($input)` rather than redeclaring or assigning `$input`. `make()` resolves the service
from the container and hands the input to the first constructor parameter whose class, interface or union type accepts
it, whatever its name; a constructor that cannot take it throws a `LogicException`.

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

$user = SignIn::make($payload)->runOrFail();
```

#### Payloads in Actions

Type-hint the payload on the action. It is validated and hydrated from the request before the action runs, a failure
renders as a `422`, and the exported request body is documented from its `rules()`. Outside HTTP, build it with
`from()` and an array.

```php
public function signIn(SignInPayload $payload): UserResource
{
    return new UserResource(SignIn::make($payload)->runOrFail());
}

SignInPayload::from(['email' => 'a@example.com', 'password' => 'secret']);
```

Form, multipart and query string values arrive as strings, so an `int`, `float` or `bool` property is cast from any
value the `integer`, `numeric`, `boolean`, `accepted` or `declined` rule accepts. A property its rules let be absent
needs a default; without one, `from()` throws a `LogicException` naming it.

`toArray()` of a payload built by `from()` returns only the validated fields the input sent, an explicit `null`
included, so a partial update persists them without resetting the rest. A payload built with `new` includes its
defaults:

```php
UpdateUserPayload::from(['email' => 'new@example.com'])->toArray(); // ['email' => 'new@example.com']
```

`messages()` and `attributes()` customise the messages and field names, as on a FormRequest. A `status.enum` entry
rewords an enum failure on `status`, whether `Rule::enum()` or the cast refused it:

```php
public static function messages(): array
{
    return ['email.unique' => 'That email is already registered.', 'status.enum' => 'Choose a listed status.'];
}

public static function attributes(): array
{
    return ['client_name' => 'organisation name'];
}
```

For Precognition, add Laravel's `HandlePrecognitiveRequests` middleware to the route. A `Precognition-Validate-Only`
header validates only the fields it names, and a request that passes answers `204` without running the action. A
precognitive `422` is Laravel's `{message, errors}` body, which Precognition clients read, rather than the error
envelope; this holds for a FormRequest too. Under `Precognition-Validate-Only` the first input to pass ends the request
before a second is validated, so an action should take one validated input.

#### Running a Service

`runOrFail()` returns the output (typed as `TOutput`) or throws, and is the usual choice in a controller: the failure
reaches the application's exception handler. A failed result with no captured exception throws a
`ServiceFailedException`, so a failure never comes back as output.

`run()` never throws for a business failure. It returns a `ServiceResult` for callers that branch on the outcome:

```php
$result = SignIn::make($payload)->run();

if ($result->failed()) {
    // $result->exception is the captured failure, or null when none was thrown
    return response()->json(['message' => 'The credentials are invalid.'], 401);
}

$user = $result->output();
```

`runOrFail()` is `run()->throw()->output()`. Both use the context given through `withContext()` and fire the same
lifecycle events.

A queued `ServiceCompleted` or `ServiceFailed` listener receives the result's exception and each side-effect error as a
`SerializedException`, which keeps its message, code, file, line and previous chain and names its class through
`originalClass()`, since an exception's trace and state, such as a validator, may not serialise.

`dispatch()` runs `authorize()` and `validate()`, then queues the service. A refusal throws from `dispatch()`, so an
HTTP action answers `403` or `422` and nothing is queued. The worker runs the whole lifecycle again with a `QUEUE`
context, since state can change before the job runs, so both hooks must be repeatable. At dispatch they see the context
given through `withContext()`, or an `INTERNAL` one. `dispatch()` returns Laravel's `PendingDispatch`, so `onQueue()`,
`onConnection()`, `delay()` and `afterCommit()` apply per call.

A failed attempt fires `onFailure()` and `ServiceFailed` and fails the job with the captured exception, which the worker
retries per `--tries` and `--backoff`. An `AuthorizationException` or `ValidationException` fails the job at once and is
not retried. Once the queue gives up on the job, because its attempts ran out, a refusal failed it or its last attempt
timed out, it calls `onFinalFailure()` with the exception. A timed-out attempt fires no other hook, as the worker kills
itself mid-run. Laravel deletes the job first, so `onFinalFailure()` runs at most once and must be idempotent; `run()`
and `runOrFail()` never call it. On the `sync` connection there is no worker to retry, so the failure is thrown to the
dispatching code when it releases the returned `PendingDispatch`, at the end of the statement unless it is kept in a
variable, or when the transaction commits for a job queued after commit.

#### Queued Services

Declare how a service is queued with Laravel's queue attributes and markers:

```php
#[Queue('reports')]
#[Timeout(900)]
#[Tries(1)]
final class ExportLedger extends Service implements ShouldBeEncrypted
{
    // ...
}
```

`#[Connection]`, `#[Queue]`, `#[Delay]`, `#[Tries]`, `#[Timeout]`, `#[Backoff]`, `#[MaxExceptions]`, `#[FailOnTimeout]`,
`#[CountCrashesAsExceptions]` and `ShouldQueueAfterCommit` apply, and an undeclared setting keeps the worker's. The
queue and connection come from the per-call `onQueue()` and `onConnection()`, then the service's attributes, then
`Queue::route()` for the service class, a parent, an interface or a trait, then `Queue::route(ServiceJob::class)`, then
the connection's default. Horizon and the failed job store list the job under the service's class.

The job is encrypted when the service implements `ShouldBeEncrypted` or its input is a `Payload` with a
`#[\SensitiveParameter]` parameter, so the secret is never stored in plain text in the queue or the failed job store.

`toJob()` builds the job without the pre-flight, for `Bus::batch()`, `Bus::chain()` and `Bus::dispatchAfterResponse()`.
A refusal fails its job, which cancels a batch that does not `allowFailures()`, and the batch's later jobs are skipped.
During a batched run, `$this->context()->batchId` holds the batch id for `Bus::findBatch()`:

```php
Bus::batch([ExportLedger::make($january)->by($actor)->toJob(), ExportLedger::make($february)->by($actor)->toJob()])
    ->name('ledger-export')
    ->withOption('actor', $actor->actorIdentifier())
    ->onQueue('reports')
    ->dispatch();
```

Laravel decides three things the toolkit leaves as they are:

- A batch pushes its jobs on its own `onQueue()` and `onConnection()`, so set a batched service's queue on the batch.
- In a chain, including one inside a batch, each later service keeps its declared or routed queue and connection over
  the chain's, so a worker must consume that queue.
- A chain serialises its later services into the first job and encrypts only that job, so a sensitive service later in
  a chain is encrypted in transit only when every job before it is.

#### Transactions

`$transactional` (default `true`) wraps the concerns, `prepare()` and `handle()` in a database transaction, and
`afterCommit()` runs once the outermost transaction has committed. The transaction runs once: a deadlock or other
concurrency error rolls it back and fails the run, firing `onFailure()` and `ServiceFailed`.

`$transactionAttempts` (default `1`) lets a top-level run retry a concurrency error, such as a deadlock, a PostgreSQL
serialization failure or a MySQL lock wait timeout. Each retry re-runs the concerns, `prepare()` and `handle()` in full,
but the rollback resets only the database: a model saved by the failed attempt still looks saved, so the retry can skip
its write and report success. A service nested in another transaction never retries; it fails with a
`DeadlockException`. On a transactional service a value below `1` fails the run with a `LogicException`. A service that
sets it above `1` must:

1. Load every model it writes inside the transaction, in `prepare()` or `handle()`, from keys in the input
   (`Order::query()->lockForUpdate()->findOrFail($this->input->orderId)`). Never write a model carried by the input or
   loaded in `authorize()` or `validate()`.
2. Keep no attempt-local state on the service, its concerns (which may be container singletons) or stateful observers,
   or reset it before its first use in each attempt. Concerns run before `prepare()`, so resetting in `prepare()` only
   covers state first used there or in `handle()`.
3. Do non-database work in `afterCommit()`, or register it inside the attempt with `DB::afterCommit()` or an
   after-commit job. Laravel discards the callbacks a rolled-back attempt registered, so never guard registration with a
   flag that outlives the attempt.

```php
final class ShipOrder extends Service
{
    protected int $transactionAttempts = 3;

    protected function handle(): mixed
    {
        $order = Order::query()->lockForUpdate()->findOrFail($this->input->orderId);

        $order->update(['status' => 'shipped']);

        DB::afterCommit(fn () => OrderShipped::dispatch($order));

        return $order;
    }
}
```

For any other service, retry the call rather than the transaction: run `Service::make(...)->run()` again with fresh
input, or `dispatch()` it and let the worker retry per `--tries` or the service's `#[Tries]` and `#[Backoff]`. Each
worker attempt starts from the dispatch-time input rather than the previous attempt's models, but models nested in an
`ArrayInput` or `Payload` are not re-fetched, so pass keys and keep external effects idempotent.

A service run inside a caller's transaction (another service, a `DB::transaction()` or a job that opens one) commits
only a savepoint, so its lock, `afterCommit()` and `ServiceCompleted` wait for the outermost commit, and a rollback
releases the lock and runs neither. A `$transactional = false` service behaves the same. The result `run()` returns
there has empty `sideEffectErrors()`; errors from `afterCommit()` are reported and carried on the `ServiceCompleted`
result. A `ServiceCompleted` or `ServiceFailed` listener exception is reported, never thrown.

The lock is held for the rest of the caller's transaction, so `$lockExpiration` must cover it, and a second run with the
same lock key inside it fails with `LockUnavailableException`. On the `database` cache store on MySQL or PostgreSQL,
give locks a `lock_connection` of their own: a lock row written on the caller's connection stays uncommitted, so a
competing run blocks until the caller's transaction ends instead of failing with `LockUnavailableException`. Queue
follow-up work from `afterCommit()`, which runs once, after the attempt that commits, and never after a rollback.
`dispatch()` from `handle()` pushes at once unless the queue connection sets `after_commit` or the service implements
`ShouldQueueAfterCommit`, so the job can run for a write that never commits. Transactions interleaved on two connections
are not supported: the deferred work attaches to the most recent open transaction on any connection.

#### Reading the Run Context

During a run, `$this->context()` returns the `ServiceContext` in use: the one given through `withContext()`, the
`INTERNAL` context `run()` builds when none was given, or the `QUEUE` context rebuilt on the worker. Every hook,
`concerns()` and `lockId()` see the same instance, and the `ServiceCompleted` and `ServiceFailed` events report it.
During a run `actor()` returns the context's actor. Calling `context()` outside a run throws a `LogicException`.

At an HTTP edge, `ServiceContext::fromRequest()` builds an `HTTP` context, so the service can read request details
without seeing the request:

```php
$order = PlaceOrder::make($payload)
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
`case WEB`, throws a `LogicException`, as does a rule nested under an enum property's key, such as `status.name`, since
the property is read from the whole value.

A model attribute cast to a pure enum also uses the lowercase name in `filters` and in repository writes, though
Eloquent stores the declared case name. `{"platform": {"$in": ["web", "mobile_app"]}}` matches rows storing `WEB` and
`MOBILE_APP`, on the root model or through a relation, and any other spelling, `MOBILE_APP` included, is refused with
a 422.
`persist()` accepts the lowercase name or the case itself, and throws a `ValueError` for a string that names no case.

### Notification Audit Log

When `api-toolkit.notifications.enable_logging` is `true` (the default), every `NotificationSending` event is logged at
`debug` and every `NotificationSent` event at `info`. Each line records the notification class, the notifiable type, the
notifiable id (when the notifiable is an Eloquent model with a key) and the channel. List classes in
`api-toolkit.notifications.excluded_classes` to skip them.

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
API_TOOLKIT_NOTIFICATIONS_LOG_CHANNEL=notifications
```

## Requirements

- PHP ^8.3
- Laravel 13.39+
- MySQL 8.4+, where the search layer is used
- PostgreSQL 15+

MariaDB is untested: it reports its own driver name, so search needs a driver registered for it, and a prefix or
ignored index can count as backing a sort.

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
