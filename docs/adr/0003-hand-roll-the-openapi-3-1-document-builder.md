---
id: 0003
title: Hand-roll the OpenAPI 3.1 document builder
status: Accepted
date: 2026-06-13
context_workflow: .sinemacula/build/workflows/openapi-exporter/
---

# ADR 0003: Hand-roll the OpenAPI 3.1 document builder

**Status:** Accepted
**Date:** 2026-06-13
**Context workflow:** .sinemacula/build/workflows/openapi-exporter/

## Context

The OpenAPI 3.1 Exporter (PRD 14, workflow `openapi-exporter`) must assemble a schema-valid OpenAPI 3.1 document of
reusable components from the toolkit's own metadata. A builder mechanism is required. The PRD and spec explicitly defer
the builder-library choice to the architecture stage and name two candidates: hand-rolled JSON assembly versus a thin
wrapper over `zircote/swagger-php` v6 (the only maintained native-3.1 PHP builder). `goldspecdigital/oooas` is excluded
(OpenAPI 3.0, unmaintained).

This is a public Laravel package: every runtime (`require`) dependency added is inherited by all consuming projects, so
the bar for a new runtime dependency is high.

## Decision

Hand-roll the builder. OpenAPI 3.1 components are, by definition, plain JSON Schema 2020-12 associative arrays, so the
assembler is structured-array assembly serialized via `json_encode(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)`. No
runtime dependency is added. The assembler (`OpenApiAssembler`) is a pure transformer adapter invoked by the
`ExportOpenApiComponents` use case; it sits behind the use case and is locally swappable. Document validity is proven at
test time, not by the builder (see ADR 0004).

## Consequences

### Positive

- Zero new runtime dependency for a public package; the package's public dependency footprint is unchanged.
- The builder is a thin, pure array transformer -- trivial to unit-test and to swap later if requirements change.
- No annotation/attribute-scanning machinery (swagger-php's model) is pulled in for what is plain array assembly.

### Negative

- The package owns the correctness of the 3.1 array shape itself rather than delegating to a library, which is why a
  test-time meta-schema validator (ADR 0004) is mandatory rather than optional.
- Future OpenAPI specification-version changes require manual array updates rather than a library bump.

## Alternatives Considered

### Option A -- zircote/swagger-php v6

The only maintained native-3.1 PHP builder, but its value is annotation/attribute scanning of controllers and DTOs --
irrelevant here because the source of truth is already-compiled toolkit metadata (`CompiledSchema`, `OperatorRegistry`,
`ErrorCode`), not docblocks. It would add runtime weight to a public package for no structural benefit. Rejected.

### Option B -- goldspecdigital/oooas

OpenAPI 3.0 only and unmaintained; cannot emit 3.1 / JSON Schema 2020-12. Rejected.

## References

- Traces to: .sinemacula/build/workflows/openapi-exporter/
- PRD: docs/prd/14-openapi-exporter.md
- Architecture: .sinemacula/build/workflows/openapi-exporter/architecture.md
