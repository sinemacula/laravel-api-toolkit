---
name: php-quality-remediator
description: >
  Run deterministic static analysis via
  `composer check -- --all --no-cache --fix` and remediate findings by changing
  code first, using existing skills where appropriate. Configuration changes and
  large or breaking changes require manual approval.
---

# PHP Quality Remediator

## Purpose

Run deterministic quality gates using `composer check -- --all --no-cache --fix` and remediate any reported findings so
the checks pass.

End goal: `composer check -- --all --no-cache --fix` must complete successfully. Code must not be pushed while checks
are failing.

This skill prioritizes code fixes over configuration changes and uses existing skills to keep remediation consistent.

## Use This Skill When

- Any PHP code has changed and quality gates must pass before completion
- `composer check -- --all --no-cache --fix` fails locally or in CI
- A task requires deterministic enforcement of linting, static analysis, and code quality rules

## References

- `references/triage-matrix.md`: Load when check output is noisy and you need deterministic classification/remediation.

## Core Principles

- Fix the code, not the rules
- Prefer minimal, behavior-preserving changes
- Use existing remediation skills to keep changes consistent:
  - `$php-complexity-refactor`
  - `$php-naming-normalizer`
  - `$php-documenter`
  - `$php-styling`

## Hard Guardrails

- Always run `composer check -- --all --no-cache --fix` before attempting remediation
- Never change static analysis configuration as a first-line fix
- Configuration changes are a last resort and require explicit manual approval
- Do not ignore or suppress findings without explicit manual approval
- Preserve runtime behavior and public contracts unless explicitly approved
- Avoid drive-by refactors; keep changes scoped to resolving reported findings
- If remediation requires large changes or anything potentially breaking, stop and request approval

## Approval Boundaries

Manual approval is required for any of the following:

- Any change to static analysis, linting, or formatter configuration
- Any ignore or suppression of findings
- Any change that modifies public APIs, contracts, or externally visible behavior
- Any large remediation that materially changes structure or touches unrelated files

## Workflow

1. Run the deterministic quality gate
    - Execute `composer check -- --all --no-cache --fix`
    - Capture the failing tool, file(s), and exact messages
2. Classify the findings
    - Formatting / style
    - Documentation
    - Naming / readability
    - Complexity / maintainability
    - Static analysis correctness (types, unreachable code, invalid assumptions, etc.)
    - Other deterministic rule failures
3. Remediate using the smallest effective approach
    - Prefer code changes that preserve behavior
    - Use the most appropriate existing skill for the category:
        - Complexity: `$php-complexity-refactor`
        - Naming: `$php-naming-normalizer`
        - Documentation: `$php-documenter`
        - Style: `$php-styling`
        - If no relevant skill exists then just follow best documented practices
4. Re-run the gate
    - Re-run `composer check -- --all --no-cache --fix` after each remediation batch
    - Continue until passing or blocked
5. Escalate when necessary
    - If the only viable path is configuration change, ignore, or suppression:
        - Stop and return `approval-required` with a precise proposal
    - If the required remediation is potentially breaking or relatively large:
        - Stop and return `approval-required` with risk and scope summary
6. Confirm completion
    - `composer check -- --all --no-cache --fix` must pass with no remaining failures
    - Summarize results using the standard outcome contract

## Anti-Churn Guardrails

- Do not “clean up” unrelated issues while fixing check failures
- If `composer check -- --all --no-cache --fix` exposes many pre-existing issues:
  - Fix only issues introduced or touched by the current task unless explicitly asked to do a cleanup sweep
- Prefer batching related fixes, but keep diffs explainable and minimal

## Output Contract

- `status`: `completed-no-changes` | `changed` | `blocked` | `approval-required`
- `changed_files`: explicit list, or `[]` if no changes
- `rerun_required`: `true` | `false`
- `approval_items`: explicit list, or `[]`
- `blockers`: explicit list, or `[]`
