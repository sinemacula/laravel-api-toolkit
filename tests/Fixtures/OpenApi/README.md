# Bundled OpenAPI 3.1 / JSON Schema 2020-12 fixtures

These fixtures back the shared OpenAPI validation harness
(`tests/Concerns/ValidatesOpenApiDocuments.php`). They are the official,
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

## opis/json-schema compatibility

The harness uses the spec-compliant validator of `opis/json-schema` (`^2.4`,
the test-only validator chosen in ADR 0003). The default one writes a schema
`default` into the validated data wherever the property is missing, and the
closed Parameter and Header Objects then reject the inserted style defaults as
unevaluated properties. No closed object is relaxed.

Two gaps remain, worked around in the harness at runtime:

1. **`$dynamicRef: "#meta"` resolution.** The meta-schemas reach every
   embedded schema through `$dynamicRef: "#meta"`, which, when validating
   against `schema-base`, resolves to the outermost `meta` anchor: the
   `schema-base` `#/$defs/schema`, which applies the OpenAPI dialect. opis
   instead resolves the bare `#meta` dynamic anchor to the root document
   resource, so every `components.schemas` entry is incorrectly validated as if
   it were a full OpenAPI document. The harness rewrites each
   `{"$dynamicRef": "#meta"}`, in every bundled meta-schema, to the equivalent
   static
   `{"$ref": "https://spec.openapis.org/oas/3.1/schema-base/2022-10-07#/$defs/schema"}`.

2. **`format` assertion on templated server URLs.** JSON Schema 2020-12 treats
   `format` as an annotation by default, but opis asserts it, so a Server Object
   `url` carrying a server variable template (`https://{region}.example.com`),
   which OpenAPI 3.1 explicitly allows, fails the meta-schema's `uri-reference`
   format. The harness's `uri-reference` check substitutes each `{variable}`,
   with a name and then with a digit so a templated port also holds, before
   applying opis's own check, so every other value is still held to the format.

`tests/Integration/OpenApi/OpenApiValidationHarnessTest.php` proves the harness
still rejects malformed documents, including unknown fixed fields such as a
`query` operation or a misspelt `operationID`. The transforms are performed in
code rather than by committing a hand-edited schema, so the vendored fixtures
stay identical to the upstream publications. If a future opis release closes
these gaps, the transforms can be deleted.
