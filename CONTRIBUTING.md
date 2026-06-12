# Contributing

Contributions are welcome via GitHub pull requests. This guide covers the expectations for working on this package.

## Requirements

- PHP 8.3+
- Composer 2

## Getting Started

```bash
git clone git@github.com:sinemacula/laravel-api-toolkit.git
cd laravel-api-toolkit
composer install
```

## Development Workflow

### Branching

Branch from `master` using the appropriate prefix:

| Prefix      | Purpose                          |
|-------------|----------------------------------|
| `feature/`  | New functionality                |
| `bugfix/`   | Bug fixes                        |
| `hotfix/`   | Urgent production fixes          |
| `refactor/` | Refactoring without new features |
| `chore/`    | Tooling, CI, dependencies        |

### Commits

This project uses [Conventional Commits](https://www.conventionalcommits.org/). Prefix your commit messages accordingly:

```text
feat: add cursor-based pagination to the criteria pipeline
fix: preserve response headers through the exception handler
test: cover the relation filter boundary
chore: update qlty configuration
```

### Code Quality

All code must pass static analysis before submission:

```bash
composer check    # Static analysis and lint checks via qlty (PHPStan, PHP-CS-Fixer, CodeSniffer)
composer format   # Format the codebase via qlty
composer smells   # Advisory code smells (duplication, complexity)
```

### Testing

Run the full test suite before submitting:

```bash
composer test            # Run the test suite in parallel using Paratest
composer test:coverage   # With clover coverage report
```

Single test file or method:

```bash
vendor/bin/phpunit tests/Unit/ApiQueryParserTest.php
vendor/bin/phpunit --filter testMethodName tests/Unit/SomeTest.php
```

### Standards

- PHPStan level 8 compliance
- Full type hints on all public method parameters and return types
- PHPDoc on all methods and classes
- New code is expected to ship with tests covering the behavioural surface; the package's mutation-testing gate
  (`composer test:mutation`) is the enforced floor. Behaviour that spans the request lifecycle (middleware, criteria,
  resources) should be covered by integration tests that exercise the package the way a consuming application does.

## Pull Requests

- Keep changes minimal and scoped to a single concern
- Do not change static analysis or formatting configuration without prior discussion
