---
name: php-test-author
description: >
  Author and update PHP tests with behavior-focused, deterministic coverage
  while enforcing the full existing PHP quality-skill chain.
---

# PHP Test Author

## Purpose

Author and maintain high-signal PHP tests that verify behavior and prevent regressions.

End goal: tests are deterministic, readable, and validated through the same quality gates used for production PHP code.

## Use This Skill When

- New functionality needs tests
- A bug fix needs a regression test
- Existing tests must be updated for behavior changes
- Touched code has coverage gaps that should be closed
- Existing, known coverage gaps in the same domain area should be closed

## References

- `references/test-patterns.md`: Load for project-native test patterns and provider shapes.

## Core Principles

- Test behavior, not implementation details
- Prefer deterministic tests; avoid uncontrolled time, randomness, and network
- Keep tests minimal and scoped to the requested change
- Follow existing test patterns and naming conventions in the repository
- Follow modern PHPUnit practices:
  - Prefer attributes over legacy annotations where supported
  - Use data providers for repeated scenario matrices
  - Prefer explicit named `yield` datasets over `yield from [...]` blocks in providers so failure output is
      self-describing
  - Use only directives supported by the current PHPUnit/PHP version
  - Use `@internal` only when it is meaningful and supported

## Hard Guardrails

- After authoring tests, always run the existing PHP quality skills in this order:
    1. `$php-complexity-refactor`
    2. `$php-naming-normalizer`
    3. `$php-styling`
    4. `$php-documenter`
    5. `$php-attribution`
    6. `$php-quality-remediator`
- Never skip quality skills for test files
- Do not assert on real external systems
- Avoid unrelated test rewrites and drive-by cleanup
- Preserve public contracts unless explicit approval is provided
- Place tests in the project's configured suite folders (for example Unit, Integration, Feature) and avoid higher-level
  suites when lower-level suites sufficiently validate behavior
- When fixtures, stubs, mocks, or data sets become non-trivial, split them into dedicated files for maintainability

## Test Strategy Baseline

- Add only the smallest set of tests that proves intended behavior
- Cover the primary path and meaningful edge/failure paths
- Use clear Arrange, Act, Assert structure
- Prefer descriptive test names that explain expected behavior
- If code is hard to test, propose a minimal refactor before over-mocking
- Determine the required test level first:
  - Use unit tests by default
  - Use integration tests when collaboration between components matters
  - Use feature tests only when end-to-end behavior is the requirement

## Workflow

1. Identify changed behavior and required scenarios
2. Select the correct test suite (`Unit`, `Integration`, or `Feature`)
3. Add or update tests in the closest existing suite
4. Verify tests are deterministic and scoped
5. Run the full PHP quality-skill chain on changed PHP files, including tests
6. Run `composer test` or the relevant PHPUnit command for the changed test file or method
7. If remediation changes code, rerun the skill chain (max 3 passes)
8. Return results using the standard outcome contract

## Approval Boundaries

Manual approval is required for:

- Public API or behavior changes outside requested scope
- Large, cross-cutting test rewrites unrelated to the task
- Any ignore/suppression for failing checks
- Static analysis or formatter configuration changes

## Output Contract

- `status`: `completed-no-changes` | `changed` | `blocked` | `approval-required`
- `changed_files`: explicit list, or `[]` if no changes
- `rerun_required`: `true` | `false`
- `approval_items`: explicit list, or `[]`
- `blockers`: explicit list, or `[]`
