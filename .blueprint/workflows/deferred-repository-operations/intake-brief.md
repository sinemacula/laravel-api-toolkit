# Intake Brief: Deferred Repository Operations

Repositories should support buffering insert/update operations into a pool that flushes automatically at the end of the request lifecycle, reducing per-call database round trips.

---

## Governance

| Field     | Value              |
|-----------|--------------------|
| Created   | 2026-02-26         |
| Status    | draft              |
| Owned by  | Ben                |
| Traces to | User idea          |

---

## Raw Idea

> One area of optimisation that we have in the repositories is the ability to buffer inserts/updates instead of executing them all immediately - at the moment, if we call setAttributes, it will immediately persist those values in the database. However, if would be good if we could have a way to handle inserts/updates that can be added to a pool and then flushed later in the request lifecycle. for example, and maybe not a great one - we may have 15 inserts for audit logs throughout a given request - we may not need these to be inserted at the precise moment they are called, and instead, we may just want to bulk insert them at the end of the request. Our repositories should provide a very simple and clean way to do this, without breaking the existing functionality i.e. either add an optional parameter, or add a new method. I like the idea of registering a listener from the service provider so that it flushes the pool on request end (if we can do that) because that means all we have to do is change the method, or add a parameter, to any inserts/updates we want to defer in our existing API, and the system will just work.

---

## Problem Signal

**Who has this problem:** Developers building APIs with the Laravel API Toolkit who perform many small write operations (inserts/updates) across a single request — particularly for cross-cutting concerns like audit logging.

**What is the problem:** Every insert or update through the repository layer executes an individual database query immediately. When a request triggers many small writes (e.g. 15 audit log entries), this creates unnecessary database round trips that degrade performance without providing any benefit, since these writes don't need to be immediately visible.

**Why it matters:** Accumulated per-call write overhead increases request latency and database load, particularly in high-throughput APIs where cross-cutting writes multiply per request. Batching these into a single bulk operation at request end would reduce round trips significantly.

**Current alternatives:** Developers must either accept the per-call overhead or manually collect records and perform bulk inserts themselves outside the repository pattern, which breaks the consistency of the repository abstraction.

---

## Context

**Domain:** Laravel package development — repository pattern for REST API scaffolding.

**Business context:** This is an open-source Laravel package (`sinemacula/laravel-api-toolkit`) with a sibling `laravel-repositories` package providing the base repository pattern. The optimisation must integrate cleanly with the existing repository API.

**Constraints:**

- Must not break existing repository behaviour — current immediate-persist calls must continue to work unchanged
- Must integrate with the existing `ApiRepository` / base repository class hierarchy
- Must work within the Laravel request lifecycle (service provider, middleware, terminable middleware)
- PHP ^8.3 required

**Assumptions:**

- Laravel's request lifecycle events (e.g. `terminate` or `RequestHandled`) are reliable points for flushing
- The pool only needs to handle simple inserts/updates, not complex transactional sequences
- Deferred operations do not need to return the created/updated model immediately

---

## Success Signals

| Signal                           | Description                                                                        |
|----------------------------------|------------------------------------------------------------------------------------|
| Minimal API surface change       | Existing code can opt into deferral with a single parameter change or method swap  |
| Automatic flush                  | Pooled operations flush at request end without explicit developer intervention      |
| No breaking changes              | All existing repository method signatures and behaviours remain intact              |
| Measurable round-trip reduction  | A request with N deferred writes executes 1 bulk query instead of N individual ones |

---

## Open Questions

- Should the pool be scoped per-repository instance, per-model type, or globally across all repositories?
- What happens to pooled operations if the request terminates abnormally (exception, timeout)?
- Should there be a manual `flush()` escape hatch for cases where a developer needs to force persistence mid-request?
- How should deferred updates be handled — can multiple updates to the same record be coalesced?
- Does the flush need to run inside a database transaction for atomicity?
- Should there be a configurable pool size limit that triggers an automatic early flush?

---

## Research Seeds

| Topic                        | Question                                                                                         | Priority |
|------------------------------|--------------------------------------------------------------------------------------------------|----------|
| Laravel lifecycle hooks      | What Laravel events or hooks reliably fire at the end of every request (HTTP, queue, CLI)?        | high     |
| Bulk insert/update patterns  | What Eloquent/query builder methods support efficient bulk inserts and bulk updates?              | high     |
| Existing pool patterns       | How do other Laravel packages (e.g. event sourcing, audit packages) handle deferred writes?      | medium   |
| Error handling               | What are the failure modes when a bulk flush fails partway through, and how should they surface?  | medium   |
| Repository pattern impact    | How can a deferred write be integrated without violating the repository contract?                 | high     |

---

## References

- Source: User idea (captured 2026-02-26)
