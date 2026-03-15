# Laravel API Toolkit: Architectural Issues

This document catalogues architectural, structural, and quality issues identified during a deep review of the
`sinemacula/laravel-api-toolkit` repository. Each issue is written as a brief for the Blueprint workflow to produce a
PRD.

---

## ISSUE-07: Reflection-Based Relation Detection in SchemaIntrospector

**Severity:** Medium
**File(s):** `src/Services/SchemaIntrospector.php` (lines 124-142)

### Problem

`SchemaIntrospector::isRelation()` detects model relations by:

1. Checking `method_exists()` and `is_callable()`.
2. Invoking the method directly (`$model->{$key}()`).
3. Checking if the return value is an instance of `Relation`.
4. Catching `LogicException` / `ReflectionException` as a fallback.

While relation detection has been centralised into `SchemaIntrospector` (previously duplicated across `ApiCriteria` and
`ApiRepository`), the core approach is unchanged:

- **Slow** -- method invocation is expensive, especially when checking many attributes/filters per request.
- **Error-prone** -- invoking a method that happens to share a name with a relation but has side effects (e.g., a scope
  method) could produce unexpected behavior.
- **Silent failures** -- exceptions are caught and logged, meaning genuine errors in relation definitions are hidden.

Laravel 11+ provides `Model::resolveRelationUsing()` and the model's `$relations` property, and there are more
reliable ways to detect relations (e.g., checking the method return type hint).

### Desired Outcome

Replace reflection-based detection with a more reliable approach:

1. **Return type inspection** -- check if the method has a return type hint that is a subclass of
   `Illuminate\Database\Eloquent\Relations\Relation`. This avoids invoking the method entirely.
2. **Schema-driven detection** -- since `ApiResource::schema()` already declares which fields are relations via
   `Relation::to()`, use the schema as the source of truth rather than probing the model.
3. **Explicit relation registry** -- allow models to declare their relations in a static property or method that returns
   the list, avoiding any runtime probing.

### Constraints

- Must remain compatible with models that don't use return type hints (graceful fallback).
- Must not break polymorphic relation detection (MorphTo, MorphToMany).
- Cache invalidation is now handled by `CacheManager::flush()`, which clears memo caches and `SchemaIntrospector`
  instance state.

---

## ISSUE-11: Service Provider Uses Fragile Kernel Middleware Manipulation

**Severity:** Medium
**File(s):** `src/ApiServiceProvider.php` (lines 223-249)

### Problem

The service provider replaces Laravel's `PreventRequestsDuringMaintenance` middleware by iterating the global middleware
array and swapping the class reference:

```php
$globalMiddleware = $kernel->getGlobalMiddleware();
foreach ($globalMiddleware as $key => $middleware) {
    if ($middleware === LaravelPreventRequestsDuringMaintenance::class) {
        $globalMiddleware[$key] = PreventRequestsDuringMaintenance::class;
    }
}
$kernel->setGlobalMiddleware($globalMiddleware);
```

This is fragile because:

1. It assumes the middleware is registered by its fully-qualified class name (could be aliased).
2. It depends on `getGlobalMiddleware()` and `setGlobalMiddleware()` being available on the kernel (internal API that
   could change between Laravel versions).
3. It also pushes `JsonPrettyPrint` as global middleware, meaning it runs on every request even when `?pretty` is not
   set.
4. The throttle middleware alias is overridden globally, which could conflict with applications that have custom
   throttle implementations.

### Desired Outcome

1. Use Laravel's recommended approach for middleware customisation (e.g., `$middleware->replace()` in the application's
   `bootstrap/app.php`, or document the recommended way for consumers to configure this).
2. Make the `JsonPrettyPrint` middleware conditional -- only add it to API route groups rather than globally.
3. Make the throttle middleware alias configurable via the `api-toolkit.php` config.
4. Document which middleware the package registers and how to override them.

### Constraints

- Must work with Laravel 11+ middleware configuration patterns.
- Must not force consumers to change their existing `bootstrap/app.php` if they don't need to customise.
- The maintenance mode override is valuable and should remain easy to opt into.

