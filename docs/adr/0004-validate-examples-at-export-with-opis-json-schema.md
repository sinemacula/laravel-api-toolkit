---
id: 0004
title: Validate examples at export with opis/json-schema
status: Accepted
date: 2026-11-19
---

# ADR 0004: Validate examples at export with opis/json-schema

**Status:** Accepted
**Date:** 2026-11-19
**Supersedes:** ADR 0003

## Context

The exporter generates an example for every JSON request body and success body it documents, from the same schemas it
emits. A generated value is only useful if it is one the schema accepts, and an author's example, an error example or a
schema-level example is only useful if it agrees with the schema beside it. Checking that needs a JSON Schema 2020-12
validator at export time, inside the consuming application, not only in the package's own test suite.

ADR 0003 added `opis/json-schema` as a `require-dev` dependency on the grounds that validation was a test-only concern.
That no longer holds.

## Decision

Move `opis/json-schema` to `require` at `^2.6`, the first release that requires a string library free of PHP 8.1
deprecations. The export validates every example against the schema it sits beside, and the generator checks each
candidate value the same way, so it never emits a value the schema rejects. A mismatch fails the export, naming the
example and the JSON pointer of the failure.

Validation runs offline against the assembled document only: the resolver registers that one document and has no
loaders, so no `$ref` is fetched. The validator runs in its specification-compliant mode: no `default` fills a missing
property, and none of the library's extension keywords (filters, mappers, templates, globals, slots, pragmas, `$data`)
takes effect, so document content cannot change how a value validates. Only one class touches the library.

Validating the emitted document against the OpenAPI 3.1 meta-schema stays a test concern, as ADR 0003 describes.

## Consequences

### Positive

- Every example in an exported document is checked against its schema; a wrong one fails the export instead of
  shipping.
- Generated examples respect every constraint the schema expresses, presence rules and unions included.

### Negative

- Every consuming application installs `opis/json-schema` and its `opis/string` and `opis/uri` dependencies, which need
  `ext-iconv`, although the library is loaded only during export.

### Risks

- A validator defect could reject a valid example. The validator sits behind a single class, so it can be replaced
  without touching the generator or the assembler.

## Alternatives Considered

### Option A - Keep validation test-only

Rejected: a consumer's own resources, rules and author values would never be checked, and the document they ship could
carry examples their schemas reject.

### Option B - Generate without validating

Rejected: the schemas carry cross-field rules (`anyOf` of `required` sets, `dependentRequired`, `if`/`then`) and unions
that a value can only be proven against by a validator.

## References

- Supersedes: ADR 0003 (validate emitted documents with opis/json-schema against a bundled 3.1 meta-schema)
- Related: ADR 0002 (hand-roll the OpenAPI 3.1 document builder)
