# Prioritization: Cache Memo Invalidation for Long-Running Processes

Scoring and ranking 10 problems from the cache memo invalidation problem map to determine PRD priority.

---

## Governance

| Field     | Value                                    |
|-----------|------------------------------------------|
| Created   | 2026-03-10                               |
| Status    | approved                                 |
| Owned by  | Product Analyst                          |
| Traces to | [Problem Map](problem-map.md)            |

---

## Scoring Axes

| Axis      | Question                                           | Scale                            |
|-----------|----------------------------------------------------|----------------------------------|
| Impact    | How significant is this problem for users?         | 1 (low) - 3 (high)              |
| Effort    | How much effort is required to solve this?         | 1 (high effort) - 3 (low effort) |
| Alignment | How well does solving this fit the product vision? | 1 (low) - 3 (high)              |

**Note:** Effort is inverted (3 = low effort = better) so that total score is a direct priority signal — higher total =
higher priority.

---

## Problem Scores

| Rank | Problem | Cluster | Impact | Impact Rationale | Effort | Effort Rationale | Alignment | Alignment Rationale | Total | Priority |
|------|---------|---------|--------|------------------|--------|------------------|-----------|---------------------|-------|----------|
| 1 | No Centralized Cache Flush | Lifecycle Mgmt | 3 | Root cause: without a single flush entry point, every consumer must build their own; absence enables all Data Corruption problems | 2 | Moderate: create a unified method that flushes all 7 cache sites, plus an artisan command; requires flush APIs (Problem 6) to exist first | 3 | Enabling correct behavior in production environments is fundamental to a production-ready toolkit | 8 | P0 |
| 2 | No Automatic Lifecycle Event Integration | Lifecycle Mgmt | 3 | Every request/job in long-running contexts encounters stale state; the most frequently occurring problem (daily) | 2 | Moderate: create event subscriber, register on OperationTerminated + JobProcessed/JobFailed, add opt-in config flag following spatie pattern | 3 | Octane and queue worker compatibility is essential for a modern Laravel package; the community expects it | 8 | P0 |
| 3 | Stale Relation Detection Causes Incorrect Query Filtering | Data Corruption | 3 | Incorrect query results are a data integrity issue; filters applied as column filters (or vice versa) without any error or warning | 2 | Addressed by the centralized flush infrastructure (Problems 1-2); marginal effort is adding MODEL_RELATIONS key to the flush list | 3 | Correct REST API query behavior is the toolkit's core promise | 8 | P0 |
| 4 | Stale Cast Maps Cause Wrong Attribute Types | Data Corruption | 3 | Wrong types break API contracts; clients parsing JSON receive unexpected types, causing downstream failures | 2 | Same flush infrastructure; marginal effort is adding REPOSITORY_MODEL_CASTS key to the flush list | 3 | API contract integrity is fundamental to toolkit purpose | 8 | P0 |
| 5 | Schema Column Changes Are Invisible Until Process Restart | Data Corruption | 3 | Missing columns cause silent data omission; added columns are ignored by repository layer. Note: this also affects FPM deployments using persistent cache drivers (Redis, file), not just Octane | 2 | Same flush infrastructure; marginal effort is adding MODEL_SCHEMA_COLUMNS key to the flush list | 3 | Schema accuracy is core to correct API behavior | 8 | P0 |
| 6 | Static Property Caches Lack Public Flush APIs | Lifecycle Mgmt | 2 | Structural blocker: ApiResource::$schemaCache and RepositoryResolver::$map cannot be cleared without reflection; blocks full implementation of Problems 1-2 | 3 | Small scope: add public static clearCompiledSchemas() to ApiResource, extend RepositoryResolver::flush() to also clear $map | 2 | Internal API structure that enables the flush mechanism; supportive but not directly user-facing | 7 | P0 |
| 7 | Stale Resource Mappings Cause Mismatched Serialization | Data Corruption | 2 | Wrong field schema in responses violates API contract but does not cause data corruption; responses are still valid JSON | 2 | Same flush infrastructure; marginal effort is adding MODEL_RESOURCES key to the flush list | 3 | Resource serialization correctness is core toolkit behavior | 7 | P0 |
| 8 | Compiled Schema Cache Persists Indefinitely | Data Corruption | 2 | Affects field inclusion/exclusion and eager loading decisions; medium severity but compounding over time in long-running processes | 2 | Requires the flush API from Problem 6; once that exists, adding to centralized flush is trivial | 3 | Schema compilation drives resource output; correct compilation is core to API behavior | 7 | P0 |
| 9 | Test Isolation Requires Reflection | DX Friction | 1 | Affects developers writing tests, not production users or API consumers | 3 | Trivially solved by adding a public clearCompiledSchemas() method — same work as Problem 6 | 2 | Test quality supports the vision but is not central to it | 6 | P1 |
| 10 | Unused Cache Keys Create Confusion | DX Friction | 1 | Confusion during code review only; no behavioral impact on production or tests | 3 | Trivial: remove two enum cases and their tests, or document as reserved | 1 | Code hygiene; tangential to core product vision | 5 | P1 |

