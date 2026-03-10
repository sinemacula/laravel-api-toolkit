# Problem Map: Cache Memo Invalidation for Long-Running Processes

Stale cached metadata in the API toolkit causes silent data corruption and offers no lifecycle management for Octane, queue worker, or Reverb deployments.

---

## Governance

| Field     | Value                                                       |
|-----------|-------------------------------------------------------------|
| Created   | 2026-03-10                                                  |
| Status    | approved                                                    |
| Owned by  | Product Analyst                                             |
| Traces to | [Intake Brief](intake-brief.md), Spikes (2 documents)      |

---

## Input Spikes

| # | Spike                           | Path                                              | Key Finding                                                                                      |
|---|---------------------------------|---------------------------------------------------|--------------------------------------------------------------------------------------------------|
| 1 | Cache & Lifecycle Inventory     | spikes/spike-cache-lifecycle-inventory.md          | 7 cache sites identified (4 memo, 2 static, 1 singleton); zero lifecycle flush infrastructure    |
| 2 | Octane Invalidation Patterns    | spikes/spike-octane-invalidation-patterns.md       | `Cache::memo()` auto-flushes in 12.9+ but static caches and `rememberForever()` persistence remain unmanaged |

---

## Problem Clusters

### Cluster: Silent Data Corruption in Long-Running Environments

**Theme:** Stale cached metadata silently produces incorrect API responses, and the errors are difficult to trace back to cache staleness.

**Affected users:** Developers deploying API toolkit applications on Laravel Octane, queue workers, or Reverb; end-users consuming the API who receive incorrect data.

#### Problem 1: Stale Relation Detection Causes Incorrect Query Filtering

- **Description:** When a model's relation methods change (added, removed, renamed) after initial caching, the `isRelation()` check returns stale results in long-running processes. This causes filters intended for relation-based queries to be applied as column filters (or vice versa), returning incorrect result sets without any error or warning.
- **Evidence:** Spike 1, Finding 1 — `ApiCriteria::isRelation()` caches via `Cache::memo()->rememberForever()` with key `MODEL_RELATIONS`. Spike 2, Finding 1 — `rememberForever()` persists in the underlying store beyond the memo auto-flush.
- **Severity:** High — incorrect query results are a data integrity issue that can affect business logic downstream.
- **Frequency:** Occasionally — triggered by model relation changes during active Octane/worker processes; rare in stable codebases but common during development and deployment cycles.

#### Problem 2: Stale Cast Maps Cause Wrong Attribute Types

- **Description:** When a model's cast definitions change, the cached cast map continues returning the old type mappings. Attributes may be serialized as the wrong type (e.g., a string instead of a JSON object, or a raw value instead of a cast enum), causing client-side parsing failures or data misinterpretation.
- **Evidence:** Spike 1, Finding 1 — `ApiRepository::storeCastsInCache()` uses `CacheKeys::REPOSITORY_MODEL_CASTS` with `rememberForever()`. Spike 1, Finding 4 — the singleton `ApiQueryParser` also retains stale state.
- **Severity:** High — wrong attribute types can break client applications that depend on the API contract.
- **Frequency:** Occasionally — triggered by cast definition changes during active processes.

#### Problem 3: Schema Column Changes Are Invisible Until Process Restart

- **Description:** After a database migration adds, removes, or renames columns, the cached column metadata continues reflecting the pre-migration schema. New columns are ignored by the repository layer, removed columns may cause errors, and renamed columns are not recognized — all silently within the running process.
- **Evidence:** Spike 1, Finding 1 — `InteractsWithModelSchema::storeColumnsInCacheForModel()` caches column data via `CacheKeys::MODEL_SCHEMA_COLUMNS` with `rememberForever()`.
- **Severity:** High — missing or incorrect column metadata can cause query failures or data loss.
- **Frequency:** Occasionally — triggered by migrations in environments where workers are not restarted.

#### Problem 4: Stale Resource Mappings Cause Mismatched Serialization

- **Description:** When the model-to-resource mapping in config changes (e.g., a model is reassigned to a different resource class), the cached mapping continues routing to the old resource. API responses may use the wrong field schema, include stale default fields, or omit new fields added to the updated resource.
- **Evidence:** Spike 1, Finding 1 — `ResolvesResource::getResourceFromModel()` caches via `CacheKeys::MODEL_RESOURCES` with `rememberForever()`.
- **Severity:** Medium — responses use wrong field schema but do not cause data corruption; the API contract is violated.
- **Frequency:** Rarely — resource map changes are infrequent in production.

#### Problem 5: Compiled Schema Cache Persists Indefinitely in Static Memory

- **Description:** `ApiResource::$schemaCache` is a private static array that stores compiled schemas per resource class. In Octane, this array accumulates entries across requests and is never cleared. If a resource's `schema()` method output changes (e.g., via config or conditional logic), the stale compiled schema persists for the lifetime of the worker process.
- **Evidence:** Spike 1, Finding 3 — `ApiResource::$schemaCache` has no public flush method; tests use reflection to clear it. Spike 2, Finding 2 — static properties are not managed by any auto-flush mechanism.
- **Severity:** Medium — affects field inclusion/exclusion and eager loading decisions.
- **Frequency:** Occasionally — triggered whenever schema definitions evolve while workers run.

---

### Cluster: No Cache Lifecycle Management Tools

**Theme:** Developers operating in long-running environments have no tools to manage, inspect, or flush the toolkit's cached metadata at request or job boundaries.

**Affected users:** DevOps engineers and backend developers deploying and operating API toolkit applications in Octane, queue worker, or Reverb environments.

#### Problem 1: No Centralized Cache Flush Mechanism

