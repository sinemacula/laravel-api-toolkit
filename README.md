# Laravel API Toolkit

[![Latest Stable Version](https://img.shields.io/packagist/v/sinemacula/laravel-api-toolkit.svg)](https://packagist.org/packages/sinemacula/laravel-api-toolkit)
[![Build Status](https://github.com/sinemacula/laravel-api-toolkit/actions/workflows/tests.yml/badge.svg?branch=master)](https://github.com/sinemacula/laravel-api-toolkit/actions/workflows/tests.yml)
[![Quality Gates](https://github.com/sinemacula/laravel-api-toolkit/actions/workflows/quality-gates.yml/badge.svg?branch=master)](https://github.com/sinemacula/laravel-api-toolkit/actions/workflows/quality-gates.yml)
[![Maintainability](https://qlty.sh/gh/sinemacula/projects/laravel-api-toolkit/maintainability.svg)](https://qlty.sh/gh/sinemacula/projects/laravel-api-toolkit)
[![Code Coverage](https://qlty.sh/gh/sinemacula/projects/laravel-api-toolkit/coverage.svg)](https://qlty.sh/gh/sinemacula/projects/laravel-api-toolkit)
[![Total Downloads](https://img.shields.io/packagist/dt/sinemacula/laravel-api-toolkit.svg)](https://packagist.org/packages/sinemacula/laravel-api-toolkit)

The Laravel API Toolkit is a comprehensive package designed to simplify the development of RESTful APIs in Laravel. It
provides tools to enhance API functionality, improve error handling, and ensure consistent data output, making API
development faster and more reliable.

## Features

- **Exception Handling**: Implements a custom exception handler that captures and formats all exceptions for consistent
  API error responses, preserving the intended HTTP status codes.
- **Queryable Models**: Allows fine-tuned control over which fields, filters, relations, and orderings are exposed via
  your API endpoints, enhancing security and customization.
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
and covers exception rendering strategy, sensitive-key redaction, repository caching, query parser limits,
deferred write behaviour, middleware toggles, and more.

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

**Filtering** - apply column-level filters using operator tokens:

```http
GET /users?filters[status][$eq]=active&filters[created_at][$ge]=2024-01-01
```

Available built-in operator tokens: `$eq`, `$neq`, `$gt`, `$lt`, `$ge`, `$le`, `$like`, `$in`, `$between`,
`$contains`, `$null`, `$notNull`.

**Sorting** - sort by one or more columns, with optional direction:

```http
GET /users?order=last_name,first_name:desc
```

**Limit clamping** - client-supplied `?limit` values are silently clamped to the `api-toolkit.parser.max_limit`
ceiling (default 100). Values exceeding the ceiling are reduced; the request is never rejected:

```http
GET /users?limit=200   // clamped to 100
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
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Count;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;
use SineMacula\ApiToolkit\Schema\Sum;

class UserResource extends ApiResource
{
    const RESOURCE_TYPE = 'user';

    protected static array $default = ['id', 'name', 'email', 'created_at'];

    public static function schema(): array
    {
        return Field::set(
            Field::scalar('name')->filterable()->sortable(),
            Field::scalar('email')->filterable(),
            Field::timestamp('created_at')->sortable(),
            Field::accessor('full_name', 'getFullName'),
            Relation::to('organization', OrganizationResource::class)->traversable(),
            Count::of('memberships'),
            Sum::of('orders', 'total'),
        );
    }
}
```

Fields marked `filterable()` or `sortable()` are exposed under the allowlist posture. Relations marked
`traversable()` can be targeted by nested filters. The `$default` static property declares the fields returned
when the client sends no `?fields` parameter.

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

**Schema validation** - enable `api-toolkit.resources.validate_schemas` (recommended for non-production
environments) to have all registered schemas validated at boot time via the `api-toolkit:validate-schemas`
Artisan command or automatically on first request.

---

### Repositories

Extend `ApiRepository` to get a repository wired to the API query parser, eager-load planning, and
pagination out of the box:

```php
use SineMacula\ApiToolkit\Repositories\ApiRepository;

class UserRepository extends ApiRepository
{
    protected function model(): string
    {
        return User::class;
    }
}
```

Call `withApiCriteria()` before any read to apply the parsed filters, sorts, eager loads, and limit from the
current request automatically:

```php
$users = $repository->withApiCriteria()->paginate();
```

**Allowlist posture** - by default (`api-toolkit.repositories.query_posture = 'allowlist'`) only schema fields
declared `filterable()`, `sortable()`, or `traversable()` are accepted. Undeclared keys are rejected with a
validation error (controlled by `api-toolkit.repositories.reject_undeclared`). Switch to `'blocklist'` to
restore the opt-out behaviour and exclude specific columns via `api-toolkit.repositories.searchable_exclusions`.

**Cacheable trait** - add per-query transparent caching to any `ApiRepository` subclass:

```php
use SineMacula\ApiToolkit\Repositories\Concerns\Cacheable;

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
        $registry->override('$like', new CaseInsensitiveLikeOperator);
    }
}
```

A `FilterOperator` is any class implementing `SineMacula\ApiToolkit\Contracts\FilterOperator`, or a closure
with the same signature. Operators registered via `register()` throw `InvalidArgumentException` if the token
is already taken; use `override()` to replace unconditionally.

---

### Exception Handling

Register the exception handler once in `bootstrap/app.php`:

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
- `'json_when_expected'` - renders JSON only when `Accept: application/json` is present.

**Sensitive-key redaction** - request data written to the exception log is automatically scanned and values
whose keys match any substring in `api-toolkit.exceptions.sensitive_keys` (default: `password`, `token`,
`secret`, `authorization`) are replaced with `[redacted]` before being written to the log. Add application-specific
keys to that array in your published config to extend coverage.

---

### Middleware

The service provider registers the following middleware automatically. Each registration can be disabled or
scoped independently in `api-toolkit.middleware`:

- **`maintenance_mode_swap`** - JSON `503` maintenance responses with an `except` URI allowlist.
- **`detect_capabilities`** - resolves typed request capabilities (trashed/export/stream) once per request.
- **`json_pretty_print`** - opt-in pretty-printed JSON responses via a query parameter.
- **`throttle`** - API-friendly rate-limit responses; auto-selects the Redis variant when Redis is the cache driver.

All four middleware accept `'scope': 'global'` (default, pushed to the global stack) or `'scope': 'api'`
(appended to the `api` middleware group only).

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

The schema introspector resolves filterable columns, sortable columns, traversable relations, and all field
keys for any registered resource without instantiating it. It is used internally by `ApiCriteria` and is
available for injection:

```php
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;

public function __construct(private SchemaIntrospectionProvider $introspector) {}
```

An OpenAPI 3.1 components document can be generated from the registered resource map and operator grammar:

```bash
php artisan api-toolkit:export-openapi
php artisan api-toolkit:export-openapi --output=openapi.json
```

---

### Upgrading

See [UPGRADE.md](UPGRADE.md) for version-by-version migration guides, including breaking changes and
the steps required to move from 1.x to 2.x.

## Requirements

- PHP ^8.3
- Laravel 12+

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
