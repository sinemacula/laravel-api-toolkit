# Contributing

Contributions are welcome via GitHub pull requests.

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
| `test/`     | Test-only changes                |
| `docs/`     | Documentation-only changes       |
| `chore/`    | Tooling, CI, dependencies        |

### Commits

This project uses [Conventional Commits](https://www.conventionalcommits.org/). Prefix your commit messages accordingly:

```text
feat: add cursor-based pagination to the criteria pipeline
fix: preserve response headers through the exception handler
test: cover the relation filter boundary
chore: update qlty configuration
```

Pull request titles must follow the same convention. PRs are squash-merged, so the title becomes the commit on
`master` that release-please reads to build the changelog and pick the next version.

### Code Quality

All code must pass static analysis before submission:

```bash
composer check    # Static analysis and lint checks via qlty (PHPStan, PHP-CS-Fixer, CodeSniffer)
composer format   # Format the codebase via qlty
composer smells   # Code smells (duplication, complexity)
```

### Testing

Run the full test suite before submitting:

```bash
composer test                 # Run the test suite in parallel using Paratest
composer test:coverage        # With clover coverage report
composer test:mutation        # Mutation gate scoped to your diff against master
composer test:mutation:full   # Full mutation run, no threshold
```

Single test file or method:

```bash
vendor/bin/phpunit tests/Unit/ApiQueryParserTest.php
vendor/bin/phpunit --filter testMethodName tests/Unit/SomeTest.php
```

### Standards

- PHPStan level 9 compliance
- Full type hints on all public method parameters and return types
- PHPDoc on all methods and classes
- New code ships with tests; the mutation gate (`composer test:mutation`) is the enforced floor. Cover behaviour that
  spans the request lifecycle (middleware, criteria, resources) with integration tests that use the package the way an
  application does.

## Pull Requests

- Title the PR as a Conventional Commit
- Keep changes minimal and scoped to a single concern
- Do not change static analysis or formatting configuration without prior discussion