---

## Priority Tiers

| Tier | Criteria   | Action                        |
|------|------------|-------------------------------|
| P0   | Total >= 7 | Create PRD immediately        |
| P1   | Total 5-6  | Create PRD if capacity allows |
| P2   | Total <= 4 | Defer; revisit next cycle     |

### Tier Summary

| Tier | Count | Problems |
|------|-------|----------|
| P0   | 8     | No Centralized Cache Flush, No Automatic Lifecycle Event Integration, Stale Relation Detection, Stale Cast Maps, Schema Column Changes, Static Property Caches Lack Flush APIs, Stale Resource Mappings, Compiled Schema Cache Persists |
| P1   | 2     | Test Isolation Requires Reflection, Unused Cache Keys Create Confusion |
| P2   | 0     | — |

---

## Tie-Breaking Rationale

Five P0 problems share a total score of 8 and three share a total score of 7. The within-tier ranking is based on implementation dependency:

**Score 8 (Rank 1-5):**
- Rank 1-2: Infrastructure problems (Centralized Flush, Lifecycle Events) are ranked first because they are **enablers** — without them, the Data Corruption problems cannot be systematically resolved. The centralized flush mechanism is ranked above lifecycle events because the mechanism must exist before it can be wired to events.
- Rank 3-5: Data Corruption problems (Stale Relations, Stale Casts, Schema Columns) are ranked by severity signal from the problem map. All three are High severity / Occasionally frequency; they are ordered by the breadth of downstream impact (query filtering > attribute types > column metadata).

**Score 7 (Rank 6-8):**
- Rank 6: Static Property Flush APIs is ranked first because it is an **unblocker** — the centralized flush mechanism (Rank 1) cannot clear `ApiResource::$schemaCache` or `RepositoryResolver::$map` without public flush methods. Low effort makes it natural to address alongside Rank 1-2.
- Rank 7-8: Remaining Data Corruption problems ordered by severity (Medium impact; Resource Mappings rarely encountered vs Schema Cache occasionally encountered).

**Implementation note:** Problems 1, 2, and 6 form a single delivery unit. Problem 6 (flush APIs) must be implemented before or alongside Problems 1 (centralized flush) and 2 (lifecycle events), since the centralized flush needs to call APIs that do not yet exist.

---

## User Overrides

No overrides applied.

---

## Strategic Validation

| Field        | Value       |
|--------------|-------------|
| Validated by | Strategist  |
| Date         | 2026-03-10  |

**Alignment notes:** The scoring is well-aligned with the product vision. Impact scores of 3 across the Data Corruption and Lifecycle Management clusters correctly reflect that silent data corruption undermines the toolkit's core promise of correct API behavior. The DX Friction problems are appropriately scored as P1. The three-axis model produces sensible totals that align with strategic priority.

**Flags:**

1. **Dependency ordering (addressed):** Problem 6 (flush APIs) is a prerequisite for Problems 1-2 (centralized flush + lifecycle events). The ranking now reflects this dependency, and the three problems are noted as a single delivery unit.
2. **Broader scope than Octane:** `rememberForever()` with persistent cache drivers (Redis, file) causes staleness in standard FPM deployments too — not just long-running processes. The Impact rationale for Problem 5 now notes this. The PRD should consider whether the solution covers persistent store invalidation beyond in-process memoization.
3. **WritePoolFlushSubscriber does not exist:** ISSUES.md references it, but Spike 1 confirms it was never built. Effort scores already reflect building from scratch (Effort 2), so no score change needed. PRD authors should not assume existing infrastructure.
4. **No production incident data:** All severity ratings are based on code analysis, not observed incidents. This is acceptable for a toolkit transitioning to long-running deployments, but should be noted if the PRD justifies significant engineering investment.
5. **ApiQueryParser singleton adjacency:** The `ApiQueryParser` singleton (ISSUE-11) shares the same lifecycle boundary problem and could be reset by the same event subscriber at near-zero incremental effort. The PRD for Problems 1-2 should explicitly decide whether to include parser reset or defer to a separate effort.
6. **Rationale text corrected:** Problem 8 (Compiled Schema Persists) correctly scores Alignment 3 as a Data Corruption problem, not grouped with the DX Friction problems.

---

## References

- Problem Map: [problem-map.md](problem-map.md)
- Intake Brief: [intake-brief.md](intake-brief.md)
