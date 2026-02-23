---
name: php-complexity-refactor
description: >
  Reduce PHP complexity by resolving objective tool-reported findings and
  optionally flagging AI-identified maintainability risks. Use when complexity
  thresholds are exceeded or when maintainability concerns need an approval-gated
  refactor proposal.
---

# PHP Complexity Refactor

## Purpose

Resolve PHP complexity issues with minimal, behavior-preserving refactors.

This skill operates in two modes:

- **Auto-Refactor Mode (default):** Applies refactors only when objective, tool-reported complexity thresholds are
  exceeded.
- **Advisory Mode:** Uses AI judgment to flag cognitive/maintainability complexity risks and propose refactors, but does
  not modify code without explicit approval.

## Use This Skill When

- Static analysis reports concrete PHP complexity findings (e.g. cyclomatic/cognitive complexity, nesting, method
  length)
- A method or class is difficult to understand or maintain and would benefit from extraction, decomposition, or
  simplification

## References

- `references/refactor-patterns.md`: Load when you need low-risk refactor shapes for common complexity findings.

## Hard Guardrails

- Preserve runtime behavior and public contracts unless explicitly approved
- Do not ignore or suppress findings without explicit manual approval
- Do not change static analysis configuration unless explicitly requested or manually approved
- Avoid speculative or stylistic refactors; keep changes scoped to the complexity finding or approved refactor plan

### Mode Rules

- **Auto-Refactor Mode:** Code changes are permitted only when objective tool thresholds are exceeded
- **Advisory Mode:** AI may identify complexity risks and propose refactors, but MUST NOT change code unless explicit
  approval is granted

## Approval Boundaries

Manual approval is required for:

- Any ignore or suppression decision
- Any public API, contract, or externally visible behavior change
- Any refactor driven primarily by AI-identified cognitive/maintainability complexity (i.e. no objective tool threshold
  violation)

## Workflow

1. Confirm and scope complexity signals
    - Identify objective tool-reported findings (if any)
    - Optionally assess cognitive/maintainability risk using AI judgment
2. Decide the mode
    - If objective thresholds are exceeded: proceed in **Auto-Refactor Mode**
    - If only AI-identified complexity exists: proceed in **Advisory Mode**
3. Execute
    - **Auto-Refactor Mode:** Apply the smallest refactor that reduces complexity while preserving behavior
    - **Advisory Mode:** Produce a recommended refactor plan and request approval; do not change code
4. Test policy
    - Run targeted tests when code changes occur
    - If refactors touch shared/public behavior, prefer a broader test scope
5. Summarize results using the standard outcome contract

## Output Contract

- `status`: `completed-no-changes` | `changed` | `blocked` | `approval-required`
- `changed_files`: explicit list, or `[]` if no changes
- `rerun_required`: `true` | `false`
- `approval_items`: explicit list, or `[]`
- `blockers`: explicit list, or `[]`
