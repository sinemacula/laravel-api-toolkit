---
name: php-attribution
description: >
  Add high-signal, PHP-native attributes that improve correctness, tooling, and
  readability while preserving behavior and avoiding churn. Use when touched PHP
  code has clear override, sensitive-parameter, or established deprecation
  opportunities.
---

# PHP Attribute Enricher

## Purpose

Add appropriate PHP attributes that improve code clarity, correctness, and tooling support while preserving runtime
behavior and avoiding unnecessary churn.

End goal: attributes should be added only where they provide clear, defensible value and align with existing code
intent.

Attributes complement documentation and structure; they do not replace docblocks or introduce semantic changes.

## Use This Skill When

- Adding or refactoring code where attributes improve correctness or intent
- Clarifying contracts that benefit static analysis and human readers
- Introducing explicit metadata that reduces ambiguity without changing behavior

## References

- `references/attribute-examples.md`: Load when deciding between applicable attributes or when placement is ambiguous.

## Hard Guardrails

- Preserve runtime behavior exactly
- Do not add attributes speculatively
- Do not introduce new dependencies solely to use attributes unless explicitly requested and approved
- Do not add attributes that require configuration changes without explicit approval
- Keep changes scoped to files touched by the task unless explicitly asked to do a broader pass
- Avoid churn: do not add large numbers of attributes in one task unless explicitly requested
- If an attribute could be interpreted as a behavior change or public contract change, require approval
- **Only PHP-native attributes are allowed** — no IDE-, tool-, or vendor-specific attributes

## Attribute Adoption Policy

Attributes may be added automatically only when all of the following are true:

- The attribute is native to PHP and compatible with the project’s PHP version
- The target location is unambiguous (class, method, property, or parameter)
- The attribute provides high signal (correctness, safety, or clarity)
- The attribute does not introduce breaking changes or behavioral drift

If any of the above is not true, do not apply the attribute and return `approval-required` with a proposal.

## What to Add and How to Decide

### 1. #[\Override]

Add #[\Override] to methods that intentionally override a parent or interface method.

Apply when:

- The class extends another class and the method exists on the parent (or ancestor), or
- The class implements an interface and the method implements that interface method
- The override target can be confidently resolved from the repository

Do not apply when:

- The parent or interface cannot be resolved
- The method is not actually overriding anything

Reference:

- See `references/attribute-examples.md` for `#[\Override]` do/don't examples.

### 2. #[\SensitiveParameter]

Add #[\SensitiveParameter] to parameters that clearly represent secrets and may appear in stack traces.

Apply when the parameter meaning is unambiguous, for example:

- `$api_key`
- `$client_secret`
- `$token`
- `$password`
- `$private_key`

Do not apply based on vague or overloaded names.

Reference:

- See `references/attribute-examples.md` for `#[\SensitiveParameter]` examples and non-examples.

### 3. #[\Deprecated]

Use only when deprecation is already established by the codebase.

Apply when:

- There is an existing @deprecated docblock on the symbol, or
- The project has an explicit deprecation policy and the symbol is already treated as deprecated

Do not apply when:

- Deprecation is not already intended
- The symbol is part of a public API unless explicitly approved

Reference:

- See `references/attribute-examples.md` for `#[\Deprecated]` usage patterns.

### 4. #[\AllowDynamicProperties] (discouraged)

Only apply when dynamic properties are demonstrably required and refactoring is out of scope.

- This is a last resort
- Requires manual approval unless already established and required for backward compatibility

## Placement Rules

- Place attributes immediately above the declaration they apply to:
  - Above the class, trait, interface, or enum
  - Above the method
  - Inline on the parameter where applicable
- Preserve existing docblocks, formatting, and custom formatter directives
- Attributes must not replace or remove existing documentation

## Anti-Churn Guardrails

- Do not add attributes broadly “because they exist”
- Limit attribute additions to files touched by the task by default
- If a broader attribute enrichment would be beneficial, return `approval-required` with a short, explicit plan
- Prefer the smallest set of attributes with the highest signal

## Workflow

1. Identify task-touched PHP files
2. Scan for high-confidence attribute opportunities (Override, SensitiveParameter, established Deprecated)
3. Verify applicability by resolving inheritance, interfaces, and usage from the repository
4. Apply the smallest set of attributes that add clear value
5. Run `composer check -- --all --no-cache --fix` if non-test code was modified
6. Run relevant tests if executable code was modified
7. Return the standard outcome contract

## Approval Boundaries

Manual approval is required for:

- Any attribute that affects public contracts or could change runtime behavior
- Any introduction of new attribute dependencies or vendor packages
- Any broad, cross-cutting attribute enrichment
- Any use of #[\AllowDynamicProperties] unless already established and necessary

## Output Contract

- `status`: `completed-no-changes` | `changed` | `blocked` | `approval-required`
- `changed_files`: explicit list, or `[]`
- `rerun_required`: `true` | `false`
- `approval_items`: explicit list, or `[]`
- `blockers`: explicit list, or `[]`
