# Changelog

## [2.0.0](https://github.com/sinemacula/laravel-api-toolkit/compare/v1.16.2...v2.0.0) (2026-09-04)


### ⚠ BREAKING CHANGES

* v2 - attribute controllers, soft-delete visibility, and a complete OpenAPI exporter ([#352](https://github.com/sinemacula/laravel-api-toolkit/issues/352))
* adopt the extracted per-query cache from laravel-repositories ([#332](https://github.com/sinemacula/laravel-api-toolkit/issues/332))
* API Toolkit v2.0 ([#319](https://github.com/sinemacula/laravel-api-toolkit/issues/319))

### Features

* API Toolkit v2.0 ([#319](https://github.com/sinemacula/laravel-api-toolkit/issues/319)) ([8e56ba4](https://github.com/sinemacula/laravel-api-toolkit/commit/8e56ba4893bfe7f2eb90416c1dd0d1fb048c6a3c))
* discover model-resource bindings via the ForModel attribute ([#325](https://github.com/sinemacula/laravel-api-toolkit/issues/325)) ([d62afe0](https://github.com/sinemacula/laravel-api-toolkit/commit/d62afe0aefcc4200577f3ecc2a3290c8c3d2bead))
* gate nested relation traversal on the declared traversable set [BL-37] ([#324](https://github.com/sinemacula/laravel-api-toolkit/issues/324)) ([4fb25da](https://github.com/sinemacula/laravel-api-toolkit/commit/4fb25da5ad87fee57827ba3579b67eb64b1a1394))
* **openapi:** document exceptions from configured extra namespaces ([#353](https://github.com/sinemacula/laravel-api-toolkit/issues/353)) ([22b4b56](https://github.com/sinemacula/laravel-api-toolkit/commit/22b4b560fdb24ce26121f820646633c262e8f147))
* resolve status-derived exception titles through translation keys ([#326](https://github.com/sinemacula/laravel-api-toolkit/issues/326)) ([3ba28fc](https://github.com/sinemacula/laravel-api-toolkit/commit/3ba28fc490e7b72da8b5bc259536208ca63905f3))
* **schema:** type the query surface with capabilities and index-backed sort validation ([#359](https://github.com/sinemacula/laravel-api-toolkit/issues/359)) ([4d42772](https://github.com/sinemacula/laravel-api-toolkit/commit/4d42772d642214a42108a818fcae4786b4998236))
* **search:** add indexed substring search and delete the unsargable $like operator ([#358](https://github.com/sinemacula/laravel-api-toolkit/issues/358)) ([7a287d7](https://github.com/sinemacula/laravel-api-toolkit/commit/7a287d71c901ebbd03d6cf148283df38960b54f5))
* v2 - attribute controllers, soft-delete visibility, and a complete OpenAPI exporter ([#352](https://github.com/sinemacula/laravel-api-toolkit/issues/352)) ([0c9568d](https://github.com/sinemacula/laravel-api-toolkit/commit/0c9568dcd8459a78ea4688b0bc2b0ce0fdcfe44d))


### Bug Fixes

* close fail-open and unbounded-cost defects in the query layer ([#356](https://github.com/sinemacula/laravel-api-toolkit/issues/356)) ([1e43ed6](https://github.com/sinemacula/laravel-api-toolkit/commit/1e43ed66cf0912fcbf7b27589479205b8435dc25))
* Octane flush listener registration, plus coding-standards v1.14.0 conformance ([#343](https://github.com/sinemacula/laravel-api-toolkit/issues/343)) ([f904769](https://github.com/sinemacula/laravel-api-toolkit/commit/f904769a02664c50e5c3a9f4fc88113594fc7e1c))


### Performance Improvements

* memoise assembled field lists across a homogeneous collection ([#333](https://github.com/sinemacula/laravel-api-toolkit/issues/333)) ([367e9f2](https://github.com/sinemacula/laravel-api-toolkit/commit/367e9f226e5bec95b16a91dc22e811a6cb7ddef0))


### Code Refactoring

* adopt the extracted per-query cache from laravel-repositories ([#332](https://github.com/sinemacula/laravel-api-toolkit/issues/332)) ([648d8f4](https://github.com/sinemacula/laravel-api-toolkit/commit/648d8f4aa5000884f467af407567c3ecffd1363d))
