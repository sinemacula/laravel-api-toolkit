# Spike: Cache & Lifecycle Inventory

Comprehensive audit of all memo caches, static property caches, singleton state, and lifecycle flush infrastructure in the toolkit and its sibling packages.

---

## Governance

| Field     | Value                                            |
|-----------|--------------------------------------------------|
| Created   | 2026-03-10                                       |
| Status    | approved                                         |
| Owned by  | Researcher                                       |
| Traces to | [Intake Brief](../intake-brief.md)               |

---

## Research Question

What is the complete set of `Cache::memo()`, `rememberForever()`, static property caches, and singleton state across the API toolkit and its sibling packages, and what lifecycle flush infrastructure currently exists to manage them?

---

## Methodology

Full-text search of the `src/` directory tree and sibling packages (`sinemacula/laravel-repositories`, `sinemacula/laravel-resource-exporter`) in the vendor directory for:

- `Cache::memo()` and `rememberForever()` calls
- Static property declarations (`private static array`, `protected static array`)
- Flush/clear/reset methods on cache-bearing classes
- Event subscriber registration and lifecycle event references (`RequestHandled`, `JobProcessed`, `CommandFinished`)
- `CacheKeys` enum usage to map defined keys to actual write sites
- Singleton bindings in `ApiServiceProvider`

All findings were verified by reading the source files containing each match.

---

## Findings

### Finding 1: Four Active `Cache::memo()->rememberForever()` Write Sites

**Observation:** Four locations write to the memo cache store using `Cache::memo()->rememberForever()`, each keyed by a `CacheKeys` enum case:

| # | Location | CacheKeys Case | What is Cached |
|---|----------|----------------|----------------|
| 1 | `ApiCriteria::isRelation()` (line 569) | `MODEL_RELATIONS` | Whether a model method is a valid Eloquent relation |
| 2 | `ApiRepository::storeCastsInCache()` (line 271) | `REPOSITORY_MODEL_CASTS` | Resolved cast map per model |
| 3 | `InteractsWithModelSchema::storeColumnsInCacheForModel()` (line 71) | `MODEL_SCHEMA_COLUMNS` | Database column metadata per model |
| 4 | `ResolvesResource::getResourceFromModel()` (line 56) | `MODEL_RESOURCES` | Model-to-API-resource class mapping |

Two additional `Cache::memo()->get()` read-only calls exist in `ApiRepository::resolveCastsFromCache()` (line 475) and `InteractsWithModelSchema::resolveColumnsFromCacheForModel()` (line 59), which consume caches written by sites #2 and #3 respectively.

**Evidence:** Direct grep of `Cache::memo()` and `rememberForever` across `src/` — four unique write sites confirmed, no others found.

**Confidence:** High — exhaustive text search of the source tree with every match verified by reading the surrounding code.

### Finding 2: Two Unused CacheKeys Enum Cases

**Observation:** The `CacheKeys` enum defines 6 cases, but only 4 are used in production code:

| CacheKeys Case | Used in `src/`? |
|----------------|-----------------|
| `REPOSITORY_MODEL_CASTS` | Yes |
| `MODEL_SCHEMA_COLUMNS` | Yes |
| `MODEL_RELATIONS` | Yes |
| `MODEL_RESOURCES` | Yes |
| `MODEL_EAGER_LOADS` | No |
| `MODEL_RELATION_INSTANCES` | No |

`MODEL_EAGER_LOADS` and `MODEL_RELATION_INSTANCES` are defined in `src/Enums/CacheKeys.php` (lines 28, 31) and tested in `tests/Unit/Enums/CacheKeysTest.php`, but no code in `src/` writes to or reads from these keys.

**Evidence:** Grep for `MODEL_EAGER_LOADS` and `MODEL_RELATION_INSTANCES` across all `.php` files returned matches only in the enum definition and its test file.

**Confidence:** High — exhaustive search confirms no usage in production code. These may be reserved for future use or left over from a refactor.

### Finding 3: Two Static Property Caches Without Public Flush Methods

**Observation:** Two classes maintain static property caches that persist across the process lifetime:

| # | Class | Property | Flush Method? |
|---|-------|----------|---------------|
| 1 | `ApiResource` | `private static array $schemaCache = []` (line 39) | No — tests use reflection (`new \ReflectionProperty(...)`) to clear it |
| 2 | `RepositoryResolver` | `private static array $repositories = []` (line 19) | Yes — `flush()` method (line 86), but docblock says "primarily useful for testing" |

A third static property, `RepositoryResolver::$map` (line 16), is lazy-loaded from config via null coalescing (`self::$map ??= config(...)`) and has no flush method. It persists once loaded.

**Evidence:** Grep for `static.*array` declarations in `src/`, cross-referenced with each class for flush/clear methods. `ApiResource` schema cache clearance confirmed only in test code (`tests/Unit/Http/Resources/ApiResourceTest.php:2152`).

**Confidence:** High — all static property declarations in `src/` were reviewed; only these three hold per-class or per-model cached data.

### Finding 4: Singleton State in ApiQueryParser

**Observation:** `ApiQueryParser` is bound as a singleton in `ApiServiceProvider::registerQueryParser()` (line 251):

