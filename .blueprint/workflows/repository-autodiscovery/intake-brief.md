# Intake Brief: Repository Autodiscovery

Eliminate manual repository registration by autodiscovering repository classes and resolving their aliases automatically.

---

## Governance

| Field     | Value     |
|-----------|-----------|
| Created   | 2026-02-26 |
| Status    | draft     |
| Owned by  | Ben       |
| Traces to | User idea |

---

## Raw Idea

At the moment, we have to manually register all repositories in the config file in our local application - this is a nightmare to maintain on large Laravel applications. As such, I would like this package to autodiscover the repositories and automatically register them using the relevant alias. At the moment, we do something like this: 'repository_map' => [
    'users'                        => UserRepository::class,
    'activity'                     => ActivityRepository::class,
    'addresses'                    => AddressRepository::class,
    'factors'                      => AuthFactorRepository::class,
] - we will need to come up with a smart way to either dynamically determine an appropriate alias (not sure what options we have there - maybe a Laravel helper method can assist), or we explcitly require each repository to define it's alias - obviously the resolver will need to check for duplicates and hard fail if any are found. Note that we use a modularised implementation of Laravel - it is not the standard Laravel with an app/ folder. Instead, we have modules/ and in modules each module is treated like an app folder. This is relevant for 2 reasons - 1, there are logical reasons why having to repositories with the same alias is actually possible, hence we need to hard fail (same repository across modules), and b) the auto discovery will need to somehow discover the repositories within each module, not just within the app/ directory (as that doesn't exist). Our Laravel app DOES expose helper methods such as module_path but I don't want to use this because this is not relevant or available to this repository. Either we need a way to provide a config/parameter to the auto discoverer, or we need it to just intelligently and efficiently traverse the filesystem and find the Repositories - it's importat that this is efficient so if the latter solution is not good then we will need to use the former or some variant of it, unless you can come up with a more graceful idea

---

## Problem Signal

**Who has this problem:** Developers using `sinemacula/laravel-api-toolkit` in large Laravel applications, particularly those with modular architectures (multiple modules instead of a single `app/` directory).

**What is the problem:** Every repository class must be manually registered in the `repository_map` config array with an explicit alias. As the application grows (more modules, more models, more repositories), this map becomes a maintenance burden — easy to forget entries, prone to stale references, and tedious to keep in sync.

**Why it matters:** On large applications with dozens or hundreds of repositories spread across multiple modules, the manual map is a constant source of friction. Missed registrations cause runtime errors, and the config file becomes unwieldy. This slows down development and increases onboarding cost for new team members.

**Current alternatives:** Developers manually maintain the `repository_map` array in the published `api-toolkit.php` config file, adding each new repository by hand as it is created.

---

## Context

**Domain:** Laravel package development — service layer / repository pattern infrastructure.

**Business context:** This is a shared open-source package used across multiple Sine Macula projects. The solution must work for any consuming Laravel application, not just those with a specific directory structure.

**Constraints:**
- The package cannot depend on host-application helpers like `module_path()` — the solution must be self-contained within the package
- Must support non-standard Laravel directory structures (modular apps without `app/`)
- Repository alias uniqueness must be enforced — duplicate aliases across modules must cause a hard failure
- Discovery must be efficient enough for production boot performance (runs on every request during service provider registration)
- Must remain backwards-compatible with the existing manual `repository_map` config approach
- PHP ^8.3 minimum

**Assumptions:**
- The existing `repository_map` config will continue to work (opt-in autodiscovery, not forced migration)
- Repository classes follow a discoverable convention (e.g., extend a known base class or implement a known interface)
- The consuming application can provide discovery paths or the package can be configured to know where to look

---

## Success Signals

| Signal                        | Description                                                                    |
|-------------------------------|--------------------------------------------------------------------------------|
| Zero-config for standard apps | A standard Laravel app with repositories in `app/` should work without config  |
| Configurable for modular apps | Modular apps can specify discovery paths and it just works                      |
| Hard fail on duplicates       | Two repositories claiming the same alias produces a clear, actionable error     |
| No performance regression     | Discovery adds negligible overhead to application boot time                     |
| Backwards compatible          | Existing `repository_map` config continues to work; manual entries take priority |

---

## Open Questions

- What is the best strategy for alias derivation — should each repository define its own alias (explicit), or should it be derived from the model/class name (convention-based), or both?
- Should autodiscovery be opt-in (explicit config flag) or on-by-default with the ability to disable?
- Should manually registered repositories in `repository_map` override or coexist with autodiscovered ones?
- How should discovery paths be configured — flat array of directories, or a more structured approach?
- Is caching the discovered repository map viable/necessary for production performance?
- Should the package provide an Artisan command to list/debug discovered repositories?

---

## Research Seeds

| Topic                          | Question                                                                                                     | Priority |
|--------------------------------|--------------------------------------------------------------------------------------------------------------|----------|
| Alias derivation strategies    | What approaches exist for deriving a repository alias from the class (model name, table name, explicit attribute/constant, interface method)? What are the trade-offs of each? | high     |
| Laravel autodiscovery patterns | How do Laravel and popular packages handle autodiscovery (e.g., event listeners, policies, Blade components)? What patterns and APIs are available? | high     |
| Discovery in modular apps      | How can the package discover classes in arbitrary directory paths without depending on host-app helpers? What are the performance implications of filesystem traversal vs. Composer classmap vs. reflection? | high     |
| Duplicate detection            | What is the best UX for reporting duplicate alias conflicts — exception message format, which classes conflict, which modules they belong to? | medium   |

---

## References

- Source: User idea (captured 2026-02-26)
