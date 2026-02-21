# AGENTS.md

## Project Overview

Laravel API Toolkit is Sine Macula's Laravel integration package for building and operating RESTful APIs with
consistent query parsing, resource formatting, exception handling, and repository-driven data access.

Current implementation includes:

- API service provider integration with middleware, request macros, publishing, and morph-map registration
- Query parsing utilities for fields, filters, sorting, pagination, aggregates, and request-scoped API controls
- API-focused repository abstractions built on `sinemacula/laravel-repositories`
- Resource primitives for consistent API payloads, collections, polymorphic rendering, and schema definitions
- Optional auto-discovery for model/resource and repository alias maps across standard and module-based structures
- Schema-strict resource field resolution to prevent undeclared dynamic output fields
- Structured API exception hierarchy with centralized mapping, JSON rendering, and debug-aware metadata
- Middleware extensions for parsing, throttling, pretty-printing, and maintenance-mode behavior
- Logging integrations for API exceptions and notifications, including optional CloudWatch support
- Octane-aware repository resolution controls to avoid cross-request state leakage
- Traits, contracts, enums, and helpers for reusable API behavior and consistent application-level integration

This repository is intended to remain:

- Laravel-specific
- RESTful API toolkit focused
- Minimal, maintainable, and explicit

## Namespace Structure

- Root namespace: `SineMacula\ApiToolkit\`
- Source: `src/` -> `SineMacula\ApiToolkit\`
- Tests: `tests/` -> `Tests\`

### Domain Scope

The package currently centers around:

- API request query parsing and safe request-level parameter extraction
- API repository composition, criteria application, and model schema-aware attribute handling
- API resource transformation patterns for single models, collections, and polymorphic relationships
- Strict schema-based response field governance for API resources
- Convention- and config-driven autodiscovery for resource and repository map registration
- Exception normalization into consistent error responses with internal error code support
- Middleware-level request handling for parser registration, throttling behavior, and maintenance controls
- Logging and notification instrumentation for operational visibility
- Package configuration surfaces for resources, parser defaults, repositories, exports, and logging
- Compatibility with Laravel database, HTTP, routing, support, and notification component expectations

This package is an integration layer. It must not become a generic application framework, cross-framework abstraction
library, or an application business workflow engine.

## Agent Role and Responsibility

The agent acts as a **feature implementer, bug fixer, and quality gate enforcer**.

It is responsible for:

- Implementing requested changes
- Orchestrating the correct skills in the correct order
- Ensuring deterministic quality gates pass
- Avoiding churn, speculative refactors, and scope creep
- Producing code that is readable, documented, tested, and ready to merge

The agent is **not** responsible for:

- Changing static analysis configuration without approval
- Introducing breaking changes unless explicitly requested
- Performing broad refactors outside the scope of the task

## PHP Standards

- Use strict types and type hints everywhere
- Declare explicit scalar/object types on class constants (for example `private const string FOO = 'bar';`)
- Do not nest control structures beyond 1 level (exceptionally 2)
- Use `match` for complex conditionals; avoid long `if/else if` chains and `switch`
- Prefer immutable data structures where they improve clarity
- Avoid exceptions for control flow
- Use PSR-4 autoloading and namespaces
- Follow PSR-12 formatting
- Every class must include a class-level docblock with a concise summary, `@author`, and `@copyright`
- For internal classes (including tests and test-support helpers), include `@internal` after copyright
- Property and constant doc comments must use single-line `@var` format
- Use dedicated, domain-specific exceptions
- Trust type declarations; avoid defensive verbosity
- Maintain backward compatibility unless explicitly instructed otherwise
- This repository must not expand into unrelated provider integrations or application-specific business workflows unless
  explicitly requested and confirmed

## Design Principles

- Start simple; refactor only when required (YAGNI)
- Apply DDD principles where appropriate (entities, value objects, services)
- Follow Clean Code and SOLID principles
- Depend on interfaces, not implementations
- Preserve Laravel-facing contracts and API toolkit compatibility
- Use `readonly` classes where immutability is appropriate

## Mandatory Skill Coverage

For any code content change, the agent MUST run the relevant skills for that language, including code written in
non-code files (for example Markdown code fences).

For any code content change, the agent MUST run a self-review gate after implementation and before running any skill
chain, formatter, static analysis command, or test command.

Self-review must verify:

- The implemented change matches the request and remains in scope
- Public contracts and expected behavior are preserved (unless explicitly changed)
- Edge cases, regression risk, and required tests/docs updates are addressed

If self-review finds issues, the agent MUST fix them and repeat self-review before proceeding.

For any PHP code content change, **all relevant PHP skills must be run**. This includes PHP in `src/`, tests, and PHP
snippets embedded in documentation files such as `README.md`.

For any Markdown content change, use `$markdown-styling` to remove duplication, wrap lines at 120 characters, and keep
documentation style consistent.

No discretionary skipping is allowed.

### Available Skills

- PHP Test Author: `$php-test-author`
- PHP Complexity Refactor: `$php-complexity-refactor`
- PHP Naming Normalizer: `$php-naming-normalizer`
- PHP Style Enforcer: `$php-styling`
- PHP Documenter: `$php-documenter`
- PHP Attribute Enricher: `$php-attribution`
- PHP Quality Remediator: `$php-quality-remediator`
- Markdown Styling: `$markdown-styling`

## Constraint-Based Execution Sequence

For PHP content, the agent MUST follow this order:

1. **Self-Review Gate** (mandatory before any PHP skill or quality command)
2. **PHP Test Author** (when tests are added/updated or new coverage is required)
3. **PHP Complexity Refactor**
4. **PHP Naming Normalizer**
5. **PHP Style Enforcer**
6. **PHP Documenter**
7. **PHP Attribute Enricher**
8. **PHP Quality Remediator**
9. **Tests**

Rules:

- PHP test authoring must use `$php-test-author` and must not replace any existing PHP quality step
- Self-review must complete before steps 2-8
- If self-review identifies issues, remediation and a repeat self-review are mandatory before continuing
- Complexity must run before naming, style, or documentation
- Tests must be run after steps 1-8 when executable PHP files are modified
- Quality remediation is the final gate; code must not push unless it passes
- The same sequence applies to PHP snippets in non-code files (for example, Markdown)
- If only documentation snippets were changed and no executable PHP files were modified, tests may be skipped, but steps
  1-9 remain mandatory

## Rerun Policy

- If `$php-quality-remediator` changes code, rerun the full PHP skill chain
- Maximum passes per language lane per task: **3**
- If unresolved issues remain after the pass budget is exhausted:
  - Stop
  - Return `blocked` or `approval-required` with a clear explanation

## Editing Guardrails

- Do not edit generated or cached artifacts unless explicitly requested
- Keep changes scoped to the task
- Avoid drive-by refactors
- Do not modify static analysis or formatter configuration without approval
- Do not modify agent skills without approval

### Approval Gates

Manual approval is required for:

- Any ignore or suppression of quality findings
- Any static analysis or formatter configuration change
- Any potentially breaking or large-scale change

## Documentation Responsibilities

- All code must be documented per `$php-documenter`
- Code snippets in docs (for example in `README.md`) must follow the same standards as source code and be validated with
  the relevant skills
- If a change introduces **new functionality** or **modifies existing behavior**, the agent MUST:
  - Update `README.md` accordingly
  - Update `AGENTS.md` accordingly
  - Ensure documentation accurately reflects the new or changed behavior
- Documentation updates are mandatory, not optional

## Canonical Commands

- Install dependencies: `composer install`
- Lint, static analysis, and auto-fix: `composer check -- --all --no-cache --fix`
- Run lint/static analysis without auto-fix: `composer check`
- Format code: `composer format`
- Run tests: `composer test`
- Run tests with coverage: `composer test-coverage`
- Run a single test file: `vendor/bin/phpunit tests/Unit/ExampleTest.php`
- Run a single test method:
  `vendor/bin/phpunit --filter testThatTrueIsTrue tests/Unit/ExampleTest.php`

## Tests & Quality

- Use `composer test` (parallel PHPUnit via Paratest) for deterministic local checks
- Test API query parsing, repository behavior, resource output, exception handling, and contract stability
- If code is not easily testable, propose refactoring before adding tests

### Test Writing

- Use `$php-test-author` for all test authoring and test updates
- When tests are changed, run the self-review gate and full PHP quality chain before `composer test`
- Unit tests must mirror source file paths (for example `src/Foo/Bar.php` -> `tests/Unit/Foo/BarTest.php`)
- Preserve 100% coverage baseline across classes, methods, and lines; coverage regressions are not acceptable

## Branch Naming Convention

- Prefix branches with:
  - `feature/`
  - `bugfix/`
  - `hotfix/`
  - `refactor/`
- Branch names SHOULD include the GitHub issue number when available Format:
  `<type>/issue-<number>-short-hyphenated-description` Example:
  `feature/issue-123-add-persistent-criteria-controls`
- If no issue exists, use a concise, hyphenated description Format: `<type>/short-hyphenated-description` Example:
  `refactor/simplify-repository-criteria-application`
- Keep names lowercase, concise, and hyphenated

## Commit Message Guidelines

- Use Conventional Commits
- Clearly describe what changed and why
- Reference GitHub issues where applicable (for example `Refs #123`)
- Never mention AI tools in commits or code comments
- PRs MUST reference related GitHub issues where applicable (for example `Closes #123` in the PR body)
- Squash merge commit message MUST default to PR title (repository setting)

