# Knowledge Artifact: sse-stream-extraction

Accumulated codebase knowledge for this workflow run.

---

## Governance

| Field     | Value                                                            |
|-----------|------------------------------------------------------------------|
| Created   | 2026-03-10                                                       |
| Status    | active                                                           |
| Owned by  | Orchestrator                                                     |
| Traces to | .sinemacula/build/workflows/sse-stream-extraction/               |

---

## Stable Patterns

Durable codebase characteristics discovered during this workflow. Entries are seeded by the architect during
architecture analysis and may be enriched by subsequent subagents. Check for duplicates before adding new entries.

### Namespace-Scoped Function Overrides for Testing

| Field | Value |
|-------|-------|
| Source | architect / architecture |
| Discovered | 2026-03-10 |

Tests intercept PHP built-in functions (`connection_aborted`, `sleep`, `flush`, `ob_flush`) via namespace-scoped overrides declared in `tests/Fixtures/Overrides/functions.php`. Each override delegates to `FunctionOverrides::get()` and falls back to the real built-in. The override file is loaded by `tests/bootstrap.php` before any tests run. When adding code that calls built-ins in a new namespace, a corresponding namespace block must be added to the override file.

**Files:** `tests/Fixtures/Overrides/functions.php`, `tests/Fixtures/Support/FunctionOverrides.php`, `tests/bootstrap.php`

### Test Fixture Controllers

| Field | Value |
|-------|-------|
| Source | architect / architecture |
| Discovered | 2026-03-10 |

Controller tests use minimal fixture subclasses in `tests/Fixtures/Controllers/` rather than anonymous classes. The `TestingController` fixture extends the base `Controller` and adds no extra behaviour, serving as a concrete instantiation target. Tests access protected methods via the `InteractsWithNonPublicMembers` trait which provides `invokeMethod()` reflection helper.

**Files:** `tests/Fixtures/Controllers/TestingController.php`, `tests/Concerns/InteractsWithNonPublicMembers.php`, `tests/Unit/Http/Routing/ControllerTest.php`

### Contracts Directory for Interfaces

| Field | Value |
|-------|-------|
| Source | architect / architecture |
| Discovered | 2026-03-10 |

The project places interfaces in `src/Contracts/` at the package root level (e.g., `ApiResourceInterface`, `ErrorCodeInterface`). There are no domain-specific contract subdirectories. New interfaces should follow the `{Name}Interface` suffix convention and be placed in `src/Contracts/`.

**Files:** `src/Contracts/ApiResourceInterface.php`, `src/Contracts/ErrorCodeInterface.php`

### Source Directory Conventions

| Field | Value |
|-------|-------|
| Source | architect / architecture |
| Discovered | 2026-03-10 |

Source code is organised by domain under `src/`: `Http/` (controllers, middleware, resources, concerns), `Enums/`, `Exceptions/`, `Repositories/`, `Services/`, `Traits/`, `Facades/`. Concerns (traits used by controllers) live in `Http/Concerns/`. Test directory structure mirrors source: `tests/Unit/Http/Routing/ControllerTest.php` tests `src/Http/Routing/Controller.php`. New domain directories (e.g., `src/Sse/`) follow the same flat namespace structure.

**Files:** `src/Http/Routing/Controller.php`, `src/Http/Concerns/RespondsWithStream.php`, `src/Enums/HttpStatus.php`

### FunctionOverrides Reset in tearDown

| Field | Value |
|-------|-------|
| Source | architect / architecture |
| Discovered | 2026-03-10 |

The base `TestCase` class calls `FunctionOverrides::reset()` in `tearDown()` to clear all registered overrides between tests. Individual tests set overrides via `FunctionOverrides::set()` without needing manual cleanup. New tests that use function overrides inherit this automatic reset behaviour.

**Files:** `tests/TestCase.php`, `tests/Fixtures/Support/FunctionOverrides.php`

### SuppressWarnings Annotations on Override Functions

| Field | Value |
|-------|-------|
| Source | architect / tasks |
| Discovered | 2026-03-10 |

Namespace-scoped function overrides in `tests/Fixtures/Overrides/functions.php` use `@SuppressWarnings` annotations for two cases: `"php:S100"` for functions with underscore naming (e.g., `ob_flush`, `ob_get_level`) and `"php:S4144"` for functions whose bodies duplicate another namespace block's implementation. When a function triggers both suppressions, both annotations are included. See the existing `ob_flush` and `flush` overrides in the `Http\Routing` namespace block.

**Files:** `tests/Fixtures/Overrides/functions.php`

### Response Construction Without Facades

| Field | Value |
|-------|-------|
| Source | architect / tasks |
| Discovered | 2026-03-10 |

The controller currently uses `Response::stream()` (Laravel facade) to create `StreamedResponse` instances. The extracted `EventStream` class must NOT use facades -- it instantiates `Symfony\Component\HttpFoundation\StreamedResponse` directly to satisfy the requirement of being usable from any PHP context without framework dependency. The controller continues to use the `Response` facade for its non-SSE methods (`respondWithData`).

**Files:** `src/Http/Routing/Controller.php`, `src/Sse/EventStream.php`

---

## Per-Task Learnings

Task-specific discoveries recorded after each task completes. Each entry captures patterns discovered, files found
relevant, conventions followed, and decisions made during implementation or review.

### Task 01: Emitter

| Field | Value |
|-------|-------|
| Source | developer / task-01 |
| Completed | 2026-03-10 |

**Patterns discovered:** The Emitter class is stateless and needs no constructor -- all SSE formatting is pure output. The `flush()` call must be unqualified so it resolves to the namespace-scoped override during tests; this is the same pattern used in the Controller's `runEventStream` method. The `Sse` namespace override block needs all five functions (`connection_aborted`, `sleep`, `flush`, `ob_flush`, `ob_get_level`) even though only `flush` is used by the Emitter, because the override file is loaded once for the entire namespace and `EventStream` (Task 02) will need the rest.

**Relevant files:** `tests/Fixtures/Overrides/functions.php`, `tests/Fixtures/Support/FunctionOverrides.php`, `tests/TestCase.php`, `src/Http/Routing/Controller.php`

**Conventions followed:** Test setUp suppresses flush via `FunctionOverrides::set('flush', fn () => null)` to prevent real flush calls during output capture, matching the ControllerTest pattern. Used `ob_start()`/`ob_get_clean()` for output capture. The formatter reorders union types alphabetically (`array|string` not `string|array`).

**Decisions made:** Set the flush override in `setUp()` rather than in each test method since every test needs it (output capture would fail otherwise). The two flush-verification tests override again with a tracking closure, which works because `FunctionOverrides::set` replaces the previous override.
