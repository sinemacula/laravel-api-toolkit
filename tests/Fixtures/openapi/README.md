# Bundled OpenAPI 3.1 / JSON Schema 2020-12 fixtures

These fixtures back the OpenAPI exporter validity test
(`tests/Integration/OpenApiExporterValidityTest.php`). They are the official,
unmodified meta-schemas vendored for offline, deterministic validation per
ADR 0004.

| File | Source | Purpose |
|------|--------|---------|
| `openapi-3.1-schema.json` | <https://spec.openapis.org/oas/3.1/schema/2022-10-07> | The official OpenAPI 3.1 meta-schema (the "without schema validation" variant). The emitted document is validated against this. |
| `json-schema-2020-12.json` | <https://json-schema.org/draft/2020-12/schema> | The JSON Schema 2020-12 dialect the OpenAPI 3.1 meta-schema is built on, registered with the resolver so the dialect `$id` resolves offline. |

Both files are committed **byte-for-byte as published** — they are not hand-edited.

## opis/json-schema compatibility transform (applied at runtime in the test)

`opis/json-schema` (`^2.4`, the test-only validator chosen in ADR 0004) is a
maintained JSON Schema 2020-12 validator, but version 2.6 has two annotation /
reference gaps that prevent it from evaluating the OpenAPI 3.1 meta-schema as
published:

1. **`$dynamicRef: "#meta"` resolution.** The meta-schema's Schema Object uses
   `$dynamicRef: "#meta"`, which should resolve to the permissive
   `#/$defs/schema` (`{"type": ["object", "boolean"]}` — the "without schema
   validation" variant deliberately does not recurse into embedded schemas).
   opis instead resolves the bare `#meta` dynamic anchor to the root document
   resource, so every `components.schemas` entry is incorrectly validated as if
   it were a full OpenAPI document (and reported as "missing the `openapi`
   property"). The test rewrites each `{"$dynamicRef": "#meta"}` to the
   equivalent static `{"$ref": "#/$defs/schema"}`. Because `#/$defs/schema` is
   fully permissive, this is **semantically identical** for the published
   "without schema validation" variant.

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

Neither transform weakens a constraint the emitter must satisfy: the test
proves below that the relaxed schema still rejects a 3.0 version string, a
document missing `info`, a parameter missing `name`/`in`, and other malformed
shapes. The transform is performed in code (visible, reviewable) rather than by
committing a hand-edited schema, so the vendored fixtures stay verifiably
identical to the upstream publications and cannot silently drift.

If a future opis release closes both gaps, the transform can be deleted and the
pristine meta-schema validated directly.
