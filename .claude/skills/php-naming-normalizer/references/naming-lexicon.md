# Naming Lexicon

Use this lexicon to keep naming aligned with established project language.

## Canonical Concepts

- `DTO`: transport-friendly data structures with `fromArray()` / `toArray()`.
- `ValueObject`: invariant-enforcing types with `from()` / `make()` / `value()`.
- `Normalizer`: input normalization step before validation.
- `ValidationException`: domain-specific invariant failure signal.

## Canonical Method Verbs

- `from*`: build from raw/untrusted input.
- `make`: build from already-normalized input and validate.
- `toArray`: serialize a DTO contract shape.
- `value`: return normalized internal value.

## Existing Naming Patterns to Preserve

- DTO fields: short, concrete field names that reflect serialized contract keys.
- VO naming: singular, concrete nouns (for example, `EmailAddress`, `PhoneNumber`, `CountryCode`).
- Test naming: behavior-first method names and readable dataset labels.

## Synonym Discipline

- Use `DTO`, not interchangeable terms like `payload object` within code symbol names.
- Use `ValueObject`, not mixed alternatives (`primitive wrapper`, `typed value`) in symbols.
- Use a single canonical term for each concept across class, method, and variable names.
