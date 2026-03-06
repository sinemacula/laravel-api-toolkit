# Laravel API Toolkit: Architectural Issues

This document catalogues architectural, structural, and quality issues identified during a deep review of the
`sinemacula/laravel-api-toolkit` repository. Each issue is written as a brief for the Blueprint workflow to produce a
PRD.

---

## ISSUE-01: Decompose the ApiResource God Class

**Severity:** High
**File(s):** `src/Http/Resources/ApiResource.php` (976 lines)

### Problem

`ApiResource` is the single largest and most complex class in the toolkit at 976 lines with 40+ methods. It
concentrates at least five distinct responsibilities in one class:

1. **Schema compilation and caching** -- parsing the static `schema()` method into a normalised array and caching it
   per-class.
2. **Eager-load planning** -- walking the relation tree to produce `with()` and `withCount()` maps, including circular
   reference detection via a visited set.
3. **Field resolution** -- determining which fields to include based on API query parameters, defaults, fixed fields,
   and `:all` overrides.
4. **Value resolution** -- resolving each field's value through multiple strategies (scalar, accessor, computed,
   relation, timestamp, date) and applying transformer pipelines.
5. **Guard evaluation** -- running guard callables to conditionally include/exclude fields.

This violates the Single Responsibility Principle and makes the class difficult to test in isolation, extend, or
maintain. Any change to eager-load planning risks breaking field resolution, and vice versa.

### Desired Outcome

Break `ApiResource` into focused, composable collaborator classes:

- **SchemaCompiler** -- compiles and caches the raw `schema()` array into a normalised form.
- **EagerLoadPlanner** -- walks the compiled schema to produce `with()` and `withCount()` arrays, owns the visited-set
  circular reference logic.
- **FieldResolver** -- determines which fields to include for a given request, merging defaults, fixed fields, explicit
  inclusions/exclusions.
- **ValueResolver** -- resolves individual field values using the appropriate strategy (scalar, accessor, computed,
  relation).
- **GuardEvaluator** -- evaluates guards and returns pass/fail for each field.

`ApiResource` becomes a thin orchestrator that delegates to these collaborators. The public API
(`eagerLoadMapFor()`, `eagerLoadCountsFor()`, `resolveFields()`, `toArray()`) must not change so that consuming
applications are unaffected.

### Constraints

- The public static methods (`eagerLoadMapFor`, `eagerLoadCountsFor`, `getCompiledSchema`, `resolveFields`,
  `getDefaultFields`, `getAllFields`) are called by `ApiCriteria` and consumer code. Their signatures must remain stable
  or be deprecated with backward-compatible wrappers.
- The static `$schemaCache` must remain process-lifetime cached (no per-request recompilation).
- The `OrdersFields` trait integration must continue to work via `resolveFields()`.
- Performance must not regress: the current single-pass `toArray()` should not become multiple passes.

---

## ISSUE-02: Incomplete ApiResourceInterface Contract

**Severity:** High
**File(s):** `src/Contracts/ApiResourceInterface.php`

### Problem

`ApiResourceInterface` only defines two methods:

```php
public static function getResourceType(): string;
public static function getDefaultFields(): array;
```

Yet `ApiResource` exposes a much larger implicit contract that `ApiCriteria`, `PolymorphicResource`, and consumer code
rely on:

- `schema(): array` (static, abstract)
- `getAllFields(): array` (static)
- `resolveFields(): array` (static)
- `eagerLoadMapFor(array $fields): array` (static)
- `eagerLoadCountsFor(?array $aliases): array` (static)
- `getCompiledSchema(): array` (static)

Because these methods are not on the interface, there is no compile-time guarantee that any class implementing
`ApiResourceInterface` actually provides them. `ApiCriteria` calls `$resource::eagerLoadMapFor()` and
`$resource::getAllFields()` after only checking `is_subclass_of($resource, ApiResource::class)`, creating tight coupling
to the concrete class rather than the interface.

### Desired Outcome

Expand `ApiResourceInterface` (or introduce a companion `SchemaAwareResourceInterface`) to include the full set of
methods that external collaborators depend on. This allows type-safe usage across the codebase and enables alternative
resource implementations that don't extend `ApiResource`.

### Constraints

