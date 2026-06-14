# Laravel API Toolkit

[![Latest Stable Version](https://img.shields.io/packagist/v/sinemacula/laravel-api-toolkit.svg)](https://packagist.org/packages/sinemacula/laravel-api-toolkit)
[![Build Status](https://github.com/sinemacula/laravel-api-toolkit/actions/workflows/tests.yml/badge.svg?branch=master)](https://github.com/sinemacula/laravel-api-toolkit/actions/workflows/tests.yml)
[![Quality Gates](https://github.com/sinemacula/laravel-api-toolkit/actions/workflows/quality-gates.yml/badge.svg?branch=master)](https://github.com/sinemacula/laravel-api-toolkit/actions/workflows/quality-gates.yml)
[![Maintainability](https://qlty.sh/gh/sinemacula/projects/laravel-api-toolkit/maintainability.svg)](https://qlty.sh/gh/sinemacula/projects/laravel-api-toolkit)
[![Code Coverage](https://qlty.sh/gh/sinemacula/projects/laravel-api-toolkit/coverage.svg)](https://qlty.sh/gh/sinemacula/projects/laravel-api-toolkit)
[![Total Downloads](https://img.shields.io/packagist/dt/sinemacula/laravel-api-toolkit.svg)](https://packagist.org/packages/sinemacula/laravel-api-toolkit)

The Laravel API Toolkit is a comprehensive package designed to simplify the development of RESTful APIs in Laravel. It
provides tools to enhance API functionality, improve error handling, and ensure consistent data output, making API
development faster and more reliable.

## Features

- **Exception Handling**: Implements a custom exception handler that captures and formats all exceptions for consistent
  API error responses, preserving the intended HTTP status codes.
- **Queryable Models**: Allows fine-tuned control over which fields, filters, relations, and orderings are exposed via
  your API endpoints, enhancing security and customization.
- **Data Repositories**: Abstracts database interactions into repositories to promote a cleaner and more maintainable
  codebase, with safe-by-default deferred writes (failed flushes retain records rather than dropping them) and per-query
  caching (each query is cached against its own fingerprint, so a cache hit performs zero database queries and a filtered
  read never returns the full table).
- **Data Resources**: Schema-driven resources ensure consistent presentation of data across different API endpoints,
  simplifying client-side data integration.
- **Services**: A composable service layer with immutable configuration, cross-cutting concerns (transactions,
  locking), and self-describing results.

## Installation

To install the Laravel API Toolkit, run the following command in your project directory:

```bash
composer require sinemacula/laravel-api-toolkit
```

## Configuration

After installation, publish the package configuration to customize it according to your needs:

```bash
php artisan vendor:publish --provider="SineMacula\ApiToolkit\ApiServiceProvider"
```

This command publishes the package configuration file to your application's config directory, allowing you to modify
aspects such as exception handling behaviors, data repository settings, and more.

## Usage

Detailed usage instructions will be provided soon. This section will cover how to integrate the toolkit into your
Laravel application, including setting up queryable models, using data repositories, and applying data transformers.

## Requirements

- PHP ^8.3
- Laravel 12+

## Testing

```bash
composer test
composer test:coverage
composer check
composer format
composer smells
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a list of notable changes, and [UPGRADE.md](UPGRADE.md) for version upgrade
guides.

## Contributing

Contributions are welcome. Please read [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines on branching, commits, code
quality, and pull requests.

## Security

If you discover a security vulnerability, please report it responsibly. See [SECURITY.md](SECURITY.md) for the
disclosure policy and contact details.

## License

Licensed under the [Apache License, Version 2.0](https://www.apache.org/licenses/LICENSE-2.0).
