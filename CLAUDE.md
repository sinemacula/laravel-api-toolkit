# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Laravel API Toolkit (`sinemacula/laravel-api-toolkit`) is a Laravel package for building consistent REST APIs. Namespace: `SineMacula\ApiToolkit\`, source in `src/`. Requires PHP ^8.3.

Key sibling packages: `sinemacula/laravel-repositories` (base repository pattern), `sinemacula/laravel-resource-exporter` (export functionality).

## Commands

```bash
composer install              # Install dependencies
composer check                # Run qlty static analysis (PHPStan level 8, PHP-CS-Fixer, CodeSniffer, etc.)
composer check -- --all --no-cache --fix  # Checks with auto-fix
composer format               # Format code via qlty
composer test                 # Run tests (Paratest, parallel execution)
composer test-coverage        # Run tests with clover coverage report

# Single test file
vendor/bin/phpunit tests/Unit/ApiQueryParserTest.php

# Single test method
vendor/bin/phpunit --filter testMethodName tests/Unit/SomeTest.php
```

## Architecture

### Request Lifecycle

1. **ParseApiQuery middleware** parses request query parameters into an `ApiQueryParser` singleton (bound as `api.query` in the container). Supports fields, filters, ordering, pagination, and aggregates.
2. **Controller** (`Http\Routing\Controller`) uses `respondWithItem`, `respondWithCollection`, `respondWithEventStream`, or `respondWithData` to build responses.
3. **ApiResource** resolves a declarative `schema()` into JSON, filtering fields based on the parsed query. Automatically computes eager-load maps via `eagerLoadMapFor()` and count maps via `eagerLoadCountsFor()`.
4. **ApiRepository** applies `ApiCriteria` (built from the parsed query) to Eloquent queries for filtering, ordering, and pagination.

### Resource Schema System

Resources extend `ApiResource` and define a static `schema()` returning an array of `Field`, `Relation`, and `Count` definitions (in `Http\Resources\Schema\`). Each definition supports guards, transformers, constraints, computed values, and accessors. Resources must define a `RESOURCE_TYPE` constant.

### Exception Handling

`ApiExceptionHandler` maps Laravel exceptions to typed `ApiException` subclasses (BadRequest, Forbidden, NotFound, etc.) and renders them as consistent JSON with translation key support.

### Service Layer

`Services\Service` provides a base class with optional database transactions, cache-based locking (`Lockable` trait), and success/failure lifecycle callbacks.

### Configuration

All behavior is driven by `config/api-toolkit.php`: resource maps, repository maps, cast maps, query parser defaults, export formats, notification logging, maintenance mode exceptions, and CloudWatch logging.

### Logging

Dual-channel logging: `DatabaseLogger`/`DatabaseHandler` for database storage, `CloudWatchLogger` for AWS CloudWatch. Notification events are automatically logged via `NotificationListener`.

## Repository Structure

- `src/` — package source (`SineMacula\ApiToolkit\`)
- `config/` — package config (`api-toolkit.php`, `logging.php`)
- `resources/lang/` — translation strings
- `database/` — migration stubs
- `tests/Unit/` and `tests/Integration/` — PHPUnit suites
- `tests/Fixtures/` — integration test support (models, resources, repositories)

## Quality Skills (`.claude/agents/`)

When PHP code is changed, run the quality skill agents in this order via the Task tool (subagent_type matching the agent name):

1. `php-test-author` — when adding/updating tests or closing coverage gaps
2. `php-complexity-refactor` — resolve tool-reported complexity findings
3. `php-naming-normalizer` — normalize naming for clarity and domain alignment
4. `php-styling` — enforce mechanical style and layout rules
5. `php-documenter` — ensure documentation is complete, concise, and correctly formatted
6. `php-attribution` — add PHP-native attributes (`#[\Override]`, `#[\SensitiveParameter]`, etc.)
7. `php-quality-remediator` — run `composer check -- --all --no-cache --fix` and remediate failures

For Markdown changes, run `markdown-styling`.

If `php-quality-remediator` changes code, rerun the chain (max 3 passes). Each agent includes its full reference material inline.

## Conventions

- Default branch: `master`. Branch prefixes: `feature/`, `bugfix/`, `hotfix/`, `refactor/`, `chore/`
- Use Conventional Commits
- Never mention AI tools in commit messages or code comments
- PHPStan level 8 (strict). All code must pass `composer check` before handoff
- Run `composer test` before handoff when executable PHP changes are made
- Keep changes minimal and scoped to the request; avoid unrelated refactors
- Do not change static analysis or formatting configuration without approval