- Existing implementations of `ApiResourceInterface` (consumer applications) must not break. If the interface is
  expanded, consider splitting into a base `ApiResourceInterface` and an extended `SchemaAwareResourceInterface` so that
  only resources used with `ApiCriteria` need the full contract.
- Static method interfaces require PHP 8.0+ (already met by `^8.3` requirement).

---

## ISSUE-03: No Schema Validation at Compile Time

**Severity:** Medium
**File(s):** `src/Http/Resources/ApiResource.php` (method `getCompiledSchema`)

### Problem

The `schema()` method on each resource returns an `array<string, mixed>` with no validation. The compiled schema is
cached and used throughout the request lifecycle. If a resource defines a malformed schema (e.g., a `Relation::to()`
pointing to a non-existent resource class, a `Field::scalar()` with an empty string, or a `Count::of()` referencing a
non-existent relation), the error surfaces as a cryptic runtime failure deep in field resolution or eager-load planning.

There is no boot-time or compile-time validation that:

1. All referenced resource classes exist and implement the correct interface.
2. All field keys are non-empty strings.
3. Count/relation keys reference valid model relations.
4. No duplicate field keys exist.
5. Guards and transformers are valid callables.

### Desired Outcome

Introduce a `SchemaValidator` that can be run:

1. **At compile time** -- as part of `getCompiledSchema()`, with clear exception messages identifying the resource class
   and the specific schema entry that is invalid.
2. **As a dev-mode artisan command** -- `php artisan api:validate-schemas` that scans all configured resources and
   reports issues.
3. **In test helpers** -- a `assertSchemaValid(ResourceClass::class)` helper for test suites.

The validator should check: class existence, interface compliance, field key uniqueness, callable validity, and relation
existence (where determinable without database access).

### Constraints

- Validation must be optional in production (the artisan command and test helper are opt-in).
- Compile-time validation in `getCompiledSchema()` should be gated behind `app.debug` to avoid production overhead.
- The schema definition classes (`Field`, `Relation`, `Count`) should gain type-safe factory methods that prevent
  invalid definitions at construction time where possible.

---

## ISSUE-04: Cache Memo Entries Cached Forever Without Invalidation

**Severity:** Medium
**File(s):** `src/Repositories/Criteria/ApiCriteria.php` (line 421), `src/Repositories/ApiRepository.php` (lines 274, 488)

### Problem

Several critical metadata lookups are cached forever using `Cache::memo()->rememberForever()`:

1. **Model relation detection** (`ApiCriteria::isRelation`) -- caches whether a method on a model is a valid Eloquent
   relation. If the model class is updated (e.g., a relation is added or removed), the cached result is stale for the
   entire process lifetime.
2. **Repository model casts** (`ApiRepository::storeCastsInCache`) -- caches the resolved cast map for each model. If
   model casts change, stale data persists.
3. **Schema compilation** (`ApiResource::$schemaCache`) -- a static in-memory cache that never invalidates.

While `Cache::memo()` is process-lifetime only (not cross-request in typical PHP-FPM), in long-running processes like
queue workers, Octane, or Reverb, stale caches can cause incorrect filtering, broken eager-loading, or wrong attribute
casting.

### Desired Outcome

1. Introduce a `SchemaCache` service that wraps caching with explicit invalidation hooks.
2. Provide a `clearApiToolkitCaches()` method (or artisan command) that flushes all memo caches.
3. In Octane/queue worker contexts, automatically flush caches between requests via a middleware or listener.
4. Consider using `rememberForever()` with a configurable TTL instead, allowing production deployments to set a
   reasonable expiry.

### Constraints

- Must not degrade performance for standard PHP-FPM deployments where process-lifetime caching is correct.
- Cache invalidation must be opt-in (not forced on every request).
- The existing `CacheKeys` enum should be extended to support the invalidation API.

---

## ISSUE-05: Hardcoded Filter Operators with No Extension Mechanism

**Severity:** Medium
**File(s):** `src/Repositories/Criteria/ApiCriteria.php` (lines 44-77)

### Problem

`ApiCriteria` defines three operator maps as private instance properties:

- `$conditionOperatorMap` -- 14 filter operators (`$eq`, `$neq`, `$like`, `$in`, `$between`, etc.)
- `$logicalOperatorMap` -- 2 logical operators (`$and`, `$or`)
- `$relationalMethodMap` -- 2 relational operators (`$has`, `$hasnt`)