```php
$this->app->singleton(Config::get('api-toolkit.parser.alias'), fn ($app) => new ApiQueryParser);
```

The parser stores parsed query parameters in instance property `$parameters` (line 21). In standard PHP-FPM, each request gets a fresh process, but in Octane or queue workers, the singleton persists and `$parameters` from request A leak into request B unless the `ParseApiQuery` middleware re-parses. If the middleware is disabled (configurable via `api-toolkit.parser.register_middleware`), no parsing occurs and stale parameters persist.

**Evidence:** Direct reading of `ApiServiceProvider.php` (line 251) and `ApiQueryParser.php` (line 21). The middleware registration is conditional on config at line 184.

**Confidence:** High — the singleton binding and conditional middleware are both in source code.

### Finding 5: No Lifecycle Flush Infrastructure Exists

**Observation:** The `WritePoolFlushSubscriber` referenced in ISSUES.md does not exist in the codebase. No event subscriber or listener handles lifecycle events (`RequestHandled`, `CommandFinished`, `JobProcessed`, `JobFailed`) for cache flushing. The only event listeners registered in `ApiServiceProvider` are for notification logging (`NotificationSending`, `NotificationSent`).

The existing flush points are:

| Method | Scope | Triggered By |
|--------|-------|--------------|
| `RepositoryResolver::flush()` | Clears `$repositories` only | Manual call / tests |
| Test `clearSchemaCache()` | Clears `ApiResource::$schemaCache` via reflection | Test tearDown only |

There is no centralized "flush all caches" method, artisan command, or automated lifecycle hook.

**Evidence:** Grep for `WritePool`, `FlushSubscriber`, `Subscriber`, `RequestHandled`, `JobProcessed`, and `CommandFinished` across all `src/` files returned zero matches. The `src/Listeners/` directory contains only `NotificationListener.php` and `ProvidesExclusiveLock.php`.

**Confidence:** High — exhaustive search with zero matches confirms the infrastructure does not exist.

### Finding 6: Sibling Packages Have No Caches

**Observation:** Neither `sinemacula/laravel-repositories` nor `sinemacula/laravel-resource-exporter` contains any `Cache::memo()` calls, `rememberForever()` calls, static property caches, or flush mechanisms. The cache invalidation problem is entirely scoped to the `laravel-api-toolkit` package.

**Evidence:** Grep for `Cache::`, `rememberForever`, `static.*\$`, `flush`, and `clearCache` across both sibling packages' `src/` directories. Only match was an unrelated `__callStatic` method in `laravel-repositories`.

**Confidence:** High — both packages are small and the search was exhaustive.

---

## Implications

- The problem surface is well-bounded: 4 memo cache write sites, 2 static property caches, 1 singleton, and 0 existing flush infrastructure. A single flush mechanism can cover all 7 sites.
- The `WritePoolFlushSubscriber` referenced in ISSUES.md does not exist, meaning the lifecycle event infrastructure must be built from scratch rather than extended.
- Two `CacheKeys` enum cases (`MODEL_EAGER_LOADS`, `MODEL_RELATION_INSTANCES`) are unused. These should either be removed as dead code or documented as reserved for planned features.
- `ApiResource::$schemaCache` lacks any public flush method — even tests resort to reflection. Any invalidation mechanism will need to add a `clearCompiledSchemas()` (or equivalent) public static method.
- `RepositoryResolver::$map` is an additional stale-state risk beyond `$repositories`. The existing `flush()` method does not clear it.
- The `ApiQueryParser` singleton is a related but distinct concern (ISSUE-11). It shares the same lifecycle boundary problem and could benefit from the same event subscriber, but its fix (scoped binding or explicit reset) differs from memo cache flushing.
- Since sibling packages have no caches, the invalidation mechanism is fully contained within this package with no cross-package coordination needed.

---

## Open Threads

- Should the two unused `CacheKeys` cases be removed, or are they reserved for a planned feature (e.g., eager-load caching)?
- Should `ApiQueryParser` reset be included in the same lifecycle subscriber, or handled separately as part of ISSUE-11?
- What is the correct event for Octane cache flushing — `RequestReceived` (proactive reset before handling) or `RequestHandled` (cleanup after handling)?
- Does `RepositoryResolver::flush()` need to also clear `$map`, or is `$map` safe because it reads from config which is itself reset by Octane?

---

## References

- Traces to: [Intake Brief](../intake-brief.md)
- Sources:
  - `src/Repositories/Criteria/ApiCriteria.php` (line 569)
  - `src/Repositories/ApiRepository.php` (lines 271, 475)
  - `src/Repositories/Traits/InteractsWithModelSchema.php` (lines 59, 71)
  - `src/Repositories/Traits/ResolvesResource.php` (line 56)
  - `src/Http/Resources/ApiResource.php` (lines 39, 283-291)
  - `src/Repositories/RepositoryResolver.php` (lines 16, 19, 86-89)
  - `src/ApiQueryParser.php` (line 21)
  - `src/ApiServiceProvider.php` (lines 184-186, 251)
  - `src/Enums/CacheKeys.php` (full file)
  - `tests/Unit/Http/Resources/ApiResourceTest.php` (lines 2152-2156)
