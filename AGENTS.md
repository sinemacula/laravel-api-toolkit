# AGENTS.md

## Project Overview

Laravel API Toolkit is Sine Macula's package for building consistent REST APIs on Laravel. The package namespace is
`SineMacula\ApiToolkit\` and source code lives in `src/`.

Core capabilities include:

- Query parsing from request parameters (`ApiQueryParser`) for fields, filters, ordering, pagination, and aggregates
- API-focused repository helpers built on `sinemacula/laravel-repositories`
- API resource utilities and schema helpers for normalized response payloads
- Exception mapping and API-safe error rendering
- API middleware registration (query parsing, pretty print, throttling, maintenance handling)
- Logging helpers (database and CloudWatch drivers) plus notification event logging
- Export and streaming response support

Current toolchain:

- PHP `^8.3`
- Illuminate components and Orchestra Testbench for integration tests
- `qlty` for static analysis/lint/format checks
- PHPUnit 11 with Paratest

## Repository Boundaries

This repository should remain a Laravel package with reusable API infrastructure.

Keep in scope:

- Package-level helpers and abstractions for API requests, resources, repositories, middleware, logging, and exceptions
- Backward-compatible API behavior unless a task explicitly requires a breaking change
- Small, targeted changes tied to the request

Out of scope unless explicitly requested:

- Application-specific business workflows
- Framework-agnostic abstraction layers
- Large speculative refactors

## Repository Structure

- `src/`: package source under `SineMacula\ApiToolkit\`
- `config/`: package config (`api-toolkit.php`, logging channel extension config)
- `resources/lang/`: translation strings
- `database/migrations/create_logs_table.stub`: log table migration stub
- `tests/Unit` and `tests/Integration`: PHPUnit suites
- `tests/Fixtures`: integration test models/resources/repositories support

## Agent Role and Responsibilities

The agent is expected to:

- Implement requested changes with minimal, safe scope
- Keep behavior and contracts stable unless instructed otherwise
- Run relevant quality checks and tests for touched code paths
- Update docs when behavior or usage changes

The agent must not:

- Change static analysis or formatting configuration without approval
- Introduce unrelated refactors or churn
- Modify generated or cache artifacts unless explicitly requested

## Skills and Execution

### Mandatory Skill Coverage

For any Markdown change, run `$markdown-styling`.

For any PHP change, run a self-review gate before quality tooling and use the PHP skills relevant to the change.

Available skills:

- `$php-test-author`
- `$php-complexity-refactor`
- `$php-naming-normalizer`
- `$php-styling`
- `$php-documenter`
- `$php-attribution`
- `$php-quality-remediator`
- `$markdown-styling`

### PHP Execution Sequence

When PHP code is changed, follow this order:

1. Self-review gate
2. `$php-test-author` (when adding/updating tests or when coverage should be expanded)
3. `$php-complexity-refactor`
4. `$php-naming-normalizer`
5. `$php-styling`
6. `$php-documenter`
7. `$php-attribution`
8. `$php-quality-remediator`
9. Tests

Rules:

- Apply this sequence to `src/`, tests, and PHP snippets in Markdown docs when they are modified
- If `$php-quality-remediator` changes code, rerun the sequence
- Maximum passes per language lane per task: `3`
- If unresolved issues remain after 3 passes, return `blocked` or `approval-required` with the reason

## Canonical Commands

- Install dependencies: `composer install`
- Run checks: `composer check`
- Run checks with auto-fix pass: `composer check -- --all --no-cache --fix`
- Format code: `composer format`
- Run all tests: `composer test`
- Run tests with coverage: `composer test-coverage`
- Run one test file: `vendor/bin/phpunit tests/Unit/ApiQueryParserTest.php`
- Run one test method:
  `vendor/bin/phpunit --filter testParserValidationRejectsInvalidInputShapes tests/Unit/ApiQueryParserTest.php`

## Tests and Quality Expectations

- Prefer targeted tests while iterating; run `composer test` before handoff when executable PHP changes
- Keep unit and integration coverage aligned with behavior changes
- Validate parser behavior, repository criteria behavior, resources, middleware, and exception handling when touched
- If tests cannot be run, report exactly what was not executed and why

## Documentation Responsibilities

When a change introduces or modifies behavior:

- Update `README.md` when user-facing usage or configuration changes
- Update `AGENTS.md` when agent workflow or repository guidance changes
- Keep docs consistent with real namespaces, commands, and package boundaries

## Branching and PR Guidelines

- Default branch is `master`
- Create short-lived branches from `master`
- Use prefixes: `feature/`, `bugfix/`, `hotfix/`, `refactor/`, `chore/`
- Use Conventional Commits
- Reference related GitHub issues when applicable
- Never mention AI tools in commit messages or code comments

## Session Completion

A task is complete when:

1. Requested changes are implemented
2. Relevant checks/tests are run (or explicitly reported as not run)
3. Documentation updates required by the change are included
4. Handoff notes clearly summarize behavior changes, risks, and verification status