These are hardcoded with no mechanism for consuming applications to:

1. Add custom operators (e.g., `$regex`, `$startsWith`, `$endsWith`, `$jsonPath`).
2. Override existing operator behavior (e.g., changing `$like` to use `ILIKE` on PostgreSQL).
3. Remove operators to restrict the query API surface.

The `$contains` operator maps to `'contains'` but this is not a standard SQL operator -- its implementation is buried in
the `AppliesFilterConditions` concern and the behavior is database-specific. There is no documentation or validation of
which operators work on which database drivers.

### Desired Outcome

Introduce an operator registry pattern:

1. **OperatorRegistry** class that holds condition, logical, and relational operator definitions.
2. **Registration API** -- `ApiCriteria::registerOperator('$regex', RegexOperator::class)` or via config.
3. **Operator interface** -- `FilterOperatorInterface` with `apply(Builder $query, string $column, mixed $value): void`
   so each operator is self-contained.
4. **Database-aware operators** -- operators can declare which database drivers they support, with clear error messages
   when used against an unsupported driver.

### Constraints

- The existing operator set must remain the default (no breaking changes for existing consumers).
- The operator registry must be resolvable from the service container for testability.
- Performance: operator lookup should remain O(1) (use a hash map, not iteration).
- The `$contains` operator needs documentation clarifying its database-specific behavior.

---

## ISSUE-06: JsonPrettyPrint Middleware Decode/Re-encode Inefficiency

**Severity:** Low
**File(s):** `src/Http/Middleware/JsonPrettyPrint.php`

### Problem

The `JsonPrettyPrint` middleware (line 28) decodes and re-encodes the entire JSON response body:

```php
$response->setContent(json_encode(json_decode($response->getContent()), JSON_PRETTY_PRINT));
```

This has several issues:

1. **Performance** -- double JSON processing on every pretty-printed response. For large collections (thousands of
   items), this is wasteful.
2. **Error handling** -- if `json_decode()` fails (non-JSON response accidentally hitting this middleware), the response
   becomes `null`.
3. **Already handled elsewhere** -- `ApiExceptionHandler::renderApiExceptionWithJson()` already checks the `pretty`
   parameter and sets `JSON_PRETTY_PRINT` on the response options. The middleware duplicates this for non-exception
   responses.

### Desired Outcome

Replace the decode/re-encode approach with one of:

1. **Set encoding options upstream** -- configure `JsonResponse::setEncodingOptions()` early in the pipeline so all JSON
   responses inherit the pretty-print flag without re-encoding.
2. **Use a response macro** -- register a `Response::macro('prettyJson', ...)` that sets the option once.
3. **Guard against non-JSON** -- if the middleware is retained, add a content-type check and error handling.

### Constraints

- Must work for both `JsonResponse` and `Response` with JSON content.
- Must not break streaming responses or non-JSON responses.
- The `?pretty=true` query parameter must remain the trigger.

---

## ISSUE-07: Reflection-Based Relation Detection in ApiCriteria and ApiRepository

**Severity:** Medium
**File(s):** `src/Repositories/Criteria/ApiCriteria.php` (line 419-436), `src/Repositories/ApiRepository.php` (lines
284-308)

### Problem

Both `ApiCriteria::isRelation()` and `ApiRepository::resolveCastForRelation()` detect model relations by:

1. Checking `method_exists()` and `is_callable()`.
2. Invoking the method via reflection (`$model->{$key}()` or `$method->invoke()`).
3. Checking if the return value is an instance of `Relation`.
4. Catching `BadMethodCallException` / `ReflectionException` as a fallback.

This approach is:

- **Slow** -- reflection and method invocation are expensive, especially when checking many attributes/filters per
  request.
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
- Cache invalidation from ISSUE-04 applies here too -- cached relation detection results must be invalidatable.

---

## ISSUE-08: Service Trait Lifecycle Callbacks Use Implicit Naming Conventions

**Severity:** Low
**File(s):** `src/Services/Service.php` (lines 198-211, 246-258)

### Problem

The `Service` class discovers and invokes trait lifecycle callbacks using naming conventions:

- **Initialization**: `initialize{TraitBaseName}()` via `forward_static_call()`.
- **Success callbacks**: `{camelCaseTraitBaseName}Success()` via dynamic method name.

