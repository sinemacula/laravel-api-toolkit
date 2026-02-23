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
- Preserve runtime behavior and public contracts unless explicitly approved
- Avoid drive-by refactors; keep changes scoped to resolving reported findings
- If remediation requires large changes or anything potentially breaking, stop and request approval

## Remediation Strategy

**Exhaust code-level solutions before resorting to suppression.** For every finding, iterate through conforming code
changes first. Any approach is acceptable as long as it does not promote bad practice or degrade code quality. Be
creative — adjust types, add assertions, restructure signatures, use type narrowing, or rework logic to satisfy the
tools. Only after **3 genuine attempts** at a code fix have failed should suppression be considered. Suppression is a
last resort, not a convenience.

When suppression is genuinely unavoidable, use the correct format for the tool:

- **PHPStan**: `/** @phpstan-ignore rule.name (justification) */` on the line above the subject
- **radarlint/SonarPHP**: `@SuppressWarnings("php:SXXXX")` in the class/method docblock. Prefer class-level when the
  entire class legitimately triggers the rule; use method-level for isolated cases. Avoid `// NOSONAR` as it suppresses
  all rules indiscriminately.
- **PHP_CodeSniffer**: `// phpcs:ignore Rule.Name -- justification` on the line above the subject. When a line-above
  comment would break the docblock-to-function association (e.g. between a docblock and a function declaration), use an
  **inline end-of-line** `// phpcs:ignore Rule.Name` on the function declaration instead. Do not place phpcs directives
  inside docblocks. Do not use `phpcs:disable`/`phpcs:enable` blocks to wrap individual methods.
- For PHPStan and radarlint, prefer line-above or docblock placement over end-of-line comments.
- Never modify configuration files in `.qlty/configs/` or tool-level config without explicit manual approval

## Approval Boundaries

Manual approval is required for any of the following:

- Any change to static analysis, linting, or formatter configuration files (`.qlty/configs/`, `phpstan.neon`, etc.)
- Broad suppression patterns (e.g. baseline files, rule-level disables); inline code suppressions with justification are
  permitted without approval
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
- **Formatter/check loop detection**: If a code fix triggers a formatter change, which re-triggers the original issue
  (or vice versa), **first consult the "Known Circular Conflicts" table in `references/triage-matrix.md`**. If the
  conflict matches a known pattern, apply the documented resolution (e.g. routing to `$php-documenter` to add a missing
  doc block) rather than escalating. Only if no known resolution applies should you stop after **3 cycles**, report the
  conflicting file(s), the two competing rules, and the exact changes oscillating.

## Output Contract

- `status`: `completed-no-changes` | `changed` | `blocked` | `approval-required`
- `changed_files`: explicit list, or `[]` if no changes
- `rerun_required`: `true` | `false`
- `approval_items`: explicit list, or `[]`
- `blockers`: explicit list, or `[]`
