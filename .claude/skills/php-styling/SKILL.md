---
name: php-styling
description: >
    Enforce deterministic PHP style rules for naming, layout, and
    readability-focused formatting while avoiding churn and preserving behavior.
---

# PHP Styler

## Purpose

Apply consistent, readable PHP style rules across modified code.

End goal: formatting should be predictable and human-readable. This skill enforces mechanical style and layout rules
without introducing semantic refactors or broad churn.

## Use This Skill When

- Any PHP code is added, modified, or refactored
- Code style inconsistencies reduce readability
- A task requires code to conform to project-wide formatting and naming-style rules

## References

- `references/style-examples.md`: Load for canonical style patterns before applying broad changes.

## Hard Guardrails

- Preserve runtime behavior exactly
- Do not perform semantic renames or domain-driven naming changes (handled by `$php-naming-normalizer`)
- Do not change public APIs unless explicitly approved
- Keep changes scoped to files touched by the task
- Avoid churn: do not reformat unrelated files without explicit request
- If a style change would require large, cross-cutting edits, request approval

## Naming Style Rules

- Use PascalCase for type names (classes, interfaces, traits, enums)
- Use camelCase for properties
- Use SCREAMING_SNAKE_CASE for constants
- Use snake_case for method parameters and local variables
- Constructor-promoted properties follow property naming (camelCase) even though they appear as parameters

## Signature Wrapping Rule

- Keep method/function signatures on a single line by default
- Constructor signatures that contain promoted properties MUST be multi-line
- Signatures without promoted properties may be multi-line only when the equivalent single-line signature would exceed
  180 characters, measured using short/imported names (not FQCN)
- Do not wrap signatures for visual preference, alignment, or symmetry unless the signature contains promoted
  properties
- Signatures without promoted properties that fit within 180 characters MUST remain single-line

Reference:

- See signature examples in `references/style-examples.md`.

## Conditional Block Padding Rule

Use padding inside control blocks only when the block contains non-trivial logic.

- Simple blocks: no blank line padding after the opening brace
- For multi-line control blocks (`if`, `foreach`, `for`, `while`, `try/catch`), apply top padding only
- Do not add a trailing blank line before the closing brace of the same control block

Reference:

- See conditional padding examples in `references/style-examples.md`.

## Statement Grouping Rule

When a group of closely related statements prepares data for a subsequent operation, keep the preparation together and
separate it from the operation with a single blank line.

Reference:

- See statement grouping examples in `references/style-examples.md`.

## Anti-Churn Guardrails

- Do not reformat code that already matches these rules
- Prefer the smallest change that achieves compliance
- If multiple valid formatting options exist, follow the surrounding file style

## Workflow

1. Identify the files touched by the task
2. Apply naming-style rules mechanically (case conventions only)
3. Apply formatting/layout rules (signatures, padding, grouping)
4. Ensure changes remain scoped and do not introduce churn
5. Run tests if executable code was modified
6. Summarize results using the standard outcome contract

## Output Contract

- `status`: `completed-no-changes` | `changed` | `blocked` | `approval-required`
- `changed_files`: explicit list, or `[]` if no changes
- `rerun_required`: `true` | `false`
- `approval_items`: explicit list, or `[]`
- `blockers`: explicit list, or `[]`