---

## ISSUE-12: Request Macros Create Implicit Global API Surface

**Severity:** Low
**File(s):** `src/ApiServiceProvider.php` (lines 184-216)

### Problem

The service provider registers 7 macros on the `Request` facade:

- `includeTrashed()`, `onlyTrashed()` -- soft-delete filtering
- `expectsExport()`, `expectsCsv()`, `expectsXml()`, `expectsPdf()` -- export format detection
- `expectsStream()` -- SSE stream detection

These macros:

1. Are globally available on every `Request` instance, polluting the API surface.
2. Are not discoverable via IDE autocomplete without a helper file (e.g., `_ide_helper.php`).
3. Cannot be type-checked by static analysis (PHPStan) without custom extensions.
4. Have no namespacing -- `expectsCsv()` could conflict with a consumer's own macro of the same name.
5. Create an implicit dependency: code using `$request->expectsExport()` has no explicit import showing it depends on
   the API toolkit.

### Desired Outcome

Replace macros with one of:

1. **A dedicated `ApiRequest` class** extending `FormRequest` that provides these methods natively.
2. **A `RequestCapabilities` helper class** with static methods: `RequestCapabilities::expectsExport($request)`.
3. **A trait** that can be applied to form requests that need these capabilities: `use DetectsExportFormat`.

The macros can be retained for backward compatibility but deprecated in favour of the explicit approach.

### Constraints

- Macros are used by consuming applications. Removal must be a major version change; deprecation in a minor version.
- The replacement must be equally convenient for controllers and middleware.
- PHPDoc annotations on the macro registrations should be added for IDE support in the interim.

---

## ISSUE-13: Missing Exception Types in ApiExceptionHandler Mapping

**Severity:** Medium
**File(s):** `src/Exceptions/ApiExceptionHandler.php` (lines 87-113), `src/Exceptions/`

### Problem

The exception handler maps only ~12 exception types. Several common Laravel/Symfony exceptions fall through to the
generic `UnhandledException` (HTTP 500):

1. **`QueryException`** -- database errors (constraint violations, connection failures) become generic 500s. A unique
   constraint violation should be a 409 Conflict.
2. **`HttpException` (generic)** -- Symfony's base `HttpException` with any status code is not mapped, so a manually
   thrown `abort(423)` becomes a 500.
3. **`ThrottleRequestsException`** -- Laravel's own throttle exception (distinct from Symfony's
   `TooManyRequestsHttpException`) may not be caught.
4. **`MaintenanceModeException`** -- the toolkit has its own `MaintenanceModeException` but the handler doesn't map
   Symfony's `ServiceUnavailableHttpException` to it.
5. **`PaymentRequiredException`**, **`ConflictException`**, **`GoneException`**, **`PreconditionFailedException`** --
   common HTTP semantics with no toolkit exception classes.

Additionally, the `ErrorCode` enum only has 14 codes with gaps in the numbering. There is no mechanism for consuming
applications to add their own error codes.

### Desired Outcome

1. Add exception mappings for: `QueryException` (map unique violations to 409, others to 500), generic `HttpException`
   (preserve the original status code), `MaintenanceModeException` variants.
2. Add new exception classes for commonly needed HTTP statuses: `ConflictException` (409), `GoneException` (410),
   `PayloadTooLargeException` (413), `UnprocessableEntityException` (422, distinct from validation), `LockedException`
   (423), `ServiceUnavailableException` (503).
3. Make `ErrorCode` extensible -- either via a registration API or by allowing applications to provide their own enum
   implementing `ErrorCodeInterface`.

### Constraints

- New exception classes must follow the existing pattern (extend `ApiException`, define `CODE` and `HTTP_STATUS`
  constants).
- New mappings must not change the behavior of currently mapped exceptions.
- Error code extensibility must not break the translation key pattern.

---

## ISSUE-17: HttpStatus Enum Duplicates Symfony Constants