- **Description:** There is no single method, command, or entry point to flush all 7 identified cache sites. Each site (4 memo caches, 2 static properties, 1 singleton) must be managed independently. Developers who discover staleness have no toolkit-provided way to resolve it other than restarting the process.
- **Evidence:** Spike 1, Finding 5 — zero lifecycle flush infrastructure exists; `RepositoryResolver::flush()` clears only one of seven sites. Spike 1, Finding 3 — `ApiResource::$schemaCache` has no public flush method.
- **Severity:** High — absence of a flush mechanism means the only recourse is process restart.
- **Frequency:** Weekly — aligns with deployment and migration cadence in active projects.

#### Problem 2: No Automatic Lifecycle Event Integration

- **Description:** The toolkit does not register listeners on Octane's `OperationTerminated` event or queue worker's `JobProcessed`/`JobFailed` events. Packages like `spatie/laravel-permission` provide opt-in config flags for Octane compatibility, but the API toolkit offers no equivalent mechanism. Developers must build custom flush logic themselves.
- **Evidence:** Spike 1, Finding 5 — no event subscribers or listeners for lifecycle events exist in `src/`. Spike 2, Finding 3 — `OperationTerminated` is the established Octane hook. Spike 2, Finding 4 — the spatie config-flag pattern is the proven community approach. Spike 2, Finding 5 — queue workers require separate event registration.
- **Severity:** High — forces every consumer to independently solve the same problem.
- **Frequency:** Daily — every request/job in a long-running process potentially encounters stale state.

#### Problem 3: Static Property Caches Lack Public Flush APIs

- **Description:** `ApiResource::$schemaCache` is `private static` with no public clear method. `RepositoryResolver::$map` has no flush at all (only `$repositories` is flushable). Even if a lifecycle listener were added, it cannot clear these caches without reflection or adding new public methods.
- **Evidence:** Spike 1, Finding 3 — test code uses `new \ReflectionProperty(ApiResource::class, 'schemaCache')` to clear the cache. Spike 2, Finding 2 — no framework mechanism resets static properties on package classes.
- **Severity:** Medium — architectural gap that blocks implementation of any flush mechanism.
- **Frequency:** N/A — this is a structural limitation, not a user-facing occurrence.

---

### Cluster: Developer Experience Friction

**Theme:** Testing, understanding, and maintaining the cache surface area is unnecessarily difficult.

**Affected users:** Contributors to the API toolkit package and developers writing tests for applications using the toolkit.

#### Problem 1: Test Isolation Requires Reflection to Clear Static Caches

- **Description:** Test teardown must use `new \ReflectionProperty(ApiResource::class, 'schemaCache')` to clear the compiled schema cache between test cases. This couples tests to the private implementation detail, breaks if the property is renamed, and is not documented as a requirement for consumers writing integration tests.
- **Evidence:** Spike 1, Finding 3 — `tests/Unit/Http/Resources/ApiResourceTest.php:2152` uses reflection for `clearSchemaCache()`, called in `tearDown()` and in 8 individual test methods.
- **Severity:** Low — affects developer experience, not production behavior.
- **Frequency:** Daily — encountered every time resource-related tests are written or run.

#### Problem 2: Unused Cache Keys Create Confusion About Cache Surface Area

- **Description:** The `CacheKeys` enum defines `MODEL_EAGER_LOADS` and `MODEL_RELATION_INSTANCES` cases that are not used anywhere in production code. Developers auditing the cache surface area may overestimate the number of active caches or attempt to flush keys that are never written.
- **Evidence:** Spike 1, Finding 2 — grep across all `src/` files confirms zero usage of these two cases; they appear only in the enum definition and its unit test.
- **Severity:** Low — creates confusion but does not affect behavior.
- **Frequency:** Rarely — encountered during code review or cache debugging.

---

## Cross-Cutting Concerns

- **Opt-in vs always-on:** The cache invalidation mechanism must not degrade performance for standard PHP-FPM deployments where process-lifetime caching is correct and desirable. This constraint spans every problem in the map — solutions must be opt-in with no default behavior change.
- **Dual event system:** Octane (`OperationTerminated`) and queue workers (`JobProcessed`/`JobFailed`) use separate event systems with no shared interface. Any lifecycle management must handle both independently, doubling the registration surface.
- **`rememberForever()` vs memo auto-flush mismatch:** The `Cache::memo()` decorator auto-flushes between requests in Laravel 12.9+, but `rememberForever()` persists in the underlying cache store. This means the staleness problem may extend beyond long-running processes to ANY deployment using a persistent cache driver (Redis, file) — not just Octane/workers.

---

## Gaps

- **Reverb (WebSocket) coverage:** No research was conducted on whether `Cache::memo()` auto-flushes in Laravel Reverb contexts. Reverb is a distinct long-running environment that may have its own lifecycle events or may lack them entirely.
- **`rememberForever()` backing store behavior:** The exact behavior of `Cache::memo()->rememberForever()` when the underlying driver is `array` vs `redis` vs `file` was not empirically verified. The analysis is based on the documented decorator pattern.
- **`ApiQueryParser` singleton scope:** The singleton state issue (ISSUE-11) overlaps with this problem space but was not deeply researched. It may share the same lifecycle flush mechanism or require a separate solution (scoped binding).
- **Production incident data:** No evidence from real-world deployments was collected — all severity/frequency ratings are based on code analysis and community patterns, not observed incidents.

---

## References

- Intake Brief: [intake-brief.md](intake-brief.md)
- Spikes:
  - [spike-cache-lifecycle-inventory.md](spikes/spike-cache-lifecycle-inventory.md)
  - [spike-octane-invalidation-patterns.md](spikes/spike-octane-invalidation-patterns.md)
