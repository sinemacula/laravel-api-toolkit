# Attribute Examples

Use this reference for concrete attribute decisions in the target repository.

## Existing Repository Usage

- Test classes commonly use `#[CoversClass(...)]`.
- Shared test abstractions commonly use `#[DataProvider(...)]`.

## `#[\Override]`

Apply when a method explicitly overrides a known parent/interface method.

```php
#[\Override]
public function toArray(): array
{
    // ...
}
```

Do not apply when the override target cannot be resolved.

## `#[\SensitiveParameter]`

Apply when a parameter clearly contains secrets and could leak in stack traces.

```php
public function authenticate(#[\SensitiveParameter] string $api_key, #[\SensitiveParameter] string $client_secret): void
{
    // ...
}
```

Do not apply to ambiguous names like `$value`, `$input`, or `$token_or_null`.

## `#[\Deprecated]`

Apply only when deprecation is already part of the code contract.

```php
#[\Deprecated]
public function legacyMethod(): void
{
    // ...
}
```

Do not introduce deprecation intent through attributes alone.

## `#[\AllowDynamicProperties]` (Approval Required)

Only use when backward compatibility requires dynamic properties and a refactor is out of scope.

Default stance: avoid.
