# Laravel API Toolkit — v2 Playbook

Central working plan for completing version 2. This document tracks the remaining work, the architectural
direction, and how the toolkit fits into the wider Sine Macula package ecosystem. It is a living document:
items are checked off as the corresponding PRs land on `2.x`.

## Status

The v2 rewrite is substantially complete. Nineteen archived PRDs (`docs/prd/.archived/`) cover the structural
work already merged: decomposition of the `ApiResource`, `ApiCriteria`, and `ApiRepository` god classes into
single-responsibility collaborators, the extensible operator registry, schema introspection as an injectable
service, boot-time schema validation, immutable service configuration with a composable concern pipeline,
deferred repository writes, cache lifecycle management, SSE/WHATWG conformance, and adoption of
`sinemacula/http-primitives-php`.

### Branch model

- `master` — v1 line (maintenance only)
- `2.x` — v2 integration branch; all work lands here via focused PRs
- `1.x` — frozen v1 history

## Ecosystem map

The toolkit is one piece of a deliberately modular ecosystem. Separation of concerns, isolation of
responsibility, and portability govern every extraction decision.

### Foundations the toolkit builds on

| Package                                | Role                                                  | Toolkit dependency |
|----------------------------------------|-------------------------------------------------------|--------------------|
| `sinemacula/laravel-repositories`      | Repository pattern, criteria pipeline (v2)            | `^2.0` (require)   |
| `sinemacula/laravel-resource-exporter` | CSV/XML export drivers for resources (v2)             | `^2.0` (require)   |
| `sinemacula/http-primitives-php`       | Typed HTTP enums (status, method, media type, header) | `^2.0` (require)   |
| `sinemacula/coding-standards`          | Centralised static analysis + style configuration     | dev                |

### Adjacent pieces (no coupling, by design)

| Package                       | Role                                                    | Relationship                      |
|-------------------------------|---------------------------------------------------------|-----------------------------------|
| `laravel-modules`             | Convention-driven modular architecture (zero manifests) | Future: module-aware discovery    |
| `laravel-modular-template`    | Opinionated API-first project template                  | Consumes the toolkit              |
| `laravel-authentication`      | Contextual stateless auth (Identity/Principal/Device)   | Independent; see exception bridge |
| `laravel-authorization`       | RBAC + IAM-style policy evaluation                      | Independent; see exception bridge |
| `laravel-mfa` / `laravel-sso` | Driver-based MFA / SSO                                  | Independent                       |
| `laravel-audit-log`           | Event-driven auth activity logging (unreleased)         | Independent                       |
| `laravel-iam`                 | Facade bundle composing the auth suite (unreleased)     | Independent                       |
| `data-normalizer-php`         | Deterministic data normalization (framework-agnostic)   | None                              |

The IAM suite throws Symfony `HttpException` subclasses and deliberately does not depend on the toolkit.
PRD 03's generic `HttpExceptionInterface` catch-all means those exceptions will render in the toolkit's JSON
error format automatically — no bridge package required. This should be verified by an integration test when
PRD 03 lands.

## Remaining work

### 1. Active PRDs (in execution order)

