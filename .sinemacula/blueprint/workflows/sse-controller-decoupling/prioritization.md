# Prioritization: SSE Implementation Decoupled from Controller

Scoring and ranking the ten problems identified in the SSE controller-decoupling problem map to determine which deserve PRDs and in what order.

---

## Governance

| Field     | Value                                   |
|-----------|-----------------------------------------|
| Created   | 2026-03-10                              |
| Status    | draft                                   |
| Owned by  | Product Analyst                         |
| Traces to | [Problem Map](problem-map.md)           |

---

## Scoring Axes

| Axis      | Question                                           | Scale                            |
|-----------|----------------------------------------------------|----------------------------------|
| Impact    | How significant is this problem for users?         | 1 (low) - 3 (high)               |
| Effort    | How much effort is required to solve this?         | 1 (high effort) - 3 (low effort) |
| Alignment | How well does solving this fit the product vision? | 1 (low) - 3 (high)               |

**Note:** Effort is inverted (3 = low effort = better) so that total score is a direct priority signal -- higher total =
higher priority.

---

## Problem Scores

| Rank | Problem | Cluster | Impact | Impact Rationale | Effort | Effort Rationale | Alignment | Alignment Rationale | Total | Priority |
|------|---------|---------|--------|------------------|--------|------------------|-----------|---------------------|-------|----------|
| 1 | P2: SSE Logic Cannot Be Used Outside the Controller Hierarchy | Inheritance Burden | 3 | This is the core problem motivating the entire workflow. Every developer who needs SSE outside the controller hierarchy is blocked entirely -- they must either extend the controller (inheriting unrelated concerns) or duplicate the implementation. This is a hard barrier, not a friction issue. | 3 | Extraction of methods from a base class into a standalone class is a well-understood refactoring pattern. The methods already exist and have defined behavior; the work is moving them, not inventing them. | 3 | Directly advances separation of concerns and reusability, which are central to the product vision. The intake brief identifies this as the primary problem signal. | 9 | P0 |
| 2 | P1: SSE Logic Inflates the Base Controller | Inheritance Burden | 3 | Affects every developer extending the base controller on a daily basis. 54% of the controller body is SSE-specific code that most controllers never use. This inflates cognitive load for all users of the toolkit, not just SSE consumers. | 3 | Resolving this problem is a direct consequence of extracting SSE logic (Problem 2). Once SSE methods are moved to a dedicated class, this problem is resolved with no additional effort beyond the extraction itself. | 3 | Separation of concerns is the most central tenet of the product vision. Removing transport-protocol logic from the base controller directly advances architectural cleanliness. | 9 | P0 |
| 3 | P5: No Protection Against Malformed SSE Output | Specification Conformance | 3 | When malformed output occurs, it silently corrupts the SSE stream with no error signal. This affects any developer whose callback data contains newlines (common in JSON payloads), and the failure is silent -- making it especially difficult to diagnose. | 2 | Requires designing and implementing a validation/encoding layer for SSE wire-format output. The newline-splitting rule is well-defined in the specification, but the solution requires an emitter abstraction that does not yet exist. Moderate scope. | 3 | Conforming to established specifications is an explicit product vision goal. Preventing silent data corruption directly serves consistency and reliability. | 8 | P0 |
| 4 | P4: Error Events Are Silently Dropped by Spec-Compliant Clients | Specification Conformance | 3 | When server-side errors occur during streaming, the client receives nothing -- the error event is silently discarded per the WHATWG specification. This is a correctness bug: the implementation intends to signal errors but the signal never arrives. Affects every SSE consumer in error scenarios. | 2 | Requires modifying the error event emission to include a `data:` field alongside the `event:` field. The fix itself is small, but it depends on having an emitter abstraction or at least a corrected wire-format emission. Achievable within one cycle. | 3 | Following established standards is an explicit product vision goal. Emitting spec-conformant events is a baseline expectation for a toolkit that aims for consistency. | 8 | P0 |
| 5 | P6: Consumers Must Know SSE Wire Format to Emit Events | Specification Conformance | 2 | Every developer writing an SSE callback encounters this daily -- they must manually echo raw wire-format strings. This is friction, not a hard barrier (developers can learn the format), but it increases the chance of errors and is inconsistent with a toolkit that should abstract transport details. | 2 | Requires designing an emitter interface/class that abstracts the wire format. This is moderate scope: it needs an API design decision (method signatures, named events support, data serialization) and implementation, but the domain is well-understood. | 3 | A toolkit's purpose is to provide consistent abstractions. Requiring consumers to know wire-format details contradicts the goal of clean separation between application logic and transport protocol. | 7 | P0 |
| 6 | P3: SSE Behaviour Is Frozen with Minimal Extension Points | Inheritance Burden | 2 | Affects developers who need to customize SSE behavior beyond heartbeat timing. The two core methods are private, leaving no override path. However, this is "occasionally" encountered -- most users may not need customization beyond the defaults. | 2 | Providing extension points (making methods overridable, adding configuration, or using dependency injection) is moderate scope. The design needs to balance flexibility with simplicity, but the patterns are well-established in Laravel. | 3 | Extensibility and clean separation of concerns are core to the product vision. A dedicated SSE class with proper extension points directly advances this. | 7 | P0 |
| 7 | P8: SSE Testing Requires Heavyweight Function Override Infrastructure | Testing and Maintenance | 2 | Affects package maintainers and contributors weekly. The 135-line fixture file and 56-line registry class for overriding four PHP built-in functions is a meaningful maintenance burden, but the affected audience is narrower (maintainers, not all users). | 2 | Reducing test infrastructure complexity depends on the SSE extraction itself. If the extraction introduces proper abstractions (injectable dependencies instead of direct PHP function calls), the test overrides become unnecessary. Moderate effort because it depends on the extraction design. | 2 | Testability is an explicit product vision goal, but testing infrastructure improvements primarily benefit maintainers rather than end-users of the toolkit. Supportive of the vision but not central to it. | 6 | P1 |
| 8 | P10: Content Negotiation and Response Construction Are Implicitly Coupled | Implicit Coupling | 1 | The shared `text/event-stream` string literal is a minor coupling concern. The risk of divergence is low because the string is a well-known MIME type unlikely to change, and the consequence of a mismatch would be caught quickly in testing. Affects a narrow scenario (modifying SSE content-type handling). | 3 | Introducing a shared constant or enum for the MIME type is a trivial change -- a single constant definition and two reference updates. Very low effort. | 2 | Reducing implicit coupling supports clean architecture, but this specific instance is a minor housekeeping concern rather than a structural problem. | 6 | P1 |
| 9 | P9: Duplicated Output Buffer Management Across Two Components | Testing and Maintenance | 1 | The duplication exists in exactly two places and affects a rarely-changed concern (output buffer flushing). The practical risk of divergence is low because the pattern is simple (three lines) and changes to buffer management are infrequent. | 3 | Extracting a shared buffer-flush helper or incorporating it into the SSE abstraction is trivial -- a few lines of code. This is likely resolved as a natural byproduct of the SSE extraction. | 2 | Reducing duplication supports consistency, but this is a minor maintenance concern rather than a structural problem affecting the product vision. | 6 | P1 |
| 10 | P7: No Support for Client Reconnection Protocol | Specification Conformance | 1 | As noted in the problem map and confirmed by user context, event ordering and sequencing is the consuming application's responsibility. This problem is scoped to providing wire-format primitives (`id:` and `retry:` fields) only, not implementing reconnection logic. The affected frequency is "rarely" and the severity is "low" in the problem map. Most SSE consumers in this toolkit's context may not need reconnection support. | 2 | Adding `id:` and `retry:` field support to an emitter abstraction is moderate scope. The fields themselves are simple (string/integer values in the wire format), but this depends on the emitter abstraction existing first (Problem 6) and requires API design decisions about how consumers specify IDs and retry values. | 2 | Providing complete specification coverage supports the standards-conformance vision, but the problem map and user context explicitly position reconnection as an application-level concern. The package's role is limited to offering the primitives, making this supportive but not central. | 5 | P1 |

