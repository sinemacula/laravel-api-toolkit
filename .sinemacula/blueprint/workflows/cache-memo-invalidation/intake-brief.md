# Intake Brief: Cache Memo Invalidation for Long-Running Processes

Process-lifetime memo caches in the API toolkit never invalidate, causing stale metadata in Octane, queue workers, and Reverb.

---

## Governance

| Field     | Value              |
|-----------|--------------------|
| Created   | 2026-03-10         |
| Status    | approved           |
| Owned by  | Ben                |
| Traces to | User idea          |

---

## Raw Idea

Several critical metadata lookups are cached forever using `Cache::memo()->rememberForever()`:

1. **Model relation detection** (`SchemaIntrospector::isRelation`) -- caches whether a method on a model is a valid Eloquent relation.
2. **Model schema columns** (`SchemaIntrospector::getColumns`) -- caches column information per model.
3. **Repository model casts** (`AttributeSetter::storeCastsInCache`) -- caches the resolved cast map for each model.
4. **Model resources** (`ResolvesResource::getResourceFromModel`) -- caches model-to-resource mappings.
5. **Schema compilation** (`SchemaCompiler::$cache`) -- a static in-memory array that never invalidates.

While `Cache::memo()` is process-lifetime only (not cross-request in typical PHP-FPM), in long-running processes like queue workers, Octane, or Reverb, stale caches can cause incorrect filtering, broken eager-loading, or wrong attribute casting.

The `CacheKeys` enum has been extended with 6 cache key types. `SchemaCompiler::clearCache()` exists for test cleanup. `WritePoolFlushSubscriber` provides lifecycle hooks (`RequestHandled`, `CommandFinished`, `JobProcessed`, `JobFailed`) but only flushes `WritePool`, not memo caches.

Remaining work: (1) Extend `WritePoolFlushSubscriber` (or add a companion subscriber) to flush memo caches at request boundaries in Octane/queue worker contexts. (2) Provide a `clearApiToolkitCaches()` method or artisan command that flushes all memo caches. (3) Consider adding a configurable TTL instead of `rememberForever()` for production deployments.

Constraints: Must not degrade performance for standard PHP-FPM deployments where process-lifetime caching is correct. Cache invalidation must be opt-in (not forced on every request).

---

## Problem Signal

**Who has this problem:** Developers deploying Laravel API Toolkit applications on long-running PHP runtimes -- Laravel Octane (Swoole/RoadRunner), queue workers, and Laravel Reverb (WebSocket server).

**What is the problem:** Five critical metadata caches (`Cache::memo()->rememberForever()` and static arrays) persist indefinitely within a process. When the process handles multiple requests or jobs, stale cache entries from earlier requests leak into later ones, causing incorrect filtering, broken eager-loading, wrong attribute casting, and mismatched model-to-resource mappings.

**Why it matters:** Long-running runtimes are increasingly the standard for production Laravel deployments. Stale metadata causes silent data integrity bugs that are difficult to diagnose -- a model column added via migration won't appear until the worker is restarted, a changed cast definition won't take effect, and relation detection results from one request pollute another. These bugs erode trust in the toolkit for any non-trivial deployment.

**Current alternatives:** Developers must manually restart queue workers and Octane servers after any schema or model change. There is no programmatic way to flush the toolkit's memo caches at request boundaries. `SchemaCompiler::clearCache()` exists but only clears one of the five cache sites and is intended for tests, not production lifecycle management.

---

## Context

**Domain:** PHP / Laravel framework ecosystem -- API toolkit package providing resource serialization, query parsing, and repository patterns.

**Business context:** The toolkit is a foundational package used across Sine Macula's Laravel applications. As these applications adopt Octane and persistent queue workers for performance, the memo cache invalidation gap becomes a production blocker rather than a theoretical concern.

**Constraints:**
- Must not degrade performance for standard PHP-FPM deployments where process-lifetime caching is correct and desirable.
- Cache invalidation must be opt-in, not forced on every request.
- The existing `WritePoolFlushSubscriber` lifecycle hook pattern should be reused or extended rather than introducing a competing mechanism.
- Changes must not break the `CacheKeys` enum contract or the existing `SchemaCompiler::clearCache()` API.

**Assumptions:**
- `Cache::memo()` stores are process-scoped and do not persist across PHP-FPM requests, so the problem is limited to long-running runtimes.
- The five identified cache sites are the complete set of memo/static caches in the toolkit (this should be verified during discovery).
- Laravel Octane's `RequestReceived` / `RequestHandled` events and queue worker's `JobProcessed` / `JobFailed` events are reliable lifecycle boundaries for cache flushing.

---

## Success Signals

| Signal                           | Description                                                                                            |
|----------------------------------|--------------------------------------------------------------------------------------------------------|
| No stale metadata across requests | Under Octane, consecutive requests with different schemas/models receive correct, isolated metadata.   |
| No stale metadata across jobs     | Queue workers processing jobs after schema changes reflect the updated columns, casts, and relations.  |
| No FPM performance regression     | Standard PHP-FPM deployments show no measurable overhead from the invalidation mechanism.              |
| Single flush entry point          | A unified method or command clears all five cache sites without requiring knowledge of each individual cache. |
| Opt-in activation                 | Cache flushing is off by default and activated via configuration or event subscriber registration.     |

---

## Open Questions

- Are the five identified cache sites (`isRelation`, `getColumns`, `storeCastsInCache`, `getResourceFromModel`, `SchemaCompiler::$cache`) the complete set, or are there additional static/memo caches in the toolkit or its sibling packages?
- Should cache flushing happen at the start of a new request/job (proactive reset) or at the end of the previous one (cleanup), or both?
- Is a configurable TTL a viable alternative to event-driven invalidation, or does it introduce its own consistency window problems?
- Should the flush mechanism be a standalone subscriber, an extension of `WritePoolFlushSubscriber`, or a trait mixed into both?
- How do sibling packages (`laravel-repositories`, `laravel-resource-exporter`) interact with these caches -- do they have their own memo caches that need coordinated invalidation?

---

## Research Seeds

| Topic                              | Question                                                                                                    | Priority |
|------------------------------------|-------------------------------------------------------------------------------------------------------------|----------|
| Complete cache inventory           | What is the full set of `Cache::memo()`, `rememberForever()`, and static property caches across the toolkit and its sibling packages? | high     |
| Lifecycle event reliability        | How do Octane's `RequestReceived`/`RequestHandled` and queue worker's `JobProcessed`/`JobFailed` events behave for cache flushing -- are there edge cases (e.g., long-running jobs, failed middleware)?  | high     |
| WritePoolFlushSubscriber extension | Can the existing `WritePoolFlushSubscriber` be extended to flush memo caches, or does separation of concerns warrant a dedicated subscriber? | medium   |
| TTL vs event-driven invalidation   | What are the trade-offs between a configurable TTL (simpler, but consistency window) and event-driven flush (precise, but requires lifecycle hooks)? | medium   |
| Octane community patterns          | How do other Laravel packages handle memo/static cache invalidation in Octane environments -- are there established patterns or pitfalls? | low      |

---

## References

- Source: User idea (captured 2026-03-10)
- ISSUES.md: ISSUE-01 — Cache Memo Entries Lack Automatic Invalidation for Long-Running Processes
