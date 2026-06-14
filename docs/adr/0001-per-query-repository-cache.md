# 0001 — Per-query repository caching

- Status: Accepted
- Date: 2026-06-13

## Context

The `Cacheable` trait cached repository reads by storing the **entire table** under a single
key (`repository-cache:<table>`) and serving every read from that one snapshot. This had two
defects:

1. **Query-blind correctness bug.** A filtered or by-id read (`scopeById(1)->first()`,
   `where(...)->get()`) was served the full-table collection from the cache, because the cache
   key ignored the query. The cached value did not correspond to the query that produced it, so
   callers could receive rows they never asked for.
2. **Double query on a miss.** Populating the cache fetched the whole table with a second query
   in addition to the caller's original read, doubling database work on every cold read.

Write invalidation was also driven by sniffing the return type of the forwarded call
(`bool`/`int` ⇒ flush), which mis-classified verbs: `count()` returns `int` and was treated as a
write, while `create()` returning a `Model` was not treated as a write and left the cache stale.

## Decision

Replace whole-table caching with **per-query caching**:

- **Per-query keying.** Each executed query is fingerprinted
  (`hash('xxh128', connection | sql | normalised-bindings)`) and stored under
  `repository-query:<table>:<hash>`. Distinct queries map to distinct entries, so a filtered read
  never collides with a full-table read.
- **Pre-execution read interception (true zero-query hits).** `Cacheable::__call()` intercepts
  read verbs before execution: it prepares the query builder, fingerprints it, and on a cache hit
  returns the cached value **without executing the query** (0 database queries). On a miss it
  executes once, stores within the size guard, and returns. The parent repository's reset
  lifecycle (`resetAndReturn()`) is reused verbatim on both paths so transient criteria, scopes,
  and the model are reset exactly as in a normal read.
- **Explicit write-verb invalidation.** A fixed set of write verbs (`create`, `forceCreate`,
  `firstOrCreate`, `updateOrCreate`, `updateOrInsert`, `update`, `delete`, `forceDelete`, `save`,
  `insert`, `insertGetId`, `upsert`, `increment`, `decrement`, `restore`) is executed through the
  parent pipeline and then flushes the whole table. `create()` returning a `Model` now correctly
  invalidates; `count()` returning `int` no longer does.
- **Size guard.** Results larger than `max_rows` or `max_bytes` are still fetched and returned but
  are not stored, preventing unbounded cache growth.
- **Tag / registry invalidation.** Stores that support tags invalidate via a per-table tag.
  Non-taggable stores track live keys in a per-table registry and forget each one on a write. When
  the registry is disabled, invalidation falls back to TTL expiry only.
- **Reference-mode opt-in.** Repositories that set `protected bool $cacheReferenceTable = true`
  retain the historical whole-table behaviour (load once, serve `get`/`all`/`find` from memory,
  cross-request persistence). This is the only mode that keeps the old semantics, and it is opt-in.

## Consequences

- **Breaking change: default flip.** The default behaviour changes from whole-table to per-query
  caching. This is intentional — the old default was the correctness bug above. The public API
  (`withoutCache()`, `flushCache()`, `getCacheStatus()`) is unchanged, and the
  `REPOSITORY_CACHE` / `REPOSITORY_CACHE_META` keys are retained for reference mode.
- **Taggable-store recommendation.** A taggable store (Redis, Memcached, array) gives precise
  per-table invalidation and isolates the repository cache from the central
  `CacheManager::flush()` when a dedicated store is used. A non-taggable store (file, database)
  relies on the key registry; disabling the registry degrades to TTL-only staleness.
- **Null results are not cached.** A by-id read that misses (returns `null`) is not stored in the
  cache; the query re-executes on every subsequent call until the row exists. This avoids the
  negative-lookup staleness hazard where a row created outside the repository would be shadowed by
  a cached null sentinel until a write verb flushed the table. Empty Collections (non-null) are
  still cached normally.
- **Documented staleness boundary.** Out-of-band writes (raw Eloquent inserts that bypass the
  repository) are not observed until the affected entry's TTL expires or a repository write
  flushes the table.
- **`CacheManager::flush()` is unchanged.** The central toolkit flush is not extended to evict the
  repository cache; the repository cache is invalidated by repository writes and its own
  `flushCache()`. When the repository uses a dedicated store, the central flush leaves it intact.
