# Changelog

## [2.0.0](https://github.com/sinemacula/laravel-api-toolkit/compare/v1.16.2...v2.0.0) (2026-11-07)


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

* accept pure enums as input and list enum values in the exported components ([#456](https://github.com/sinemacula/laravel-api-toolkit/issues/456)) ([86384ac](https://github.com/sinemacula/laravel-api-toolkit/commit/86384ac91f9d21fea1ddb8ca97449e4969120d20))
* add Service::runOrFail() and never return a failure as output ([#449](https://github.com/sinemacula/laravel-api-toolkit/issues/449)) ([2d22d78](https://github.com/sinemacula/laravel-api-toolkit/commit/2d22d78877ac2831ed1310441b2dfad589a10a3b))
* API Toolkit v2.0 ([#319](https://github.com/sinemacula/laravel-api-toolkit/issues/319)) ([8e56ba4](https://github.com/sinemacula/laravel-api-toolkit/commit/8e56ba4893bfe7f2eb90416c1dd0d1fb048c6a3c))
* discover model-resource bindings via the ForModel attribute ([#325](https://github.com/sinemacula/laravel-api-toolkit/issues/325)) ([d62afe0](https://github.com/sinemacula/laravel-api-toolkit/commit/d62afe0aefcc4200577f3ecc2a3290c8c3d2bead))
* document declared response headers and the headers an exception sets ([#455](https://github.com/sinemacula/laravel-api-toolkit/issues/455)) ([868f96c](https://github.com/sinemacula/laravel-api-toolkit/commit/868f96c83964dba21784c5469039d0b9a6f7497a))
* document route-derived error responses for every OpenAPI operation ([#440](https://github.com/sinemacula/laravel-api-toolkit/issues/440)) ([c80e81a](https://github.com/sinemacula/laravel-api-toolkit/commit/c80e81ab0ce48fc828253f14a6efe4acc67e9e69))
* document several response shapes from one ResponseSchema ([#447](https://github.com/sinemacula/laravel-api-toolkit/issues/447)) ([5638050](https://github.com/sinemacula/laravel-api-toolkit/commit/563805011679e2dd3b3d528f0b399364a2b12c07))
* export servers in the OpenAPI document, globally and per audience ([#470](https://github.com/sinemacula/laravel-api-toolkit/issues/470)) ([39d8ce1](https://github.com/sinemacula/laravel-api-toolkit/commit/39d8ce1cff91476f5545e69f48a42e7f5dc88417))
* gate nested relation traversal on the declared traversable set [BL-37] ([#324](https://github.com/sinemacula/laravel-api-toolkit/issues/324)) ([4fb25da](https://github.com/sinemacula/laravel-api-toolkit/commit/4fb25da5ad87fee57827ba3579b67eb64b1a1394))
* hydrate camelCase payload properties from snake_case body keys ([#446](https://github.com/sinemacula/laravel-api-toolkit/issues/446)) ([eb55622](https://github.com/sinemacula/laravel-api-toolkit/commit/eb5562256af406b13e4b3136136d77439118622e))
* leave #[\SensitiveParameter] fields out of Payload::toArray() ([#434](https://github.com/sinemacula/laravel-api-toolkit/issues/434)) ([ba22c64](https://github.com/sinemacula/laravel-api-toolkit/commit/ba22c64b68f3f46a3aa1f5546057e8ea66f555ac))
* let a service read its run context and build one from the request ([#450](https://github.com/sinemacula/laravel-api-toolkit/issues/450)) ([ecf0c0d](https://github.com/sinemacula/laravel-api-toolkit/commit/ecf0c0de6e14d25329d73f89b06caa3c9da706c0))
* let an action declare its success status ([#468](https://github.com/sinemacula/laravel-api-toolkit/issues/468)) ([9e3df56](https://github.com/sinemacula/laravel-api-toolkit/commit/9e3df561f0cfa23c7cb3a099467c03941cb4fa2b))
* **openapi:** document exceptions from configured extra namespaces ([#353](https://github.com/sinemacula/laravel-api-toolkit/issues/353)) ([22b4b56](https://github.com/sinemacula/laravel-api-toolkit/commit/22b4b560fdb24ce26121f820646633c262e8f147))
* **openapi:** make the query surface visible in the emitted document ([#360](https://github.com/sinemacula/laravel-api-toolkit/issues/360)) ([ff833c4](https://github.com/sinemacula/laravel-api-toolkit/commit/ff833c4886db864f8c3c33971e8633d50f1dfece))
* refuse an index the engine will not plan against ([#376](https://github.com/sinemacula/laravel-api-toolkit/issues/376)) ([bd09e74](https://github.com/sinemacula/laravel-api-toolkit/commit/bd09e74665ca151ff14f514a5dcae9426d504eec))
* resolve status-derived exception titles through translation keys ([#326](https://github.com/sinemacula/laravel-api-toolkit/issues/326)) ([3ba28fc](https://github.com/sinemacula/laravel-api-toolkit/commit/3ba28fc490e7b72da8b5bc259536208ca63905f3))
* **schema:** type the query surface with capabilities and index-backed sort validation ([#359](https://github.com/sinemacula/laravel-api-toolkit/issues/359)) ([4d42772](https://github.com/sinemacula/laravel-api-toolkit/commit/4d42772d642214a42108a818fcae4786b4998236))
* **search:** add indexed substring search and delete the unsargable $like operator ([#358](https://github.com/sinemacula/laravel-api-toolkit/issues/358)) ([7a287d7](https://github.com/sinemacula/laravel-api-toolkit/commit/7a287d71c901ebbd03d6cf148283df38960b54f5))
* type the service input as its declared input template ([#443](https://github.com/sinemacula/laravel-api-toolkit/issues/443)) ([8b5b7dd](https://github.com/sinemacula/laravel-api-toolkit/commit/8b5b7ddc20145a8b63bc8071c392075c5eea0aaf))
* v2 - attribute controllers, soft-delete visibility, and a complete OpenAPI exporter ([#352](https://github.com/sinemacula/laravel-api-toolkit/issues/352)) ([0c9568d](https://github.com/sinemacula/laravel-api-toolkit/commit/0c9568dcd8459a78ea4688b0bc2b0ce0fdcfe44d))


### Bug Fixes

* bound the eager-load walk when resources name each other ([#363](https://github.com/sinemacula/laravel-api-toolkit/issues/363)) ([184d9f0](https://github.com/sinemacula/laravel-api-toolkit/commit/184d9f03435ae5df0c5027f0acafea391b9908d6))
* close fail-open and unbounded-cost defects in the query layer ([#356](https://github.com/sinemacula/laravel-api-toolkit/issues/356)) ([1e43ed6](https://github.com/sinemacula/laravel-api-toolkit/commit/1e43ed66cf0912fcbf7b27589479205b8435dc25))
* compile resource discovery to bootstrap/cache instead of the cache store ([#418](https://github.com/sinemacula/laravel-api-toolkit/issues/418)) ([af5cacd](https://github.com/sinemacula/laravel-api-toolkit/commit/af5cacd62e5efd17d358f4c5d6c961b9abe02fd5))
* declare the repository exception the model lookup can throw ([#387](https://github.com/sinemacula/laravel-api-toolkit/issues/387)) ([47f3166](https://github.com/sinemacula/laravel-api-toolkit/commit/47f3166b1e27e39dbc2c8aa1833f83c9377671df))
* document a resource action's declared response schema ([#480](https://github.com/sinemacula/laravel-api-toolkit/issues/480)) ([174497c](https://github.com/sinemacula/laravel-api-toolkit/commit/174497ca49d00f97904a9a92cd9e6a3c1e658f18))
* document the query cost caps and skip the search proof on an unreadable connection ([#361](https://github.com/sinemacula/laravel-api-toolkit/issues/361)) ([911a730](https://github.com/sinemacula/laravel-api-toolkit/commit/911a730e36a24412cee9b723107176626b2062b9))
* export a narrowed Rule::enum() as the values it accepts ([#463](https://github.com/sinemacula/laravel-api-toolkit/issues/463)) ([5b149da](https://github.com/sinemacula/laravel-api-toolkit/commit/5b149dacb4ce8e4990e40ac86c92f6815e40f5ec))
* export conditional presence rules and require the confirmation field ([#481](https://github.com/sinemacula/laravel-api-toolkit/issues/481)) ([215f268](https://github.com/sinemacula/laravel-api-toolkit/commit/215f2688e56d906791387a3580461bb8c493d472))
* export narrowed enum rules as their exact values, an empty enum when none is accepted ([#469](https://github.com/sinemacula/laravel-api-toolkit/issues/469)) ([0c109ac](https://github.com/sinemacula/laravel-api-toolkit/commit/0c109ac0193a07892da6a3d7a1ee5b1a9109c996))
* export numeric rule keys instead of crashing ([#482](https://github.com/sinemacula/laravel-api-toolkit/issues/482)) ([ee5e05b](https://github.com/sinemacula/laravel-api-toolkit/commit/ee5e05b0a8baa8b133ab8f86bb3d5b9b069b68c3))
* export required arrays as non-empty and required parents of required fields ([#490](https://github.com/sinemacula/laravel-api-toolkit/issues/490)) ([05904c3](https://github.com/sinemacula/laravel-api-toolkit/commit/05904c3f128f4e1a8e97b4d0db7661cbd7d4ba4e))
* export Rule::in() objects as the field's enum ([#457](https://github.com/sinemacula/laravel-api-toolkit/issues/457)) ([fa5ba62](https://github.com/sinemacula/laravel-api-toolkit/commit/fa5ba623b3933c01c159eb787c52b4656746c990))
* fail the OpenAPI export when an audience documents no operations ([#445](https://github.com/sinemacula/laravel-api-toolkit/issues/445)) ([a7a4930](https://github.com/sinemacula/laravel-api-toolkit/commit/a7a4930a1918f810e698131c7068313553e65cac))
* fail the queued job when its service fails ([#471](https://github.com/sinemacula/laravel-api-toolkit/issues/471)) ([69ecb0e](https://github.com/sinemacula/laravel-api-toolkit/commit/69ecb0ec27fc3d53577dabaac3191b59bd80e981))
* filter on falsy values instead of dropping the filter ([#467](https://github.com/sinemacula/laravel-api-toolkit/issues/467)) ([72d325f](https://github.com/sinemacula/laravel-api-toolkit/commit/72d325f42585c58ee141ae037ccbdbbce574dcc8))
* follow the repository through the copy a composition returns ([#384](https://github.com/sinemacula/laravel-api-toolkit/issues/384)) ([2ae2a1e](https://github.com/sinemacula/laravel-api-toolkit/commit/2ae2a1e9fff23956c200293a72cec2898c51a7bc))
* forward Retry-After and Refresh headers from maintenance mode ([#413](https://github.com/sinemacula/laravel-api-toolkit/issues/413)) ([ed63576](https://github.com/sinemacula/laravel-api-toolkit/commit/ed635765ad99feb77935282575a59dc33de6c4e2))
* include the configured server in the schema identity ([#396](https://github.com/sinemacula/laravel-api-toolkit/issues/396)) ([f9621c6](https://github.com/sinemacula/laravel-api-toolkit/commit/f9621c60b94de1b9da823e63d62b36176038ad58))
* keep rule array elements whole instead of splitting them on pipes ([#458](https://github.com/sinemacula/laravel-api-toolkit/issues/458)) ([73c2525](https://github.com/sinemacula/laravel-api-toolkit/commit/73c2525482de8f5d5fd9c1b41c67214c5b7b3cdb))
* keep shared schema metadata warm across lifecycle boundaries ([#391](https://github.com/sinemacula/laravel-api-toolkit/issues/391)) ([a675b9f](https://github.com/sinemacula/laravel-api-toolkit/commit/a675b9f3ca66986fe501a68daa8821533041a0e0))
* keep the maintenance middleware after CORS when swapping it in ([#422](https://github.com/sinemacula/laravel-api-toolkit/issues/422)) ([bc4fdb4](https://github.com/sinemacula/laravel-api-toolkit/commit/bc4fdb4e4df0e30abcaee05d12d0386e42c12e85))
* keep the metadata cache readable with serializable_classes disabled ([#466](https://github.com/sinemacula/laravel-api-toolkit/issues/466)) ([b30bb80](https://github.com/sinemacula/laravel-api-toolkit/commit/b30bb807ab97e83c6471940bc3f7502d4d18ac76))
* keep the page-size ceiling when its configuration is unreadable ([#364](https://github.com/sinemacula/laravel-api-toolkit/issues/364)) ([e296229](https://github.com/sinemacula/laravel-api-toolkit/commit/e296229e45611f98c3a45b81dc9b61b575b81a39))
* let an array field with named children accept a list ([#484](https://github.com/sinemacula/laravel-api-toolkit/issues/484)) ([669779e](https://github.com/sinemacula/laravel-api-toolkit/commit/669779ecc67d1fdd324a1dc1b84040053401e4b6))
* measure the offset cap in rows rather than pages ([#369](https://github.com/sinemacula/laravel-api-toolkit/issues/369)) ([18e4c3e](https://github.com/sinemacula/laravel-api-toolkit/commit/18e4c3e98aea112d51becdfba527cd68a0d86452))
* merge dynamic morph aliases instead of requiring a morph map ([#448](https://github.com/sinemacula/laravel-api-toolkit/issues/448)) ([9377d37](https://github.com/sinemacula/laravel-api-toolkit/commit/9377d379d4307ca2e414df8791a88f1c36db2b38))
* never export an empty array as a schema, and document prohibited fields as false ([#464](https://github.com/sinemacula/laravel-api-toolkit/issues/464)) ([1920f47](https://github.com/sinemacula/laravel-api-toolkit/commit/1920f4707c6373eeb3a877d1e65e3d1ef8a48d76))
* Octane flush listener registration, plus coding-standards v1.14.0 conformance ([#343](https://github.com/sinemacula/laravel-api-toolkit/issues/343)) ([f904769](https://github.com/sinemacula/laravel-api-toolkit/commit/f904769a02664c50e5c3a9f4fc88113594fc7e1c))
* publish the enforced search bound and register warm metadata keys ([#380](https://github.com/sinemacula/laravel-api-toolkit/issues/380)) ([3d8f161](https://github.com/sinemacula/laravel-api-toolkit/commit/3d8f161a348db587e652799d9a3bfaa707374bc6))
* refuse a search backed only by a key collated apart from its column ([#397](https://github.com/sinemacula/laravel-api-toolkit/issues/397)) ([487e257](https://github.com/sinemacula/laravel-api-toolkit/commit/487e257031c87d186bd2874834a6bc56e5f6af85))
* refuse a sort backed only by a key that cannot deliver its column's order ([#395](https://github.com/sinemacula/laravel-api-toolkit/issues/395)) ([9f86492](https://github.com/sinemacula/laravel-api-toolkit/commit/9f864929156342238a93c60907fa5ac80fabab84))
* refuse a sort the engine will not plan against ([#379](https://github.com/sinemacula/laravel-api-toolkit/issues/379)) ([63ef2c0](https://github.com/sinemacula/laravel-api-toolkit/commit/63ef2c057d25e334108936da02e786d1a611ea06))
* reject a filter position that carries a list index ([#365](https://github.com/sinemacula/laravel-api-toolkit/issues/365)) ([f69b5e4](https://github.com/sinemacula/laravel-api-toolkit/commit/f69b5e497a93885c90c5946e8568177e89312619))
* remove the resource cache flush that cleared the whole store ([#388](https://github.com/sinemacula/laravel-api-toolkit/issues/388)) ([6ea38ac](https://github.com/sinemacula/laravel-api-toolkit/commit/6ea38ace12cadce1f047ab84692a2a7bc72be478))
* report caught failures through the exception handler ([#414](https://github.com/sinemacula/laravel-api-toolkit/issues/414)) ([f1541b6](https://github.com/sinemacula/laravel-api-toolkit/commit/f1541b6c8553aab8edff076730880fa3f95449de))
* report the export summary counts from the written document ([#472](https://github.com/sinemacula/laravel-api-toolkit/issues/472)) ([1f6ff00](https://github.com/sinemacula/laravel-api-toolkit/commit/1f6ff00245189a474f10cc44dcc3e2a3a7063e57))
* resolve imported [@throws](https://github.com/throws) names when documenting error responses ([#473](https://github.com/sinemacula/laravel-api-toolkit/issues/473)) ([c3faecf](https://github.com/sinemacula/laravel-api-toolkit/commit/c3faecf7dcba7c3cbfa4877696dcf568b73692aa))
* retire cached metadata in every process when the schema changes ([#389](https://github.com/sinemacula/laravel-api-toolkit/issues/389)) ([be917d1](https://github.com/sinemacula/laravel-api-toolkit/commit/be917d19ac10d9a9c7f5dfec6dcb236cd543bcd3))
* **search:** scope the index-proof waiver to the connection it names ([#362](https://github.com/sinemacula/laravel-api-toolkit/issues/362)) ([f304f3b](https://github.com/sinemacula/laravel-api-toolkit/commit/f304f3bc4d3bafb38858f632af42c2a0ac0a7193))
* separate read aliases and connecting users in the schema identity ([#392](https://github.com/sinemacula/laravel-api-toolkit/issues/392)) ([9fb6e8e](https://github.com/sinemacula/laravel-api-toolkit/commit/9fb6e8e81f3de7c0a354eea8aa8b60aefdb3a672))
* serve requests when the metadata cache store is unavailable ([#424](https://github.com/sinemacula/laravel-api-toolkit/issues/424)) ([dd7be23](https://github.com/sinemacula/laravel-api-toolkit/commit/dd7be23e39346bfc56ecdc968a876068be64b02e))
* share the request-time search index proof across operations ([#394](https://github.com/sinemacula/laravel-api-toolkit/issues/394)) ([33f3f9f](https://github.com/sinemacula/laravel-api-toolkit/commit/33f3f9f7f0507dd613cd32236f986d98d654a79b))
* ship the index-proof waiver empty ([#368](https://github.com/sinemacula/laravel-api-toolkit/issues/368)) ([b62fdf5](https://github.com/sinemacula/laravel-api-toolkit/commit/b62fdf5905b2d17bb3b89a526a2383e7342614d6))
* stop length-aware index pages matching both pagination shapes ([#444](https://github.com/sinemacula/laravel-api-toolkit/issues/444)) ([e0d953a](https://github.com/sinemacula/laravel-api-toolkit/commit/e0d953a7eb8907b41253e00cd4e81f0fed38bb7c))
* stop reporting and logging handled api exceptions ([#415](https://github.com/sinemacula/laravel-api-toolkit/issues/415)) ([9a0ca5b](https://github.com/sinemacula/laravel-api-toolkit/commit/9a0ca5be61f4f1e3db2f2c0c3d650fcbc8c46dc4))
* translate pure enum wire names in filters and repository writes ([#462](https://github.com/sinemacula/laravel-api-toolkit/issues/462)) ([b2a3414](https://github.com/sinemacula/laravel-api-toolkit/commit/b2a34143255c386236c09049168c830ff1308cec))
* type Payload rule keys as array keys ([#485](https://github.com/sinemacula/laravel-api-toolkit/issues/485)) ([db1a305](https://github.com/sinemacula/laravel-api-toolkit/commit/db1a3055c3ffa3ee9400e49b5cecf4d4e44eb4b7))
* write the notification audit log to a configurable channel ([#417](https://github.com/sinemacula/laravel-api-toolkit/issues/417)) ([00d5043](https://github.com/sinemacula/laravel-api-toolkit/commit/00d5043525a7b6892afa4212c1418381ad6ed06d))


### Performance Improvements

* memoise assembled field lists across a homogeneous collection ([#333](https://github.com/sinemacula/laravel-api-toolkit/issues/333)) ([367e9f2](https://github.com/sinemacula/laravel-api-toolkit/commit/367e9f226e5bec95b16a91dc22e811a6cb7ddef0))


### Code Refactoring

* adopt the extracted per-query cache from laravel-repositories ([#332](https://github.com/sinemacula/laravel-api-toolkit/issues/332)) ([648d8f4](https://github.com/sinemacula/laravel-api-toolkit/commit/648d8f4aa5000884f467af407567c3ecffd1363d))