This pattern is:

1. **Hard to discover** -- developers must know the naming convention to hook into the service lifecycle. No IDE
   autocomplete, no interface contract.
2. **Fragile** -- renaming a trait breaks its lifecycle hooks silently (no error, just no callback).
3. **Uses `forward_static_call()`** -- the initialization method is called statically despite potentially needing
   instance context. The `initializeTraits()` method is `protected static` but called from instance context via
   `$this->initialize()`.
4. **No failure callbacks per trait** -- only success callbacks are supported. There is no `{traitName}Failed()` hook.

### Desired Outcome

Replace the naming convention with an explicit contract:

1. **`ServiceLifecycleHook` interface** with `initializeHook(): void`, `successHook(): void`,
   `failedHook(\Throwable $e): void`.
2. **Explicit registration** -- traits register themselves in the service via a `registerLifecycleHook()` method called
   from the trait's `boot` or `initialize` method.
3. **Or use PHP attributes** -- `#[OnServiceSuccess]`, `#[OnServiceFailed]`, `#[OnServiceInitialize]` on trait methods,
   discovered via reflection once and cached.

### Constraints

- Backward compatibility: existing traits using the naming convention must continue to work during a transition period.
- The `Lockable` trait's `initializeLockable()` and any future trait hooks must migrate smoothly.
- Performance: hook discovery must not add overhead per `run()` call (cache the discovered hooks).

---

## ISSUE-09: Exception Handler Render Condition is Confusing and Potentially Leaky

**Severity:** Medium
**File(s):** `src/Exceptions/ApiExceptionHandler.php` (lines 62-68)

### Problem

The render method has this condition:

```php
if (!$request->expectsJson() && Config::get('app.debug')) {
    return null;
}
```

The inline comment says: *"We only render exceptions as JSON when specifically required and if the application is in
debug mode."* But the code does the opposite of what the comment describes -- it renders JSON for **all** requests
**except** when the request does NOT expect JSON AND debug mode is on.

This means:

1. **In production** -- ALL requests (including browser/HTML requests) get JSON error responses. A browser hitting a
   broken route gets `{"error":{"status":404,...}}` instead of a human-readable page.
2. **In debug mode** -- non-JSON requests fall through to Laravel's Whoops/Ignition handler (correct for development).

While this may be intentional for an API-only package, the misleading comment creates confusion, and the behavior should
be explicit and documented. Additionally, the debug-mode meta (lines 161-167) includes full stack traces, file paths,
and line numbers in the JSON response, which is an information disclosure risk if debug mode is accidentally left on in
production.

### Desired Outcome

1. **Fix the comment** to accurately describe the behavior.
2. **Make the render strategy configurable** -- add a config key like
   `api-toolkit.exceptions.render_strategy` with options: `always_json`, `json_when_expected`, `auto` (current
   behavior).
3. **Add debug meta safeguards** -- ensure `app.debug` is actually checked at render time and consider adding an
   additional config flag to control whether trace data is included even in debug mode.
4. **Document the behavior** explicitly in the config file comments.

### Constraints

- The current behavior is likely correct for many API-only applications. The default should not change without a major
  version bump.
- The fix must not break existing test suites that rely on the current rendering behavior.

---

## ISSUE-10: PolymorphicResource Does Not Propagate All Field Constraints

**Severity:** Medium
**File(s):** `src/Http/Resources/PolymorphicResource.php` (line 66)

### Problem

When `PolymorphicResource` maps a model to its resource, it only propagates the `$fields` constraint:

```php
return new $map[$class]($resource, false, $this->fields ?? null);
```

It does not propagate:

- `$excludedFields` -- fields that should be excluded.
- `$all` flag is handled separately (line 36-39 via `withAll()`) but after construction, meaning the resource is first
  constructed without the flag and then mutated.
- The `$load_missing` parameter is hardcoded to `false`, preventing eager-load-on-access for polymorphic resources.

Additionally:

- There is no type validation that the mapped class is actually an `ApiResourceInterface` implementation. The return
  type is `ApiResourceInterface` but the `new $map[$class](...)` call has no runtime check.
- The `LogicException` thrown when a model is not found in the map is a generic exception, not an `ApiException`
  subclass, so it bypasses the structured error handling.

### Desired Outcome