## Pull Request Description Guidelines

- PR bodies MUST be valid Markdown with real newlines
- Never submit escaped newline sequences (for example `\n`) as literal body text
- Prefer `gh pr create --body-file <file>` over inline `--body` strings
- If `--body` is used, verify shell quoting produces real newlines
- Validate rendered PR Markdown before handoff

Template:

```md
## Summary
- change one
- change two

## Notes
- additional context
```

## Session Completion

**Work is NOT complete until changes are pushed successfully.**

### Mandatory Workflow

1. Run quality gates (if code changed):
    - `composer check -- --all --no-cache --fix`
    - `composer test`
2. Sync and push feature branch:

   ```bash
   git fetch origin
   git rebase origin/master
   git push -u origin HEAD
   git status  # MUST show "up to date with origin"
   ```

3. **Critical Branch Rule**
    - Never rebase or force-push `master`
    - Rebase is allowed only on local feature branches prior to PR merge
4. **Clean up**
    - Clear stashes
    - Prune remote branches
5. **Verify**
    - All changes committed
    - All changes pushed
6. **Hand off**
    - Provide context for next session

## Branching Policy (Trunk-Based)

- All branches MUST be created from `master`
- All changes MUST be merged back into `master` via Pull Request
- Direct pushes to `master` are prohibited
- Feature branches must be short-lived

**CRITICAL RULES:**

- Never stop before pushing
- Never say "ready to push when you are"
- If push fails, resolve and retry
- If credentials are unavailable, stop and report explicitly
