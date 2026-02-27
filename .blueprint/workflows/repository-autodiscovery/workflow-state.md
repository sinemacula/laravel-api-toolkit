# Workflow State: Repository Autodiscovery

Eliminate manual repository registration via autodiscovery — workflow tracking.

---

## Governance

| Field     | Value                                                              |
|-----------|--------------------------------------------------------------------|
| Created   | 2026-02-26                                                         |
| Status    | intake                                                             |
| Owned by  | Ben                                                                |
| Traces to | .blueprint/workflows/repository-autodiscovery/intake-brief.md      |

---

## Idea Summary

| Field          | Value                                                                                       |
|----------------|---------------------------------------------------------------------------------------------|
| Intake Brief   | .blueprint/workflows/repository-autodiscovery/intake-brief.md                               |
| Idea           | Autodiscover repository classes and resolve their aliases without manual config registration |
| Target user    | Developers using laravel-api-toolkit in large/modular Laravel applications                  |
| Problem signal | Manual repository_map maintenance is a scaling bottleneck for large modular applications     |

---

## Phases

| # | Phase           | Status  | Started    | Completed | Artifacts                                                             |
|---|-----------------|---------|------------|-----------|-----------------------------------------------------------------------|
| 0 | Intake          | active  | 2026-02-26 | --        | .blueprint/workflows/repository-autodiscovery/intake-brief.md         |
| 1 | Discovery       | pending | --         | --        | --                                                                    |
| 2 | Problem Mapping | pending | --         | --        | --                                                                    |
| 3 | Prioritization  | pending | --         | --        | --                                                                    |
| 4 | PRD Creation    | pending | --         | --        | --                                                                    |

---

## Phase Details

### Phase 0: Intake

| Field  | Value                 |
|--------|-----------------------|
| Role   | Orchestrator (inline) |
| Input  | User idea             |
| Output | Intake brief          |

**Artifacts:**

| # | Type         | Title                    | Path                                                          | Status |
|---|--------------|--------------------------|---------------------------------------------------------------|--------|
| 1 | intake-brief | Repository Autodiscovery | .blueprint/workflows/repository-autodiscovery/intake-brief.md | draft  |

### Phase 1: Discovery

| Field  | Value           |
|--------|-----------------|
| Role   | Researcher      |
| Input  | Intake brief    |
| Output | Spike documents |

**Artifacts:**

| # | Type | Title | Path | Status |
|---|------|-------|------|--------|

### Phase 2: Problem Mapping

| Field  | Value                        |
|--------|------------------------------|
| Role   | Product Analyst              |
| Input  | Spike documents from Phase 1 |
| Output | Problem map                  |

**Artifacts:**

| # | Type | Title | Path | Status |
|---|------|-------|------|--------|

### Phase 3: Prioritization

| Field  | Value                       |
|--------|-----------------------------|
| Roles  | Product Analyst, Strategist |
| Input  | Problem map from Phase 2    |
| Output | Prioritized problem list    |

**Artifacts:**

| # | Type | Title | Path | Status |
|---|------|-------|------|--------|

### Phase 4: PRD Creation

| Field  | Value                             |
|--------|-----------------------------------|
| Role   | Product Analyst                   |
| Input  | Prioritized problems from Phase 3 |
| Output | PRD documents                     |

**Artifacts:**

| # | Type | Title | Path | Status |
|---|------|-------|------|--------|

---

## Log

| Date       | Phase  | Event                              |
|------------|--------|------------------------------------|
| 2026-02-26 | Intake | Intake brief created from user idea |

---

## References

- Intake Brief: .blueprint/workflows/repository-autodiscovery/intake-brief.md