**Severity:** Low
**File(s):** `src/Enums/HttpStatus.php`

### Problem

The `HttpStatus` enum replicates all HTTP status codes that are already available as constants in
`Symfony\Component\HttpFoundation\Response::HTTP_*`. This creates:

1. **Maintenance burden** -- when new HTTP status codes are standardised, both Symfony and this enum need updating.
2. **Confusion** -- developers may use either `HttpStatus::OK` or `Response::HTTP_OK`, creating inconsistency.
3. **Deprecated codes included** -- `USE_PROXY` (305) and `SWITCH_PROXY` (306) are included with deprecation comments
   but no `#[\Deprecated]` attribute.
4. **No category methods** -- there's no way to query "is this a client error?" or "is this a server error?" without
   manual range checks.

### Desired Outcome

Either:

1. **Deprecate and remove** `HttpStatus` in favour of Symfony's constants (simpler, less code to maintain).
2. **Or enhance** `HttpStatus` to justify its existence with value-adds: `isClientError(): bool`,
   `isServerError(): bool`, `isSuccess(): bool`, `getReasonPhrase(): string`, and mark deprecated codes with the PHP
   8.4 `#[\Deprecated]` attribute.

### Constraints

- `HttpStatus` is used by all exception classes (`HTTP_STATUS` constant) and the `Controller` response methods.
  Removal requires updating all references.
- If retained, must stay in sync with the HTTP specification.

---

## ISSUE-18: ServiceInterface Contract is Too Minimal

**Severity:** Low
**File(s):** `src/Services/Contracts/ServiceInterface.php`

### Problem

`ServiceInterface` defines only two methods:

```php
public function run(): bool;
public function getStatus(): ?bool;
```

This is insufficient to type-hint or mock services effectively:

1. No access to the payload or result data.
2. No way to check if the service uses transactions or locking.
3. The `?bool` return type for `getStatus()` is ambiguous (`null` = not run, `true` = success, `false` = failure), but
   this semantic is not documented.
4. The `run()` return type is `bool` but the concrete `Service::run()` can throw, which isn't expressed in the
   interface.

Note: The concrete `Service` class now provides `prepare()`, `success()`, and `failed()` lifecycle methods, and the
`ServiceConcern` interface provides an explicit contract for cross-cutting concerns. However, `ServiceInterface` itself
remains minimal, limiting the ability to type-hint against the interface when these lifecycle methods are needed.

### Desired Outcome

Expand the interface to better represent the service contract:

```php
interface ServiceInterface {
    public function run(): bool;
    public function getStatus(): ?bool;
    public function prepare(): void;
    public function success(): void;
    public function failed(\Throwable $exception): void;
}
```

Or consider a result object instead of `bool`:

```php
interface ServiceInterface {
    public function run(): ServiceResult;
}
```

Where `ServiceResult` encapsulates success/failure, status messages, and any output data.

### Constraints

- Expanding the interface is a breaking change for any class implementing `ServiceInterface` directly.
- The `ServiceResult` approach is a larger change that should be planned as a major version feature.
- The `@throws` PHPDoc should be added to the interface's `run()` method regardless.

---

## ISSUE-19: Notification Listener Lacks Deduplication and Rate Limiting

**Severity:** Low
**File(s):** `src/Listeners/NotificationListener.php`

### Problem

The `NotificationListener` logs every `NotificationSending` and `NotificationSent` event equally:

1. **No deduplication** -- if a notification is sent to 1,000 users, 2,000 log entries are created (1,000 sending +
   1,000 sent).
2. **No rate limiting** -- a burst of notifications can flood both the database logger and CloudWatch.
3. **No sampling** -- in high-throughput systems, logging every single notification is expensive and rarely necessary.
4. **No filtering** -- there's no way to configure which notification classes should be logged.

### Desired Outcome

1. Add configurable log levels per notification event type (e.g., `NotificationSending` at `debug`, `NotificationSent`
   at `info`).