---

## Priority Tiers

| Tier | Criteria   | Action                        |
|------|------------|-------------------------------|
| P0   | Total >= 7 | Create PRD immediately        |
| P1   | Total 5-6  | Create PRD if capacity allows |
| P2   | Total <= 4 | Defer; revisit next cycle     |

---

## User Overrides

_No user overrides applied. This section will be updated if the user adjusts any rankings._

| Problem   | Original Rank | New Rank | Rationale                    |
|-----------|---------------|----------|------------------------------|

---

## Strategic Validation

| Field        | Value      |
|--------------|------------|
| Validated by | Strategist |
| Date         | 2026-03-10 |

**Alignment notes:**

1. **P1 and P2 scores are defensible and well-calibrated.** Both score 9/9. P2 (SSE logic cannot be used outside the controller hierarchy) is correctly ranked first as the foundational extraction that enables everything else. P1 (SSE logic inflates the base controller) correctly identifies that it resolves as a direct consequence of P2 with no additional effort. The 3/3 effort scores are appropriate: moving existing, well-understood methods into a new class is a standard refactoring pattern. Both problems directly advance the core product vision tenet of separation of concerns.

2. **P5 and P4 scores are internally consistent.** Both score 8 with Impact 3, Effort 2, Alignment 3. The Impact 3 scores are justified: P5 involves silent data corruption and P4 is a correctness bug where the implementation's intent (signaling errors) is silently defeated by the specification. Effort 2 is appropriate for both because each depends on an emitter abstraction that does not yet exist. Alignment 3 is correct: specification conformance is an explicit product vision goal.

