# Documentation Patterns

Use these templates as canonical examples for documentation work in the target repository.

## Class / Type Docblock Template

```php
/**
 * Short type summary.
 *
 * Optional context only when it materially improves understanding.
 *
 * @author      Name <email@example.com>
 * @copyright   2026 Company Name
 */
```

## Facade / Formatter Tag Block Template

```php
/**
 * Constructor.
 *
 * @formatter:off
 *
 * @param  \Vendor\Namespace\Type  $param
 * @param  array<string, mixed>  $options
 *
 * @formatter:on
 */
```

## Property and Constant Templates

```php
/** @var array<string, mixed> Context storage. */
private array $context = [];

/** @var int Maximum retry attempts. */
private const int MAX_RETRIES = 3;
```

## Promoted Property + Mixed Signature Template

```php
public function __construct(

    // Human-readable message.
    string $message,

    /** Optional value object class name. */
    private readonly ?string $valueObjectClass = null,

    // Previous chained exception.
    ?\Throwable $previous = null,

) {}
```

## Configuration Banner Template

```php
/*
|-------------------------------------------------------------------------------
| Section Title
|-------------------------------------------------------------------------------
|
| Explain why this section exists and how it should be interpreted.
|
*/
```

## Project Grounding

- Align templates with the target project's existing docblock patterns.
- Prefer consistency with local style over introducing a new comment dialect.
