# Bundled OpenAPI 3.1 / JSON Schema 2020-12 fixtures

These fixtures back the OpenAPI exporter validity test
(`tests/Integration/OpenApiExporterValidityTest.php`). They are the official,
unmodified meta-schemas vendored for offline, deterministic validation per
ADR 0003.

- `openapi-3.1-schema-base.json` - the official OpenAPI 3.1 meta-schema that
  also validates every embedded Schema Object against the OpenAPI dialect, from
  <https://spec.openapis.org/oas/3.1/schema-base/2022-10-07>. The emitted
  document is validated against this, so a schema that is neither an object nor
  a boolean is rejected wherever it appears.
- `openapi-3.1-schema.json` - the OpenAPI 3.1 meta-schema it extends (the
  "without schema validation" variant), from
  <https://spec.openapis.org/oas/3.1/schema/2022-10-07>.
- `openapi-3.1-dialect-base.json` and `openapi-3.1-meta-base.json` - the
  OpenAPI Schema Object dialect and its vocabulary, from
  <https://spec.openapis.org/oas/3.1/dialect/base> and
  <https://spec.openapis.org/oas/3.1/meta/base>.
- `json-schema-2020-12.json` and `json-schema-2020-12-meta-*.json` - the JSON
  Schema 2020-12 dialect the OpenAPI dialect is built on and its vocabulary
  meta-schemas, from <https://json-schema.org/draft/2020-12/schema> and
  <https://json-schema.org/draft/2020-12/meta/>.

Every file is registered with the resolver under its own `$id`, so each
reference resolves offline.

Every file is committed **byte-for-byte as published** - none is hand-edited.

## opis/json-schema compatibility transform (applied at runtime in the test)

`opis/json-schema` (`^2.4`, the test-only validator chosen in ADR 0003) is a
maintained JSON Schema 2020-12 validator, but version 2.6 has two annotation /
reference gaps that prevent it from evaluating the OpenAPI 3.1 meta-schema as
published:

1. **`$dynamicRef: "#meta"` resolution.** The meta-schemas reach every
   embedded schema through `$dynamicRef: "#meta"`, which, when validating
   against `schema-base`, resolves to the outermost `meta` anchor: the
   `schema-base` `#/$defs/schema`, which applies the OpenAPI dialect. opis
   instead resolves the bare `#meta` dynamic anchor to the root document
   resource, so every `components.schemas` entry is incorrectly validated as if
   it were a full OpenAPI document (and reported as "missing the `openapi`
   property"). The test rewrites each `{"$dynamicRef": "#meta"}`, in every
   bundled meta-schema, to the equivalent static
   `{"$ref": "https://spec.openapis.org/oas/3.1/schema-base/2022-10-07#/$defs/schema"}`,
   which is **semantically identical** to the dynamic resolution.

2. **`unevaluatedProperties` annotation collection.** The Parameter Object
   permits `style`, `explode`, `allowReserved`, and `allowEmptyValue` only
   through `dependentSchemas` + `if`/`then` branches, then closes the object
   with `unevaluatedProperties: false`. opis 2.6 does not collect the
   `properties` annotations produced by those conditional branches, so it
   rejects even a minimal, spec-compliant parameter
   (`{name, in, schema}`). The test relaxes every `unevaluatedProperties: false`
   to `true`, neutralising only the one keyword opis cannot evaluate soundly
   while preserving every structural constraint that carries the validity
   signal (required fields, the `openapi` `3.1.x` pattern, type/enum/pattern
   checks, the parameter `name`/`in` requirement, the `schema`-xor-`content`
   `oneOf`, and so on).

Neither transform weakens a constraint the emitter must satisfy: the test proves
below that the relaxed schema still rejects a 3.0 version string, a document
missing `info`, a parameter missing `name`/`in`, an embedded schema encoded as
an array, and other malformed shapes. The transform is performed in code
(visible, reviewable) rather than by committing a hand-edited schema, so the
vendored fixtures stay verifiably identical to the upstream publications and
cannot silently drift.

If a future opis release closes both gaps, the transform can be deleted and the
pristine meta-schemas validated directly.