3. **P6 and P3 scores are reasonable at 7.** P6 (consumers must know wire format) correctly receives Impact 2 rather than 3 because it is friction, not a hard barrier -- developers can learn the format. P3 (frozen extension points) correctly receives Impact 2 because customization beyond heartbeat timing is an occasional rather than daily concern. Alignment 3 for both is appropriate as they directly advance the toolkit's purpose of providing clean abstractions.

4. **P1 tier (P8, P10, P9) scores are well-differentiated from P0.** The lower impact and alignment scores for these problems correctly reflect their narrower audience (maintainers rather than all toolkit users) and their status as housekeeping concerns rather than structural problems.

5. **P7 (reconnection protocol) is correctly positioned at the bottom of P1.** The Impact 1 and Alignment 2 scores are well-calibrated to the user's explicit guidance that event ordering and sequencing is the consuming application's responsibility. Scoring this as Impact 1 correctly reflects that the package's role is limited to providing wire-format primitives, not implementing reconnection logic.

6. **Backward compatibility is a hard constraint throughout.** The intake brief and problem map both identify backward compatibility as a requirement. This does not need to affect individual problem scores (it applies equally to all problems), but every resulting PRD must address how backward compatibility is preserved. The extraction (P2) must ensure `respondWithEventStream()` continues to work without modification for existing call sites.

7. **No problems appear over-scored or under-scored.** The rationales are evidence-based, citing specific spike findings, line counts, and specification text. The distinction between Impact 3 (hard barrier or correctness bug) and Impact 2 (friction or occasional concern) is applied consistently across all ten problems.

**Flags:**

1. **DEPENDENCY: P6 (emitter abstraction) is a prerequisite for P5, P4, and P7 but is ranked below them.** P6 (Rank 5, score 7) describes the missing emitter abstraction. P5 (Rank 3, score 8) and P4 (Rank 4, score 8) both depend on this abstraction to implement their solutions -- P5 needs the emitter to validate/encode wire-format output, and P4 needs the emitter to include a `data:` field alongside the `event:` field. P7 (Rank 10, score 5) needs the emitter to support `id:` and `retry:` fields. The scoring is correct (P5 and P4 are higher-impact problems), but PRD creation and implementation ordering must respect this dependency. The PRD for the emitter abstraction (P6) should either be created first or bundled with the extraction PRD (P2), since the emitter is a natural component of the extraction.

2. **LARGE P0 TIER: Six problems in P0 may exceed single-cycle capacity.** The P0 tier contains six problems (P1, P2, P3, P4, P5, P6). While the scores are individually justified, this is a large set for "create PRD immediately." In practice, P1 and P2 are a single extraction effort, and P6 is a natural component of that extraction. P3 (extension points) is also a design consideration within the extraction. This effectively means the extraction PRD (covering P1, P2, P3, and P6) is the critical first deliverable, with P4 and P5 as follow-on PRDs that build on the emitter abstraction. The user should consider whether some P0 problems should be bundled into a single PRD to reflect their implementation coupling.

3. **NO CRITICAL MISALIGNMENT FOUND.** All scores align with the product vision tenets (separation of concerns, testability, consistency, following standards, backward compatibility). The prioritization correctly places the core architectural extraction at the top and positions specification-conformance improvements as dependent follow-ons. The P1 tier appropriately captures maintenance and housekeeping concerns that support but do not drive the product vision.

---

## References

- Problem Map: [problem-map.md](problem-map.md)
- Intake Brief: [intake-brief.md](intake-brief.md)
