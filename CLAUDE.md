# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Laravel API Toolkit is a comprehensive PHP package for building RESTful APIs in Laravel. It provides structured exception handling, schema-driven API resources with dynamic field filtering, query string parsing, and repository patterns for database abstraction.

## Commands

### Testing
```bash
# Run all tests
vendor/bin/phpunit

# Run tests with coverage
vendor/bin/phpunit --coverage-clover coverage.xml
```

### Code Quality
```bash
# PHP CS Fixer (using qlty config)
vendor/bin/php-cs-fixer fix --config=.qlty/configs/.php-cs-fixer.php

# PHPStan static analysis (level 5)
vendor/bin/phpstan analyse --configuration=.qlty/configs/phpstan.neon
```

## Architecture

### API Resources (`src/Http/Resources/`)
Schema-driven resources extend `ApiResource` with a required `schema()` method defining fields, relations, computed values, and guards. Each resource must define a `RESOURCE_TYPE` constant. The system handles:
- Dynamic field selection via query params (`?fields[user]=id,name`)
- Eager loading based on requested fields
- Relation count loading (`?counts[user]=posts`)
- Field guards for conditional visibility
- Field transformers for value modification

### Repositories (`src/Repositories/`)
`ApiRepository` extends from `sinemacula/laravel-repositories`. Key features:
- `withApiCriteria()` applies query parsing for filtering/ordering
- `setAttributes()` handles type casting and relation syncing automatically
- `paginate()` supports both offset and cursor pagination

### Query Parser (`src/ApiQueryParser.php`)
Parses query string parameters into structured data:
- `fields[resource]` - field selection per resource type
- `counts[resource]` - relation counts
- `filters` - JSON-encoded filter conditions
- `order` - sorting (`field:direction`)
- `limit`, `page`, `cursor` - pagination

Access via facade: `ApiQuery::getFields('user')`

### Exception Handling (`src/Exceptions/`)
`ApiException` base class requires `CODE` (ErrorCodeInterface enum) and `HTTP_STATUS` (HttpStatus enum) constants. Exception messages use translation keys: `api-toolkit::exceptions.{code}.title/detail`

### Controllers (`src/Http/Routing/`)
- `Controller` - base with `respondWithItem()`, `respondWithCollection()`, `respondWithEventStream()`
- `AuthorizedController` - adds authorization hooks

### Middleware
- `ParseApiQuery` - parses query parameters on each request
- `ThrottleRequests` / `ThrottleRequestsWithRedis` - rate limiting
- `JsonPrettyPrint` - formats JSON responses
- `PreventRequestsDuringMaintenance` - maintenance mode handling

## Code Style

Uses PSR-12 with extensive customizations in `.qlty/configs/.php-cs-fixer.php`:
- Aligned binary operators
- PHPDoc type annotations converted to native types where possible
- PHPUnit attributes (not annotations)
- Ordered class elements: traits, constants, properties, constructor, methods
- No trailing commas in multiline structures

## Key Patterns

### Resource Schema Definition
```php
public static function schema(): array
{
    return [
        'id'    => Field::make(),
        'name'  => Field::make()->default(),
        'email' => Field::make()->guard(fn($r) => $r->resource->is(auth()->user())),
        'posts' => Relation::make(PostResource::class)->constraint(fn($q) => $q->published()),
    ];
}
```

### Repository Usage
```php
$repository->withApiCriteria()->paginate();
$repository->setAttributes($model, $request->validated());
```