1. Propagate all field constraints (`$fields`, `$excludedFields`, `$all`) to the mapped resource.
2. Validate that the mapped class implements `ApiResourceInterface` before instantiation.
3. Replace the generic `LogicException` with a typed exception (e.g., `ResourceMappingException`).
4. Allow `$load_missing` to be configurable rather than hardcoded to `false`.

### Constraints

- The `resource_map` config structure must not change.
- Must remain backward compatible with existing polymorphic resource usage.

---

## ISSUE-11: Service Provider Uses Fragile Kernel Middleware Manipulation

**Severity:** Medium
**File(s):** `src/ApiServiceProvider.php` (lines 189-215)

### Problem

The service provider replaces Laravel's `PreventRequestsDuringMaintenance` middleware by iterating the global middleware
array and swapping the class reference:

```php
$global_middleware = $kernel->getGlobalMiddleware();
foreach ($global_middleware as $key => $middleware) {
    if ($middleware === LaravelPreventRequestsDuringMaintenance::class) {
        $global_middleware[$key] = PreventRequestsDuringMaintenance::class;
    }
}
$kernel->setGlobalMiddleware($global_middleware);
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
**File(s):** `src/ApiServiceProvider.php` (lines 143-181)

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
**File(s):** `src/Exceptions/ApiExceptionHandler.php` (lines 94-110), `src/Exceptions/`

### Problem

The exception handler maps only ~10 exception types. Several common Laravel/Symfony exceptions fall through to the
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

## ISSUE-14: SSE (Server-Sent Events) Implementation Coupled to Controller

**Severity:** Low
**File(s):** `src/Http/Routing/Controller.php` (method `respondWithEventStream`)

### Problem

The `respondWithEventStream()` method in the base controller contains ~60 lines of transport-level SSE logic:

- Connection state management (`connection_aborted()`)
- Heartbeat interval tracking (20-second configurable interval)
- Output buffering and flushing (`ob_flush()`, `flush()`)
- Error event emission
- Initial keep-alive comment (`:\n\n`)
- Sleep/polling loop with configurable interval

This is transport protocol logic that doesn't belong in a controller. It:

1. Makes the controller harder to test (requires output buffering mocks).
2. Cannot be reused outside the controller hierarchy.
3. Mixes HTTP concerns (response headers) with streaming concerns (heartbeat, flush).
4. Has no backpressure or client disconnect debouncing.

### Desired Outcome

Extract SSE logic into a dedicated `SseStream` or `EventStreamResponse` class:

1. **`EventStreamResponse`** class extending `StreamedResponse` that encapsulates headers, heartbeat, and connection
   management.
2. **`SseEmitter`** interface that the callback receives, with `emit(string $event, mixed $data): void` and
   `heartbeat(): void` methods.
3. **Configurable heartbeat** -- interval, format, and whether to send at all.
4. The controller's `respondWithEventStream()` becomes a thin wrapper: `return new EventStreamResponse($callback)`.

### Constraints

- Must maintain backward compatibility with existing `respondWithEventStream()` call sites.
- Must work with Laravel's response testing utilities.
- Heartbeat and connection management must remain functional across different PHP SAPI environments (FPM, CLI, Octane).

---

## ISSUE-15: ApiQueryParser Singleton Without Request Isolation Guarantees

**Severity:** Medium
**File(s):** `src/ApiQueryParser.php`, `src/ApiServiceProvider.php` (line 261)

### Problem

`ApiQueryParser` is bound as a singleton in the service container:

```php
$this->app->singleton(Config::get('api-toolkit.parser.alias'), fn ($app) => new ApiQueryParser);
```

The parser stores parsed parameters as instance state in the `$parameters` property. In standard PHP-FPM this is safe
because each request gets a fresh process. However:

1. **Laravel Octane** -- the singleton persists across requests. If `parse()` is called on request A and then the
   singleton is reused for request B without re-parsing, request B sees request A's parameters.
2. **Queue workers** -- if a queued job accesses `ApiQuery` facade, it gets stale parameters from the last HTTP request.
3. **Testing** -- tests that don't re-parse between test cases can leak state.

The `ParseApiQuery` middleware calls `ApiQuery::parse($request)` on each request which should reset the state, but if
the middleware is disabled (configurable via `api-toolkit.parser.register_middleware`), no parsing occurs and the
singleton retains its previous state.

### Desired Outcome

1. Make `ApiQueryParser` request-scoped instead of singleton (use `$this->app->scoped()` in Laravel 11+).
2. Add a `reset()` method that clears all parsed parameters.
3. In Octane contexts, register a `RequestReceived` listener that resets the parser.
4. Add documentation warning about singleton behavior when the middleware is disabled.

### Constraints

- Must not break the `ApiQuery` facade access pattern.
- Must work with both `singleton()` and `scoped()` container bindings.
- The `api.query` alias must remain functional.

---

## ISSUE-16: Debug Mode Information Disclosure in Exception Responses

**Severity:** Medium
**File(s):** `src/Exceptions/ApiExceptionHandler.php` (lines 157-167)

### Problem

When `app.debug` is `true`, exception responses include detailed debugging information:

```php
return Config::get('app.debug') && $previous ? array_merge($exception->getCustomMeta() ?? [], [
    'message'   => $previous->getMessage(),
    'exception' => $previous::class,
    'file'      => $previous->getFile(),
    'line'      => $previous->getLine(),
    'trace'     => collect($previous->getTrace())->map(fn ($trace) => Arr::except($trace, ['args']))->all(),
]) : $exception->getCustomMeta();
```

While the `['args']` are stripped from the trace, this still exposes:

1. Full file system paths (revealing server directory structure).
2. Class names and namespaces (revealing internal architecture).
3. Line numbers (aiding targeted exploitation).
4. Exception messages (may contain sensitive data like SQL queries from `QueryException`).

If `app.debug` is accidentally left on in production (a common misconfiguration), this becomes a significant information
disclosure vulnerability.

### Desired Outcome

1. Add a dedicated config key `api-toolkit.exceptions.include_debug_info` (default: `false`) that must be explicitly
   enabled, separate from `app.debug`.
2. Sanitise exception messages to strip potentially sensitive content (e.g., SQL query bodies, file paths).
3. Add a `DebugInfoSanitizer` class that can be extended by consumers to customise what is included.
4. Log a warning if debug info is enabled in a non-local environment.

### Constraints

- Default behavior must be "safe" (no debug info unless explicitly opted in).
- Must not break existing applications that rely on debug info in development.
- The change must be backward compatible (if `include_debug_info` is not set, fall back to current `app.debug`
  behavior).

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

## ISSUE-20: No Internationalisation Fallback for Exception Messages

**Severity:** Low
**File(s):** `src/Exceptions/ApiException.php` (lines 45-48, 75-78), `resources/lang/en/exceptions.php`

### Problem

Exception titles and details are resolved via Laravel's translation system:

```php
$key = $this->getTranslationKey('title');
return Lang::has($key) ? Lang::get($key) : '';
```

If the translation key doesn't exist:

1. The title/detail is an empty string, providing no useful error information to the API consumer.
2. Only English translations are shipped (`resources/lang/en/exceptions.php`). Applications serving non-English users
   must create their own translation files.
3. There's no fallback to a sensible default message derived from the HTTP status code (e.g., "Not Found" for 404).

### Desired Outcome

1. Add fallback messages derived from the HTTP status code when translations are missing (e.g., `HttpStatus::NOT_FOUND`
   -> "Not Found").
2. Ship additional language files for common languages or document how consumers should create them.
3. Add a config option to use exception class name or error code as a fallback identifier.
4. Consider supporting message parameters in translations (e.g., "Resource {type} not found").

### Constraints

- The `getCustomTitle()` and `getCustomDetail()` methods must remain backward compatible.
- Translation keys must not change (would break existing consumer translations).
- Fallback messages should be in English by default but configurable.

---

## ISSUE-21: Test Suite Only Uses SQLite -- No Multi-Database Coverage

**Severity:** Low
**File(s):** `tests/TestCase.php`, `phpunit.xml.dist`

### Problem

The entire test suite runs against in-memory SQLite. Several features have database-specific behavior that is not
tested:

1. **The `$contains` filter operator** -- maps to `'contains'` in the operator map, which is not standard SQL. Its
   behavior likely differs between MySQL (`JSON_CONTAINS`), PostgreSQL (`@>`), and SQLite.
2. **Transaction handling** in `Service` -- the comment explicitly notes "Transactions are only supported on MySQL
   databases running the InnoDB engine".
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
