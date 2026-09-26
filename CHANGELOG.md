# Changelog

## [2.0.0](https://github.com/sinemacula/laravel-api-toolkit/compare/v1.16.2...v2.0.0) (2026-09-26)


### ⚠ BREAKING CHANGES

* remove the resource cache flush that cleared the whole store ([#388](https://github.com/sinemacula/laravel-api-toolkit/issues/388))
* follow the repository through the copy a composition returns ([#384](https://github.com/sinemacula/laravel-api-toolkit/issues/384))
* refuse a sort the engine will not plan against ([#379](https://github.com/sinemacula/laravel-api-toolkit/issues/379))
* refuse an index the engine will not plan against ([#376](https://github.com/sinemacula/laravel-api-toolkit/issues/376))
* measure the offset cap in rows rather than pages ([#369](https://github.com/sinemacula/laravel-api-toolkit/issues/369))
* ship the index-proof waiver empty ([#368](https://github.com/sinemacula/laravel-api-toolkit/issues/368))
* v2 - attribute controllers, soft-delete visibility, and a complete OpenAPI exporter ([#352](https://github.com/sinemacula/laravel-api-toolkit/issues/352))
* adopt the extracted per-query cache from laravel-repositories ([#332](https://github.com/sinemacula/laravel-api-toolkit/issues/332))
* API Toolkit v2.0 ([#319](https://github.com/sinemacula/laravel-api-toolkit/issues/319))

### Features

* API Toolkit v2.0 ([#319](https://github.com/sinemacula/laravel-api-toolkit/issues/319)) ([8e56ba4](https://github.com/sinemacula/laravel-api-toolkit/commit/8e56ba4893bfe7f2eb90416c1dd0d1fb048c6a3c))
* discover model-resource bindings via the ForModel attribute ([#325](https://github.com/sinemacula/laravel-api-toolkit/issues/325)) ([d62afe0](https://github.com/sinemacula/laravel-api-toolkit/commit/d62afe0aefcc4200577f3ecc2a3290c8c3d2bead))
* gate nested relation traversal on the declared traversable set [BL-37] ([#324](https://github.com/sinemacula/laravel-api-toolkit/issues/324)) ([4fb25da](https://github.com/sinemacula/laravel-api-toolkit/commit/4fb25da5ad87fee57827ba3579b67eb64b1a1394))
* **openapi:** document exceptions from configured extra namespaces ([#353](https://github.com/sinemacula/laravel-api-toolkit/issues/353)) ([22b4b56](https://github.com/sinemacula/laravel-api-toolkit/commit/22b4b560fdb24ce26121f820646633c262e8f147))
* **openapi:** make the query surface visible in the emitted document ([#360](https://github.com/sinemacula/laravel-api-toolkit/issues/360)) ([ff833c4](https://github.com/sinemacula/laravel-api-toolkit/commit/ff833c4886db864f8c3c33971e8633d50f1dfece))
* refuse an index the engine will not plan against ([#376](https://github.com/sinemacula/laravel-api-toolkit/issues/376)) ([bd09e74](https://github.com/sinemacula/laravel-api-toolkit/commit/bd09e74665ca151ff14f514a5dcae9426d504eec))
* resolve status-derived exception titles through translation keys ([#326](https://github.com/sinemacula/laravel-api-toolkit/issues/326)) ([3ba28fc](https://github.com/sinemacula/laravel-api-toolkit/commit/3ba28fc490e7b72da8b5bc259536208ca63905f3))
* **schema:** type the query surface with capabilities and index-backed sort validation ([#359](https://github.com/sinemacula/laravel-api-toolkit/issues/359)) ([4d42772](https://github.com/sinemacula/laravel-api-toolkit/commit/4d42772d642214a42108a818fcae4786b4998236))
* **search:** add indexed substring search and delete the unsargable $like operator ([#358](https://github.com/sinemacula/laravel-api-toolkit/issues/358)) ([7a287d7](https://github.com/sinemacula/laravel-api-toolkit/commit/7a287d71c901ebbd03d6cf148283df38960b54f5))
* v2 - attribute controllers, soft-delete visibility, and a complete OpenAPI exporter ([#352](https://github.com/sinemacula/laravel-api-toolkit/issues/352)) ([0c9568d](https://github.com/sinemacula/laravel-api-toolkit/commit/0c9568dcd8459a78ea4688b0bc2b0ce0fdcfe44d))


### Bug Fixes

* bound the eager-load walk when resources name each other ([#363](https://github.com/sinemacula/laravel-api-toolkit/issues/363)) ([184d9f0](https://github.com/sinemacula/laravel-api-toolkit/commit/184d9f03435ae5df0c5027f0acafea391b9908d6))
* close fail-open and unbounded-cost defects in the query layer ([#356](https://github.com/sinemacula/laravel-api-toolkit/issues/356)) ([1e43ed6](https://github.com/sinemacula/laravel-api-toolkit/commit/1e43ed66cf0912fcbf7b27589479205b8435dc25))
* declare the repository exception the model lookup can throw ([#387](https://github.com/sinemacula/laravel-api-toolkit/issues/387)) ([47f3166](https://github.com/sinemacula/laravel-api-toolkit/commit/47f3166b1e27e39dbc2c8aa1833f83c9377671df))
* document the query cost caps and skip the search proof on an unreadable connection ([#361](https://github.com/sinemacula/laravel-api-toolkit/issues/361)) ([911a730](https://github.com/sinemacula/laravel-api-toolkit/commit/911a730e36a24412cee9b723107176626b2062b9))
* follow the repository through the copy a composition returns ([#384](https://github.com/sinemacula/laravel-api-toolkit/issues/384)) ([2ae2a1e](https://github.com/sinemacula/laravel-api-toolkit/commit/2ae2a1e9fff23956c200293a72cec2898c51a7bc))
* include the configured server in the schema identity ([#396](https://github.com/sinemacula/laravel-api-toolkit/issues/396)) ([f9621c6](https://github.com/sinemacula/laravel-api-toolkit/commit/f9621c60b94de1b9da823e63d62b36176038ad58))
* keep shared schema metadata warm across lifecycle boundaries ([#391](https://github.com/sinemacula/laravel-api-toolkit/issues/391)) ([a675b9f](https://github.com/sinemacula/laravel-api-toolkit/commit/a675b9f3ca66986fe501a68daa8821533041a0e0))
* keep the page-size ceiling when its configuration is unreadable ([#364](https://github.com/sinemacula/laravel-api-toolkit/issues/364)) ([e296229](https://github.com/sinemacula/laravel-api-toolkit/commit/e296229e45611f98c3a45b81dc9b61b575b81a39))
* measure the offset cap in rows rather than pages ([#369](https://github.com/sinemacula/laravel-api-toolkit/issues/369)) ([18e4c3e](https://github.com/sinemacula/laravel-api-toolkit/commit/18e4c3e98aea112d51becdfba527cd68a0d86452))
* Octane flush listener registration, plus coding-standards v1.14.0 conformance ([#343](https://github.com/sinemacula/laravel-api-toolkit/issues/343)) ([f904769](https://github.com/sinemacula/laravel-api-toolkit/commit/f904769a02664c50e5c3a9f4fc88113594fc7e1c))
* publish the enforced search bound and register warm metadata keys ([#380](https://github.com/sinemacula/laravel-api-toolkit/issues/380)) ([3d8f161](https://github.com/sinemacula/laravel-api-toolkit/commit/3d8f161a348db587e652799d9a3bfaa707374bc6))
* refuse a search backed only by a key collated apart from its column ([#397](https://github.com/sinemacula/laravel-api-toolkit/issues/397)) ([487e257](https://github.com/sinemacula/laravel-api-toolkit/commit/487e257031c87d186bd2874834a6bc56e5f6af85))
* refuse a sort backed only by a key that cannot deliver its column's order ([#395](https://github.com/sinemacula/laravel-api-toolkit/issues/395)) ([9f86492](https://github.com/sinemacula/laravel-api-toolkit/commit/9f864929156342238a93c60907fa5ac80fabab84))
* refuse a sort the engine will not plan against ([#379](https://github.com/sinemacula/laravel-api-toolkit/issues/379)) ([63ef2c0](https://github.com/sinemacula/laravel-api-toolkit/commit/63ef2c057d25e334108936da02e786d1a611ea06))
* reject a filter position that carries a list index ([#365](https://github.com/sinemacula/laravel-api-toolkit/issues/365)) ([f69b5e4](https://github.com/sinemacula/laravel-api-toolkit/commit/f69b5e497a93885c90c5946e8568177e89312619))
* remove the resource cache flush that cleared the whole store ([#388](https://github.com/sinemacula/laravel-api-toolkit/issues/388)) ([6ea38ac](https://github.com/sinemacula/laravel-api-toolkit/commit/6ea38ace12cadce1f047ab84692a2a7bc72be478))
* retire cached metadata in every process when the schema changes ([#389](https://github.com/sinemacula/laravel-api-toolkit/issues/389)) ([be917d1](https://github.com/sinemacula/laravel-api-toolkit/commit/be917d19ac10d9a9c7f5dfec6dcb236cd543bcd3))
* **search:** scope the index-proof waiver to the connection it names ([#362](https://github.com/sinemacula/laravel-api-toolkit/issues/362)) ([f304f3b](https://github.com/sinemacula/laravel-api-toolkit/commit/f304f3bc4d3bafb38858f632af42c2a0ac0a7193))
* separate read aliases and connecting users in the schema identity ([#392](https://github.com/sinemacula/laravel-api-toolkit/issues/392)) ([9fb6e8e](https://github.com/sinemacula/laravel-api-toolkit/commit/9fb6e8e81f3de7c0a354eea8aa8b60aefdb3a672))
* share the request-time search index proof across operations ([#394](https://github.com/sinemacula/laravel-api-toolkit/issues/394)) ([33f3f9f](https://github.com/sinemacula/laravel-api-toolkit/commit/33f3f9f7f0507dd613cd32236f986d98d654a79b))
* ship the index-proof waiver empty ([#368](https://github.com/sinemacula/laravel-api-toolkit/issues/368)) ([b62fdf5](https://github.com/sinemacula/laravel-api-toolkit/commit/b62fdf5905b2d17bb3b89a526a2383e7342614d6))


### Performance Improvements

* memoise assembled field lists across a homogeneous collection ([#333](https://github.com/sinemacula/laravel-api-toolkit/issues/333)) ([367e9f2](https://github.com/sinemacula/laravel-api-toolkit/commit/367e9f226e5bec95b16a91dc22e811a6cb7ddef0))


### Code Refactoring

* adopt the extracted per-query cache from laravel-repositories ([#332](https://github.com/sinemacula/laravel-api-toolkit/issues/332)) ([648d8f4](https://github.com/sinemacula/laravel-api-toolkit/commit/648d8f4aa5000884f467af407567c3ecffd1363d))
