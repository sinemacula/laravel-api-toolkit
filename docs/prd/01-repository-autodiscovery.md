# PRD: 01 Repository Autodiscovery

Eliminate manual repository registration by automatically discovering repository classes and resolving their aliases at application boot time.

---

## Governance

| Field     | Value                                                                                                                                         |
|-----------|-----------------------------------------------------------------------------------------------------------------------------------------------|
| Created   | 2026-02-26                                                                                                                                    |
| Status    | approved                                                                                                                                      |
| Owned by  | Ben                                                                                                                                           |
| Traces to | [Prioritization](.blueprint/workflows/repository-autodiscovery/prioritization.md) -- All 11 P0 problems (P1, P2, P3, P4, P5, P6, P7, P8, P9, P12, P13) plus 5 P1 problems (P10, P11, P14, P15, P16) |

---

## Overview

Developers using the Laravel API Toolkit in large applications must manually register every repository class in a config array, mapping each to a string alias. This manual `repository_map` grows linearly with the application, is prone to stale entries after refactoring, causes merge conflicts when multiple developers add repositories simultaneously, and provides no feedback when two repositories claim the same alias. The burden is especially acute in modular Laravel applications where repositories are spread across dozens of module directories rather than a single `app/` folder.

Repository autodiscovery replaces this manual process with an automatic mechanism that finds repository classes across configured directories, reads each repository's alias from metadata declared on the class itself, validates that no two repositories share the same alias, and registers them -- all during application boot. Developers adding a new repository simply create the class with its alias declaration; the toolkit does the rest. A caching mechanism ensures that discovery overhead is negligible in production, and an Artisan introspection command provides visibility into what was discovered.

This is the right time to build this because the package has reached a maturity point where the manual registration pattern is the primary friction point for adoption in larger projects. Breaking changes are acceptable for this release, and the maintainer of the sibling package (`sinemacula/laravel-repositories`) is willing to coordinate changes across both packages.

---

## Target Users

| Persona                  | Description                                                                                                                 | Key Need                                                                           |
|--------------------------|-----------------------------------------------------------------------------------------------------------------------------|------------------------------------------------------------------------------------|
| Standard App Developer   | Developer using the API Toolkit in a conventional Laravel application with repositories in the default `app/` directory     | Add new repositories without touching a config file                                |
| Modular App Developer    | Developer using the API Toolkit in a modular Laravel architecture with repositories spread across `modules/*/Repositories/` | Autodiscovery that works across multiple module directories without host-app helpers |
| Package Maintainer       | Maintainer of `sinemacula/laravel-api-toolkit` and `sinemacula/laravel-repositories`                                        | Clear cross-package specification for coordinated release                          |

**Primary user:** Modular App Developer (the intake brief originates from this persona and represents the most constrained use case; solving for modular apps inherently solves for standard apps).

---

## Goals

- Reduce the number of manual steps required to make a new repository available from two (create class + add config entry) to one (create class)
- Eliminate stale config entries as a failure mode entirely
- Ensure modular applications can use autodiscovery without depending on host-application helpers
- Maintain or improve application boot performance in production relative to the current manual config approach
- Provide actionable, developer-friendly error messages when alias conflicts occur

## Non-Goals