2. Add a notification class filter in config: `api-toolkit.notifications.logged_classes` (allowlist) or
   `api-toolkit.notifications.excluded_classes` (blocklist).
3. Add optional batched logging for high-throughput scenarios.
4. Consider adding aggregate metrics (count per notification class per minute) instead of individual log entries.

### Constraints

- Must remain backward compatible (default: log everything as currently).
- Must not impact notification delivery performance.
- CloudWatch integration must respect CloudWatch's batch limits.

---

## ISSUE-21: Test Suite Only Uses SQLite -- No Multi-Database Coverage

**Severity:** Low
**File(s):** `tests/TestCase.php`, `phpunit.xml.dist`

### Problem

The entire test suite runs against in-memory SQLite. Several features have database-specific behavior that is not
tested:

1. **The `$contains` filter operator** -- maps to `'contains'` in the operator map, which is not standard SQL. Its
   behavior likely differs between MySQL (`JSON_CONTAINS`), PostgreSQL (`@>`), and SQLite.
2. **Transaction handling** in `Service` -- the `TransactionConcern` wraps the lifecycle in `DB::transaction()` with
   configurable retry count, but transaction behavior varies by database engine.
3. **`inRandomOrder()`** -- uses `RANDOM()` on SQLite but `RAND()` on MySQL.
4. **Cursor pagination** -- behavior varies by database.
5. **`$between` operator** -- edge cases differ by database collation and type.

### Desired Outcome

1. Add a CI matrix that runs the integration test suite against MySQL and PostgreSQL in addition to SQLite.
2. Tag database-specific tests with PHPUnit groups (`@group mysql`, `@group pgsql`) so they can be run selectively.
3. Document which features are database-specific in the config and README.
4. Add a database compatibility matrix to the documentation.

### Constraints

- The default test command (`composer test`) should remain fast and dependency-free (SQLite).
- Multi-database CI can use Docker services (MySQL 8.x, PostgreSQL 16.x).
- Must not increase test suite runtime significantly for the default case.

---

## ISSUE-22: WritePool Silently Drops Records on Insert Failure

**Severity:** Medium
**File(s):** `src/Repositories/Concerns/WritePool.php` (lines 70-81)

### Problem

When `WritePool::flush()` encounters an insert failure, it logs the error but does not re-throw the exception:

```php
try {
    DB::table($table)->insert($chunk);
} catch (\Throwable $e) {
    Log::error("WritePool flush failed for table [{$table}]", [
        'table'      => $table,
        'chunk_size' => count($chunk),
        'error'      => $e->getMessage(),
    ]);
}
```

After the loop completes, the buffer is unconditionally cleared (`$this->buffer = []`), meaning failed records are
permanently lost with no mechanism to recover or retry them.

This creates several issues:

1. **Silent data loss** -- callers have no way to know that records were dropped. The `flush()` method returns `void`
   with no error signal.
2. **No retry mechanism** -- transient failures (e.g., temporary database lock contention, connection timeout) cause
   permanent data loss rather than being retried.
3. **No failed-record reporting** -- there is no way to inspect which records failed or why, beyond the log message.
4. **Partial flush ambiguity** -- if chunk 1 of 5 fails but chunks 2-5 succeed, the caller believes all records were
   persisted.

### Desired Outcome

1. **Make failure behavior configurable** -- add a config option (e.g., `api-toolkit.deferred_writes.on_failure`) with
   strategies: `log` (current behavior), `throw` (re-throw on first failure), `collect` (complete all chunks and return
   a failure report).
2. **Return a flush result** -- change `flush()` to return a `WritePoolFlushResult` value object containing success
   count, failure count, and failed record details.
3. **Add retry support** -- allow configurable retry attempts per chunk for transient failures.
4. **Preserve failed records** -- optionally retain failed records in the buffer for later retry rather than clearing
   them unconditionally.

### Constraints

- The default behavior must remain backward compatible (log-and-continue is the current contract).
- Must not significantly increase memory usage when retaining failed records.
- Must work with the existing `WritePoolFlushSubscriber` lifecycle integration.