| #   | PRD                                  | Size   | Status                |
|-----|--------------------------------------|--------|-----------------------|
| 03  | Exception handler coverage           | Medium | Pending               |
| 05  | Notification listener configuration  | Small  | Pending               |
| 06  | Explicit request capabilities        | Large  | Pending               |
| 07  | Service result value object          | Large  | Pending               |
| 04  | Configurable middleware registration | —      | Done (#227); archived |

Order rationale: 05 and 03 are small/medium and self-contained; 06 removes the request macros (and with them
the `Closure::bind` scaffolding and the facade-macro PHPStan blind spots); 07 changes the `Service` public
contract and benefits from landing last, when the surface around it is stable.

### 2. Architecture items (surfaced during the standards sweep)

- [ ] **Decompose `ApiQueryParser`** (28 methods; parse, validate, and access concerns interleaved).
  Natural seams: parser, validation-rule builder, typed parameter bag. The typed bag also removes the
  remaining `@var`-narrowing in getters. Consider alongside PRD 06.
- [ ] **Decompose `ApiServiceProvider`** (26 methods). Extract registrar collaborators (macros, middleware,
  logging, lifecycle) following the pattern Laravel's own providers use. PRD 06 removes the macro block,
  so sequence this after it.
- [ ] **`ThrottleRequestsTrait` signature intent**: the `?? $request->ip()` guest fallback was dead code due
  to operator precedence (string concatenation binds tighter than `??`) and was removed verbatim in the
  sweep. Decide whether guest requests should be keyed by IP — a behavioural change to throttle keys.
- [ ] **Dedicated exceptions** for internal `\RuntimeException`/`\LogicException` throw sites
  (`Lockable`, `Field::set()`, `ThrottleRequestsTrait`, `AttributeSetter`) — radarlint S112. Small,
  cohesive exception types under `Exceptions\`.
- [ ] **Listener/driver signatures**: `OctaneFlushListener::handle($event)` and `DatabaseLogger($config)`
  carry unused parameters required by framework contracts — confirm and suppress or document.
- [ ] **`JsonPrettyPrint` dead code**: the explicit `setData()` call after `setEncodingOptions()` is redundant —
  `JsonResponse::setEncodingOptions()` already re-encodes via `setData($this->getData())`. Surfaced by mutation
  testing; remove on the next pass through the middleware.

### 3. Extraction candidates

| Candidate                                                                                  | Recommendation             | Rationale                                                                   |
|--------------------------------------------------------------------------------------------|----------------------------|-----------------------------------------------------------------------------|
| SSE transport (`Sse\EventStream`, `Emitter`)                                               | **Extract** to own package | Zero toolkit coupling; WHATWG-conformant; reusable beyond REST APIs         |
| Logging drivers (`CloudWatchLogger`, `DatabaseLogger`, `LogMessage`, notification logging) | **Extract** to own package | Orthogonal to API mechanics; CloudWatch deps already optional               |
| `WritePool` / deferred writes                                                              | Keep in v2; revisit        | Couples to repository lifecycle; candidate for `laravel-repositories` later |
| `SchemaIntrospector`                                                                       | Keep                       | Core to resources, criteria, and validation                                 |

Extractions should follow the established pattern (`http-primitives-php`, PR #223): stand the package up,
integrate behind the same public API, deprecate in-toolkit usage, remove in v2.0 final. SSE first — a
`worktree-blueprint-sse-extraction` branch already sketches this.

### 4. Module-awareness (post-2.0 direction)

`laravel-modules` discovers module paths by convention. The toolkit currently requires a global
`resource_map` config. A future minor (2.1+) could discover `Http/Resources/` per module and register
resource mappings automatically, keeping the global map as an override. Not a 2.0 blocker — design when the
template proves the need.

### 5. Housekeeping

- [ ] Resolve 24 osv-scanner/trivy advisories on `composer.lock` (dependency updates; coordinate with
  Dependabot PR #235 equivalent on `2.x`)
- [ ] `UPGRADE.md` completeness pass once PRDs 06/07 land (macro deprecations, `ServiceResult` migration)
- [ ] README refresh for v2 surface
- [ ] Tag `v2.0.0-beta` once PRDs land; release after downstream projects validate

## Testing strategy

Line coverage is necessary but not sufficient. The quality bar for this package is **behavioural coverage of every
way a consuming application uses it**:

- **Integration-first for consumer paths.** Every public surface should have integration tests that exercise the
  package the way an application does — real HTTP requests through the middleware stack, real database queries
  through repositories and criteria, real serialization through resources (`tests/Integration/RequestLifecycleTest`
  is the anchor for this pattern). New consumer-facing behaviour ships with an integration test, not only unit tests
  of the collaborators.
- **Mutation testing as the assertion-quality gate.** `composer test:mutation` enforces an MSI floor in CI
  (Quality Gates workflow). The floor starts at the measured baseline (75%) and is ratcheted upward as escaped
  mutants are killed — raise the threshold in `composer.json` whenever the full suite (`composer test:mutation:full`)
  shows headroom. Escaped mutants are a to-do list for missing assertions.
- **Multi-database matrix.** Integration tests run against SQLite locally and MySQL + PostgreSQL in CI; anything
  touching query generation must hold across all three.
- **End-to-end feature coverage**: real-route integration tests now cover the request lifecycle
  (`RequestLifecycleTest`), exception rendering (`ExceptionRenderingTest`), SSE streaming (`SseStreamingTest`),
  export content negotiation (`ExportNegotiationTest`), and the deferred-write flush across a request boundary
  (`DeferredWriteRequestBoundaryTest`). New consumer-facing behaviour should extend this suite.

## Quality gates

Every PR to `2.x` must pass:

1. `composer check -- --all` — zero phpstan/formatter/sniffer/markdown findings (baseline green since the
   compliance sweep; radarlint comment-mode advisories tracked above, not gate failures)
2. `composer test` — full Paratest suite green
3. Conventional commit messages; one reviewable concern per PR

## PR ledger

| PR   | Branch                                           | Scope                                            | Status |
|------|--------------------------------------------------|--------------------------------------------------|--------|
| #232 | `feature/schema-introspector-relation-detection` | PRD 02 — return-type relation detection          | Open   |
| #236 | `chore/centralise-coding-standards-2x`           | Centralised coding standards on `2.x`            | Open   |
| #237 | `chore/standards-compliance-sweep`               | Compliance sweep — 387 findings to zero          | Open   |
| #238 | `chore/v2-playbook`                              | This playbook + PRD 04 archival                  | Open   |
| #239 | `feature/notification-listener-config-2x`        | PRD 05 — notification log levels and exclusion   | Open   |
| #240 | `feature/exception-handler-coverage-2x`          | PRD 03 — handler coverage + status preservation  | Open   |
| #241 | `feature/explicit-request-capabilities-2x`       | PRD 06 — typed RequestCapabilities API           | Open   |
| #242 | `feature/service-result-value-object-2x`         | PRD 07 — ServiceResult value object              | Open   |

PRs #236 through #242 form a linear stack; merge in order — each PR retargets automatically as its base merges.
