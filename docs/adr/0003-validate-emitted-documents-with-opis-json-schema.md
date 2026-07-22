---
id: 0003
title: Validate emitted documents with opis/json-schema against a bundled 3.1 meta-schema
status: Accepted
date: 2026-06-13
context_workflow: .sinemacula/build/workflows/openapi-exporter/
---

# ADR 0003: Validate emitted documents with opis/json-schema against a bundled 3.1 meta-schema

**Status:** Accepted
**Date:** 2026-06-13
**Context workflow:** .sinemacula/build/workflows/openapi-exporter/

## Context

The OpenAPI 3.1 Exporter hand-rolls the emitted document (ADR 0002), so the package -- not a builder library -- owns the
correctness of the 3.1 array shape. The spec's headline success metric is "100% of emitted documents validate as OpenAPI
3.1", and the release criteria gate on an automated validity assertion. A test-time validator that understands JSON
Schema 2020-12 (the dialect OpenAPI 3.1 is built on) is therefore required. OpenAPI 3.1 documents are validated against
the official OpenAPI 3.1 meta-schema, which itself references the JSON Schema 2020-12 dialect.

This is a test-only concern: the validator never runs in a consuming application's request path, only in the package's
own test suite.

## Decision

Add `opis/json-schema` (`^2.4`) as a `require-dev` dependency. It is maintained and supports JSON Schema 2020-12. The
integration validity test (`tests/Integration/OpenApiExporterValidityTest.php`) loads the official OpenAPI 3.1
meta-schema -- vendored under `tests/Fixtures/openapi/openapi-3.1-schema.json` along with any referenced 2020-12 dialect
documents -- registers it with the `opis` resolver, and asserts the emitted document validates. The validator sits behind
the integration test, decoupled from the emitter, so it can be swapped for another maintained 2020-12 validator without
touching production code.

## Consequences

### Positive

- The spec's 100%-validity success metric becomes a deterministic, automated assertion.
- `require-dev` only: no runtime dependency, no change to the public package footprint.
- The validator is isolated behind one integration test; swapping it is a one-file change.

### Negative

- The OpenAPI 3.1 meta-schema (and its referenced JSON Schema 2020-12 dialect documents) must be vendored as fixtures and
  kept current with the targeted spec revision.
- One more dev dependency to maintain alongside the existing PHPStan/PHPUnit/Infection toolchain.

### Risks

- If `opis/json-schema` cannot resolve the meta-schema's external `$ref`s to the 2020-12 dialect, the dialect documents
  must be bundled and registered explicitly with the resolver; if `opis` proves unsuitable, a different maintained
  2020-12 validator is substituted (mitigated by the validator being test-only and behind a single test).

## Alternatives Considered

### Option A -- justinrainbow/json-schema

The long-standing PHP JSON Schema validator, but it targets older drafts (draft-03/04 era) and does not implement JSON
Schema 2020-12, so it cannot validate OpenAPI 3.1. Rejected.

### Option B -- Delegate validity to the builder library

Rejected upstream by ADR 0002 (hand-rolled builder). With no builder library there is nothing to delegate to; an
independent meta-schema validator is the only way to prove correctness of a hand-assembled document.

### Option C -- No automated validation (manual review)

Rejected: the spec gates the release on an automated validity assertion, and "wrong documentation is worse than none" is
the feature's core premise -- a silent shape regression would defeat the purpose.

## References

- Traces to: .sinemacula/build/workflows/openapi-exporter/
- PRD: docs/prd/14-openapi-exporter.md
- Architecture: .sinemacula/build/workflows/openapi-exporter/architecture.md
- Related: ADR 0002 (hand-roll the OpenAPI 3.1 document builder)
