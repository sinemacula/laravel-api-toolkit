# Laravel API Toolkit

[![Latest Stable Version](https://img.shields.io/packagist/v/sinemacula/laravel-api-toolkit.svg)](https://packagist.org/packages/sinemacula/laravel-api-toolkit)
[![Build Status](https://github.com/sinemacula/laravel-api-toolkit/actions/workflows/tests.yml/badge.svg?branch=master)](https://github.com/sinemacula/laravel-api-toolkit/actions/workflows/tests.yml)
[![StyleCI](https://github.styleci.io/repos/787362267/shield?style=flat&branch=master)](https://github.styleci.io/repos/787362267)
[![Maintainability](https://qlty.sh/gh/sinemacula/projects/laravel-api-toolkit/maintainability.svg)](https://qlty.sh/gh/sinemacula/projects/laravel-api-toolkit)
[![Test Coverage](https://qlty.sh/gh/sinemacula/projects/laravel-api-toolkit/coverage.svg)](https://qlty.sh/gh/sinemacula/projects/laravel-api-toolkit)
[![Total Downloads](https://img.shields.io/packagist/dt/sinemacula/laravel-api-toolkit.svg)](https://packagist.org/packages/sinemacula/laravel-api-toolkit)

The Laravel API Toolkit is a comprehensive package designed to simplify the development of RESTful APIs in Laravel. It
provides tools to enhance API functionality, improve error handling, and ensure consistent data output, making API
development faster and more reliable.

## Features

- **Exception Handling**: Implements a custom exception handler that captures and formats all exceptions for consistent
  API error responses.
- **Schema-Strict Resources**: Resource output is constrained to declared schema fields, preventing undeclared dynamic
  attribute exposure.
- **Queryable Models**: Allows fine-tuned control over which fields are exposed via your API endpoints, enhancing
  security and customization.
- **Data Repositories**: Abstracts database interactions into repositories to promote a cleaner and more maintainable
  codebase.
- **Data Resources**: Ensures consistent presentation of data across different API endpoints, simplifying client-side
  data integration.
- **Octane-Safe Resolution**: Repository instance caching is automatically bypassed under Laravel Octane to avoid
  cross-request state leakage.

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

## Operational Defaults

- **Strict schema field output**: API resources resolve only declared schema fields (plus fixed toolkit fields).
- **Throttle signatures**: Authenticated requests are throttled per user identity; guest requests fall back to client
  IP.
- **Octane runtime**: Repository resolver instance caching is disabled automatically when running under Octane.
- **Exception request logging**: Payload logging remains enabled by default, but sensitive keys are masked before
  persistence.

Relevant configuration keys in `config/api-toolkit.php`:

- `repositories.cache_resolved_instances`
- `logging.request_context.include_payload`
- `logging.request_context.sensitive_keys`

## Auto Discovery

The toolkit can auto-register both maps that are usually maintained manually:

- `resources.resource_map` (`Model::class => Resource::class`)
- `repositories.repository_map` (`alias => Repository::class`)

This is opt-in and keeps manual entries as the source of truth when both exist.

```php
'auto_discovery' => [
    'enabled' => true,
],

'resources' => [
    'auto_discovery' => [
        'enabled' => true,
    ],
],

'repositories' => [
    'auto_discovery' => [
        'enabled' => true,
    ],
],
```

### Modular Applications

For module-based Laravel applications (for example `modules/<ModuleName>`), configure namespace prefixes so discovery
can build fully-qualified class names correctly:

```php
'auto_discovery' => [
    'enabled' => true,
    'modules' => [
        'enabled' => true,
        'namespace_prefixes' => ['Verifast\\'],
        // Optional explicit roots if your modules are not in base_path('modules')
        // 'paths' => ['/absolute/path/to/modules'],
    ],
],
```

Repository alias resolution order is:

1. `repositories.auto_discovery.alias_overrides[RepositoryClass::class]`
2. Repository class constant (default `REPOSITORY_ALIAS`)
3. Repository static method (default `repositoryAlias()`)
4. Convention-based alias generated from namespace/class path

## Usage

Detailed usage instructions will be provided soon. This section will cover how to integrate the toolkit into your
Laravel application, including setting up queryable models, using data repositories, and applying data transformers.

## Contributing

Contributions are welcome and will be fully credited. We accept contributions via pull requests on GitHub.

## Security

If you discover any security related issues, please email instead of using the issue tracker.

## License

The Laravel API Toolkit repository is open-sourced software licensed under
the [Apache License, Version 2.0](https://www.apache.org/licenses/LICENSE-2.0).
