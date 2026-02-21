# Test Patterns

Use these patterns when adding or updating tests in any PHP project.

## Base Test Abstractions

- Use existing project test abstractions when available.
- Prefer `#[CoversClass(...)]` on test classes.
- Use `#[DataProvider(...)]` for deterministic scenario matrices.

## Data Provider Shapes

DTO provider shape:

```php
iterable<array{0: array<string, mixed>, 1: array<string, mixed>}>
```

VO `makeProvider` shape:

```php
iterable<string, array{0: mixed, 1: mixed, 2?: bool}>
```

VO `fromProvider` shape:

```php
iterable<string, array{0: mixed, 1: mixed, 2?: mixed, 3?: bool}>
```

## Dataset Naming Convention

- Use explicit scenario labels, not numeric labels.
- Encode behavior intent in the label (for example, "uppercase normalized to lowercase").
- Prefer deterministic input/output pairs with clear expected value.

## Coverage Expectations

- DTOs: round-trip `fromArray()` -> `toArray()` plus null/default edge cases.
- VOs: both `make()` strict validation and `from()` normalization path.
- Include meaningful invalid inputs that assert exception/null behavior as applicable.