- Providing autodiscovery for repositories that do not extend the API Toolkit's base repository class (see Out of Scope: P17)
- Auto-invalidating the discovery cache when files change on disk (developers will run an explicit command, consistent with Laravel's caching patterns for events and routes)
- Supporting runtime dynamic repository registration as a replacement for the current `RepositoryResolver::register()` method (the existing method may coexist with autodiscovery)
- Building a general-purpose PHP class discovery library (the discovery mechanism is scoped to repositories for this package)

---

## Problem

**User problem:** Every new repository requires a manual config entry in the `repository_map` array, creating daily friction that scales linearly with application size. Developers forget entries (causing runtime errors), leave stale entries after refactoring (causing delayed errors), and suffer merge conflicts in large teams. In modular applications, the problem is compounded because repositories live in many directories, and there is no way to tell the package where to look. When two repositories accidentally claim the same alias, the current system silently overwrites one with the other, introducing subtle bugs with no diagnostic feedback.

**Business problem:** The manual registration burden is the primary friction point for adopting the API Toolkit in large projects. It increases onboarding cost, slows development velocity, and reduces confidence in the repository layer. For an open-source package competing for developer mindshare, reducing boilerplate and providing a zero-config experience for common cases is a competitive necessity.

**Current state:** Developers manually maintain the `repository_map` array in the published `api-toolkit.php` config file, adding a `'alias' => RepositoryClass::class` entry for each repository. The `RepositoryResolver` reads this array on first access and caches it in a static property. There is no discovery, no alias validation, no duplicate detection, and no support for non-standard directory structures.

**Evidence:**

- [Intake Brief](.blueprint/workflows/repository-autodiscovery/intake-brief.md): "this is a nightmare to maintain on large Laravel applications"
- [Problem Map](.blueprint/workflows/repository-autodiscovery/problem-map.md): 5 clusters, 17 problems, 4 rated high severity
- [Spike: Alias Derivation](.blueprint/workflows/repository-autodiscovery/spikes/spike-alias-derivation.md): Finding 4 shows convention-derived aliases diverge from developer intent; Finding 6 shows the `RESOURCE_TYPE` constant pattern already exists in this codebase
- [Spike: Laravel Autodiscovery](.blueprint/workflows/repository-autodiscovery/spikes/spike-laravel-autodiscovery.md): Finding 2 shows Laravel's own event discovery uses caching to avoid per-request scanning overhead
- [Spike: Modular Discovery](.blueprint/workflows/repository-autodiscovery/spikes/spike-modular-discovery.md): Finding 1 shows every module-aware package uses configurable discovery paths; Finding 10 shows caching is universal
- [Spike: Duplicate Detection](.blueprint/workflows/repository-autodiscovery/spikes/spike-duplicate-detection.md): Finding 1 shows Laravel silently overwrites duplicates, a documented source of bugs

---

## Proposed Solution

When a developer creates a new repository class, they declare the repository's alias directly on the class using a mechanism that can be read without instantiating the repository. This is the only step required. On application boot, the toolkit automatically discovers all repository classes within configured directories, reads each class's alias, validates that no duplicates exist, and registers them for resolution through `RepositoryResolver`.

For a **standard Laravel application**, the developer installs the package and creates repository classes in the default location. Each repository declares its alias. No configuration is needed -- the toolkit discovers repositories automatically.

For a **modular Laravel application**, the developer publishes the toolkit config and specifies the directories where repository classes live (e.g., `modules/auth/Repositories`, `modules/billing/Repositories`). The toolkit scans all specified directories during discovery.

When a developer creates a repository with an alias that collides with another repository, the application fails at boot time with a clear error message identifying the alias, both conflicting classes (with their file paths), and guidance on how to resolve the conflict. If multiple conflicts exist, all are reported at once.

In **development**, discovery runs on every boot to immediately reflect new or changed repositories. In **production**, the developer runs a cache command during deployment to pre-compute the discovery result. Subsequent requests load the cached result with no filesystem scanning. A cache-clear command is available for troubleshooting.

At any time, the developer can run an Artisan introspection command to see all discovered repositories, their aliases, and where they were found.

### Key Capabilities

- Developer can create a repository class with an alias declaration and have it automatically registered without touching any config file
- Developer can configure which directories the toolkit should scan for repositories
- Developer can cache the discovery result for production performance
- Developer can see a clear, actionable error when two repositories share the same alias
- Developer can inspect all discovered repositories via an Artisan command

---

## Requirements

### Must Have (P0)

- **R1: Automatic repository registration:** Developer can create a repository class with an alias declaration and have it automatically discovered and registered in the `RepositoryResolver` without adding any manual config entry.
  - **Acceptance criteria:** A newly created repository class extending the base repository with a valid alias declaration is resolvable via `RepositoryResolver::get('alias')` without any config file changes. This is verified by an integration test that creates a repository class and confirms resolution without a `repository_map` entry.
  - **Traces to:** P1 (Every Repository Requires Manual Config Entry), Rank 1, Score 9

- **R2: Stale entry elimination:** Developer is no longer exposed to stale config entries when repositories are renamed, moved, or deleted.
  - **Acceptance criteria:** After removing a repository class file, the toolkit no longer attempts to resolve that repository. No config file cleanup is required. Attempting to resolve the removed repository's alias produces a "not found" error rather than a class-not-found error.
  - **Traces to:** P2 (Stale Config Entries After Refactoring), Rank 10, Score 8

- **R3: Config file reduction:** Developer's `api-toolkit.php` config file does not contain a `repository_map` array that grows with the application.
  - **Acceptance criteria:** The published config file does not include a `repository_map` key that requires manual per-repository entries. A project with 50 repositories has the same config file size as a project with 5 repositories (excluding discovery path configuration, if applicable).
  - **Traces to:** P3 (Config File Becomes Unwieldy at Scale), Rank 9, Score 8

- **R4: Explicit alias declaration with override capability:** Developer can declare an alias on each repository class that takes precedence over any convention-based default, ensuring the alias always matches developer intent.
  - **Acceptance criteria:** A repository with an explicitly declared alias (e.g., `'factors'`) is registered under that exact alias regardless of the class name or model name. The declared alias is the alias returned by `RepositoryResolver::map()`.
  - **Traces to:** P4 (Convention-Derived Aliases Diverge from Developer Intent), Rank 6, Score 8

- **R5: Enforced alias contract:** Developer receives a clear error at boot time if a repository class does not declare a valid alias.
  - **Acceptance criteria:** A repository class that extends the base repository without declaring an alias (or declaring an empty alias) causes a boot-time error with a message identifying the class and explaining that an alias is required. The application does not boot successfully with an alias-less repository.
  - **Traces to:** P5 (No Enforced Contract for Alias Declaration), Rank 7, Score 7

- **R6: Pre-instantiation alias reading:** Developer's alias declaration is readable by the discovery mechanism without instantiating the repository or its model.
  - **Acceptance criteria:** During the discovery phase, no repository constructor is invoked and no Eloquent model is resolved from the container. Alias reading works even when the application's database is unavailable. This is verified by a test that confirms discovery completes without database connectivity.
  - **Traces to:** P6 (Alias Derivation Requires Information Not Available Before Instantiation), Rank 4, Score 8

- **R7: Configurable discovery paths:** Developer can specify which directories the toolkit scans for repository classes, supporting modular and non-standard directory structures.
  - **Acceptance criteria:** A developer with repositories in `modules/auth/Repositories/` and `modules/billing/Repositories/` can configure these paths in the toolkit config and have all repositories in those directories discovered. Paths are specified as provided by the developer without opinionated normalization (no automatic appending of wildcards or suffixes). The default configuration works for a standard Laravel application without any path configuration.
  - **Traces to:** P7 (Standard Laravel Path Assumptions Do Not Apply to Modular Apps), Rank 2, Score 8; P8 (No Standard Way to Tell a Package Where to Look), Rank 8, Score 7

- **R8: Production-ready caching:** Developer can cache the discovery result so that production requests do not incur filesystem scanning overhead.
  - **Acceptance criteria:** After running a cache command, subsequent application boots load the repository map from the cache without scanning the filesystem. The cached boot path does not perform directory traversal, file reading, or reflection. The cache can be cleared via a separate command. Boot time with a cached discovery result is no slower than the current config-based approach.
  - **Traces to:** P9 (Filesystem Scanning on Every Request Is Prohibitively Expensive for Production), Rank 3, Score 8

- **R9: Duplicate alias detection with hard failure:** Developer receives a hard error at boot time when two or more repository classes declare the same alias.
  - **Acceptance criteria:** When two repository classes declare the same alias, the application fails to boot. The error is raised during the discovery/registration phase, not deferred to first use. The error occurs regardless of whether the repositories are in the same directory or different directories. Silent overwriting never occurs.
  - **Traces to:** P12 (Duplicate Aliases Across Modules Produce No Error), Rank 5, Score 9

- **R10: Actionable conflict error messages:** Developer receives an error message that identifies the conflicting alias, both conflicting class names with their file locations, and guidance on how to resolve the conflict.
  - **Acceptance criteria:** The duplicate alias error message contains: (1) the alias that is duplicated, (2) the fully qualified class name of both conflicting repositories, (3) the file path of both conflicting repositories, and (4) a human-readable instruction on how to resolve the conflict (e.g., changing one of the aliases). The message is self-contained -- the developer does not need to search the codebase to identify the conflicting classes.
  - **Traces to:** P13 (Conflict Error Messages Lack Actionable Context), Rank 13, Score 7

- **R11: Discovery path configuration format:** Developer can specify discovery paths using a clear, documented configuration format that does not depend on host-application helpers.
  - **Acceptance criteria:** Discovery paths are configured in the toolkit's config file using absolute paths or paths relative to the application's base path. The configuration does not reference `module_path()` or any other host-application helper. The configuration format is documented with examples for both standard and modular directory structures.
  - **Traces to:** P8 (No Standard Way to Tell a Package Where to Look), Rank 8, Score 7

### Should Have (P1)

- **R12: Consistent dev/prod discovery behavior:** Developer experiences the same set of discovered repositories in development and production, regardless of Composer autoloader optimization level.
  - **Acceptance criteria:** The same repository classes are discovered whether or not `composer dump-autoload --optimize` has been run. No repository is silently missed in development that would be found in production, or vice versa.
  - **Traces to:** P10 (Composer Classmap Availability Differs Between Development and Production), Rank 11, Score 6

- **R13: Cache management commands:** Developer can rebuild and clear the discovery cache via Artisan commands, following the same patterns as Laravel's `event:cache` and `event:clear`.
  - **Acceptance criteria:** An Artisan command exists to build/rebuild the discovery cache. A separate Artisan command exists to clear the discovery cache. Both commands produce confirmation output indicating success. The cache command can be included in deployment scripts alongside `event:cache` and `route:cache`.
  - **Traces to:** P11 (Cache Invalidation Is a Manual Burden), Rank 12, Score 6

- **R14: All conflicts reported at once:** Developer sees all duplicate alias conflicts in a single error, not one at a time.
  - **Acceptance criteria:** When three repositories declare alias `'users'` and two repositories declare alias `'orders'`, the error message reports both the `'users'` conflict (with all three classes) and the `'orders'` conflict (with both classes) in a single exception. The developer does not need to fix one conflict, restart, and discover the next.
  - **Traces to:** P14 (Reporting Only the First Conflict Forces Restart Loops), Rank 15, Score 6

- **R15: Repository introspection command:** Developer can run an Artisan command to list all discovered repositories with their aliases and source locations.
  - **Acceptance criteria:** An Artisan command exists that displays a table of all registered repositories showing at minimum: alias, fully qualified class name, and file path. The command works in both cached and uncached modes. The output format is human-readable by default.
  - **Traces to:** P15 (No Introspection Tooling for Discovered Repositories), Rank 16, Score 5

- **R16: Cross-package coordination specification:** The PRD includes a clear specification of changes needed in `sinemacula/laravel-repositories` so the base package maintainer can create a companion PRD.
  - **Acceptance criteria:** The Cross-Package Specification section of this PRD is detailed enough for the base package maintainer to understand what changes are needed, why, and what contract the API Toolkit depends on, without requiring additional discussion. See the Dependencies section below.
  - **Traces to:** P16 (Alias Infrastructure May Require Changes to the Base Repository Package), Rank 14, Score 6

### Nice to Have (P2)

- **R17: Manual registration coexistence:** Developer can use manual registration alongside autodiscovery during migration, with manually registered repositories taking precedence over discovered ones.
  - **Acceptance criteria:** A repository registered via a manual mechanism takes precedence over a repository discovered via autodiscovery if both claim the same alias. This allows incremental migration from manual to automatic registration.

---

## Success Criteria

| Metric                           | Baseline                                                            | Target                                                     | How Measured                                                                                            |
|----------------------------------|---------------------------------------------------------------------|------------------------------------------------------------|---------------------------------------------------------------------------------------------------------|
| Manual config entries required   | One entry per repository (N entries for N repositories)             | Zero entries for standard apps; path-only config for modular apps | Count of `repository_map` entries in a sample project before and after migration                         |
| Repository creation steps        | 2 steps (create class + add config)                                 | 1 step (create class)                                      | Manual count of steps to add a new repository to a working application                                  |
| Stale config entry failures      | Non-zero (occur after refactoring when config is not updated)       | Zero (no config entries to go stale)                       | Count of runtime errors caused by stale `repository_map` entries across a test suite after refactoring  |
| Production boot time (cached)    | Current time with config-based resolution (baseline to be measured) | No more than 5% slower than baseline                       | Benchmark of `RepositoryResolver::map()` execution time in a test with 100 repository entries           |
| Duplicate alias detection rate   | 0% (duplicates silently overwritten)                                | 100% (all duplicates produce errors)                       | Test suite that asserts every duplicate combination produces a boot-time exception                       |
| Conflict resolution time         | Unknown (developer must search codebase manually)                   | Under 30 seconds per conflict                              | Qualitative assessment: error message contains alias, both classes, file paths, and resolution guidance  |

---

## Dependencies

- **sinemacula/laravel-repositories:** The alias declaration mechanism requires changes to the base repository package. See Cross-Package Specification below.
- **PHP 8.3:** Required for typed class constants and native attribute support. Already a requirement of the current package.
- **Symfony Finder (or equivalent scanning capability):** Already a transitive dependency via `laravel/framework`. No new package dependency is required.

### Cross-Package Specification

This section specifies changes needed in `sinemacula/laravel-repositories` to support repository autodiscovery. This specification is intended as a hand-off document for the base package maintainer to create a companion PRD.

**Context:** Repository autodiscovery requires reading a repository's alias before it is instantiated (because the `Repository` constructor calls `makeModel()`, which resolves the Eloquent model from the container). The alias must be declared as class-level metadata that is readable via reflection without constructing the repository.

**What is needed from the base package:**

1. **Alias declaration capability on the base repository contract:** Every repository that participates in autodiscovery must be able to declare its alias. The API Toolkit's `ApiRepository` extends the base `Repository` class. The alias mechanism should be introduced at the base package level so that the contract is consistent across the inheritance chain. This aligns with the intake brief's acceptance that "breaking changes to the repository contract are acceptable."

2. **Pre-instantiation readability:** Whatever mechanism is chosen for alias declaration (typed constant, attribute, or abstract method on the base class), it must be readable via reflection (`ReflectionClass`, `ReflectionClassConstant`, or `ReflectionClass::getAttributes()`) without calling the repository constructor. This rules out instance methods as the sole mechanism.

3. **No enforcement of specific alias values:** The base package should provide the structural mechanism for alias declaration (the "slot" where an alias goes) but should not impose constraints on alias format or uniqueness. Uniqueness enforcement is the API Toolkit's responsibility during discovery.

4. **Backward compatibility consideration:** The base package has its own consumers beyond the API Toolkit. The alias mechanism should be additive where possible. If it requires a breaking change (e.g., adding an abstract method to the base class), this should be released as a new major version with a clear upgrade guide.

**What the API Toolkit will provide (not needed from the base package):**

- Discovery scanning logic (directory traversal, class filtering)
- Alias uniqueness validation and conflict reporting
- Caching of the discovery result
- Artisan commands for cache management and introspection
- Configuration for discovery paths

**Coordination requirement:** The base package release containing the alias mechanism must be published before or simultaneously with the API Toolkit release containing autodiscovery. The API Toolkit's `composer.json` must specify the minimum base package version that includes the alias capability.

**Precedent:** The existing `RESOURCE_TYPE` constant pattern on `ApiResource`/`ApiResourceInterface` demonstrates how typed metadata can be introduced in the toolkit layer. However, the alias mechanism is better placed in the base package because the `Repository` base class is where the class hierarchy starts, and placing it there avoids the fragmentation noted in Problem P16.

---

## Assumptions

- Breaking changes to the repository contract (requiring existing repositories to add an alias declaration) are acceptable, as confirmed in the intake brief
- The `repository_map` config key can be deprecated and eventually removed, with autodiscovery as the primary registration mechanism
- Repository classes in the consuming application follow a discoverable convention: they extend a known base class from the package's class hierarchy
- The consuming application's Composer autoloader is configured to autoload classes in the directories specified for discovery (PSR-4 or classmap)
- The number of repository classes in a typical application is in the range of 10-200, making filesystem scanning feasible in development without caching
- The base package maintainer (`sinemacula/laravel-repositories`) is willing and able to release a coordinated update

---

## Risks

| Risk                                            | Impact                                                                                                | Likelihood | Mitigation                                                                                                                                         |
|-------------------------------------------------|-------------------------------------------------------------------------------------------------------|------------|----------------------------------------------------------------------------------------------------------------------------------------------------|
| Breaking change migration friction              | Existing consumers must update every repository class to add an alias declaration before upgrading     | High       | Provide a clear upgrade guide with before/after examples. Consider a coexistence period (R17) where manual `repository_map` entries still work     |
| Cross-package release coordination failure      | Base package and toolkit releases must be synchronized; a gap could leave consumers unable to upgrade  | Medium     | Document exact version constraints. Release base package first. Test the full upgrade path in a sample application before publishing either package |
| Discovery performance in development            | Uncached discovery on every boot could slow Artisan commands and HTTP requests during development      | Medium     | Ensure the uncached discovery path is optimized for the expected scale (10-200 files). Monitor development boot time during implementation          |
| Composer autoloader inconsistency between envs  | Discovery relying on Composer classmap would produce different results in dev vs prod                  | Medium     | Discovery must use a mechanism that works consistently regardless of `composer dump-autoload --optimize` (addressed by R12)                        |
| Laravel Octane static property persistence      | The `RepositoryResolver::$map` static property persists across Octane worker requests                 | Low        | Verify that the discovered map is safe to persist across requests (it should be, as it is immutable after boot). Document Octane compatibility     |
| Alias naming collisions in modular applications | Modules developed independently may choose the same alias for different repositories                  | Medium     | Hard failure on duplicates (R9) makes collisions immediately visible. Actionable error messages (R10) guide resolution                              |

---

## Out of Scope

- **P17: Repositories not extending ApiRepository.** Repositories that extend the base `Repository` class directly (from `sinemacula/laravel-repositories`) without going through the API Toolkit's base repository are excluded from autodiscovery. This is an acceptable boundary because the API Toolkit's autodiscovery feature is scoped to API Toolkit consumers. If the base package later wants its own discovery mechanism, it can build one independently.
- **Automatic cache invalidation.** The discovery cache is not automatically invalidated when repository files change on disk. This is consistent with Laravel's own approach to event and route caching, where explicit commands are required during deployment. Developers working in development mode do not need to manage the cache because discovery runs uncached.
- **General-purpose class discovery.** This feature discovers repository classes specifically. It is not a general-purpose PHP class scanner or a replacement for packages like `spatie/php-structure-discoverer`.
- **IDE integration.** No IDE plugins, language server extensions, or editor tooling is included in this feature.
- **Resource map autodiscovery.** The `resource_map` config (mapping models to resources) is a separate concern and is not addressed by this PRD, even though it follows a similar manual registration pattern.

---

## Open Questions

None. All open questions from the intake brief and problem map have been resolved through spike research:

| Original Question | Resolution |
|---|---|
| "What is the best strategy for alias derivation?" (Intake Brief) | Explicit declaration is required as the primary mechanism because convention-based approaches diverge from developer intent in documented cases (Alias Derivation spike, Findings 4, 5). Every surveyed framework uses explicit declaration with optional convention fallback (Finding 9). The specific mechanism (constant vs. attribute) is an implementation decision. |
| "Should autodiscovery fully replace the `repository_map` config?" (Intake Brief) | Yes. Autodiscovery replaces manual registration as the primary mechanism. A coexistence mode (R17, P2) may be provided for migration but is not required for the initial release. |
| "How should discovery paths be configured?" (Intake Brief) | Via a config array of directories, following the pattern established by Laravel's event discovery and nwidart/laravel-modules (Modular Discovery spike, Finding 1). Paths are specified without opinionated normalization (Finding 1, nwidart issue #417). Default paths cover standard Laravel applications. |
| "Is caching the discovered repository map viable/necessary?" (Intake Brief) | Necessary for production, viable for implementation. Every examined framework and package treats discovery as a cached operation in production (Modular Discovery spike, Finding 10). Laravel's `event:cache` pattern provides a proven model. |
| "Should the package provide an Artisan command to list/debug discovered repositories?" (Intake Brief) | Yes, as a P1 requirement (R15). Every comparable Laravel subsystem provides introspection commands (Duplicate Detection spike, Finding 6). |

---

## Release Criteria

- All P0 requirements (R1-R11) pass their acceptance criteria in the test suite
- The package's existing test suite passes with no regressions
- PHPStan level 8 analysis passes with no new errors
- `composer check` passes cleanly
- An upgrade guide documents how to migrate from manual `repository_map` to autodiscovery, including: (a) how to add alias declarations to existing repositories, (b) how to configure discovery paths for modular apps, (c) how to remove the `repository_map` config, and (d) how to set up the cache command in deployment scripts
- The `sinemacula/laravel-repositories` companion release is published with the required alias mechanism (per the Cross-Package Specification)
- The `composer.json` version constraint for `sinemacula/laravel-repositories` is updated to require the minimum version containing the alias mechanism
- The package changelog documents the breaking changes clearly

---

## Traceability

| Artifact             | Path                                                                                                                                                                                                                                                                                                                                                               |
|----------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Intake Brief         | [.blueprint/workflows/repository-autodiscovery/intake-brief.md](.blueprint/workflows/repository-autodiscovery/intake-brief.md)                                                                                                                                                                                                                                    |
| Relevant Spikes      | [spike-alias-derivation.md](.blueprint/workflows/repository-autodiscovery/spikes/spike-alias-derivation.md), [spike-laravel-autodiscovery.md](.blueprint/workflows/repository-autodiscovery/spikes/spike-laravel-autodiscovery.md), [spike-modular-discovery.md](.blueprint/workflows/repository-autodiscovery/spikes/spike-modular-discovery.md), [spike-duplicate-detection.md](.blueprint/workflows/repository-autodiscovery/spikes/spike-duplicate-detection.md) |
| Problem Map          | [.blueprint/workflows/repository-autodiscovery/problem-map.md](.blueprint/workflows/repository-autodiscovery/problem-map.md) -- All 5 clusters: Manual Registration Burden (P1, P2, P3), Alias Identity and Predictability (P4, P5, P6), Discovery Across Non-Standard Directory Structures (P7, P8), Performance and Production Readiness (P9), Conflict Detection and Debugging (P12, P13) |
| Prioritization Entry | [.blueprint/workflows/repository-autodiscovery/prioritization.md](.blueprint/workflows/repository-autodiscovery/prioritization.md) -- P0 Tier: Rank 1 (P1, score 9), Rank 2 (P7, score 8), Rank 3 (P9, score 8), Rank 4 (P6, score 8), Rank 5 (P12, score 9), Rank 6 (P4, score 8), Rank 7 (P5, score 7), Rank 8 (P8, score 7), Rank 9 (P3, score 8), Rank 10 (P2, score 8), Rank 13 (P13, score 7). P1 Tier: Ranks 11-12 (P10, P11, score 6), Rank 14 (P16, score 6), Rank 15 (P14, score 6), Rank 16 (P15, score 5) |

---

## References

- Traces to: [Prioritization](.blueprint/workflows/repository-autodiscovery/prioritization.md) -- All P0 and P1 ranked problems
- Intake Brief: [.blueprint/workflows/repository-autodiscovery/intake-brief.md](.blueprint/workflows/repository-autodiscovery/intake-brief.md)
- Current source: `src/Repositories/RepositoryResolver.php`, `src/Repositories/ApiRepository.php`, `src/Repositories/Traits/HasRepositories.php`, `config/api-toolkit.php`, `src/ApiServiceProvider.php`
