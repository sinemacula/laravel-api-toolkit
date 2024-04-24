# Laravel API Toolkit

[![Latest Stable Version](http://poser.pugx.org/sinemacula/laravel-api-toolkit/v)](https://packagist.org/packages/sinemacula/laravel-api-toolkit)
[![Build Status](https://github.com/sinemacula/laravel-api-toolkit/actions/workflows/tests.yml/badge.svg?branch=master)](https://github.com/sinemacula/laravel-api-toolkit/actions/workflows/tests.yml)
[![StyleCI](https://github.styleci.io/repos/787362267/shield?style=flat&branch=master)](https://github.styleci.io/repos/787362267)
[![Maintainability](https://api.codeclimate.com/v1/badges/f3221f27741afef43551/maintainability)](https://codeclimate.com/github/sinemacula/laravel-api-toolkit/maintainability)
[![Test Coverage](https://api.codeclimate.com/v1/badges/f3221f27741afef43551/test_coverage)](https://codeclimate.com/github/sinemacula/laravel-api-toolkit/test_coverage)
[![License](http://poser.pugx.org/sinemacula/laravel-api-toolkit/license)](https://packagist.org/packages/sinemacula/laravel-api-toolkit)
[![Total Downloads](http://poser.pugx.org/sinemacula/laravel-api-toolkit/downloads)](https://packagist.org/packages/sinemacula/laravel-api-toolkit)

The Laravel API Toolkit is a comprehensive package designed to simplify the development of RESTful APIs in Laravel. It
provides tools to enhance API functionality, improve error handling, and ensure consistent data output, making API
development faster and more reliable.

## Features

- **Exception Handling**: Implements a custom exception handler that captures and formats all exceptions for consistent
  API error responses.
- **Queryable Models**: Allows fine-tuned control over which fields are exposed via your API endpoints, enhancing
  security and customization.
- **Data Repositories**: Abstracts database interactions into repositories to promote a cleaner and more maintainable
  codebase.
- **Data Resources**: Ensures consistent presentation of data across different API endpoints, simplifying client-side
  data integration.

## Installation

To install the Laravel API Toolkit, run the following command in your project directory:

```bash
composer require sinemacula/laravel-api-toolkit
```

## Configuration

After installation, publish the package configuration to customize it according to your needs:

```bash
php artisan vendor:publish --provider="SineMacula\ApiServiceProvider"
```

This command publishes the package configuration file to your application's config directory, allowing you to modify
aspects such as exception handling behaviors, data repository settings, and more.

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

## TODO

- Make a decision on Exceptions
- Exception handler must handle validation exceptions...
