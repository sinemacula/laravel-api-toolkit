# Task 01: Emitter

Create the `Emitter` class for structured SSE event emission with wire-format abstraction, add the `Sse` namespace block to the function overrides file, and create comprehensive tests.

---

## Governance

| Field        | Value                                                                                           |
|--------------|-------------------------------------------------------------------------------------------------|
| Created      | 2026-03-10                                                                                      |
| Status       | draft                                                                                           |
| Owned by     | Developer                                                                                       |
| Traces to    | [Architecture](../.sinemacula/build/workflows/sse-stream-extraction/architecture.md)            |
| Task Number  | 01                                                                                              |
| Tier         | 1                                                                                               |
| Dependencies | none                                                                                            |

---

## Objective

Create the `Emitter` class that provides a structured API for emitting SSE events without callers needing to construct wire-format strings, add all `Sse` namespace function overrides needed for testing, and verify wire-format correctness through unit tests.

---

## Scope

### Files to Create

| Path                            | Component | Description                                                              |
|---------------------------------|-----------|--------------------------------------------------------------------------|
| `src/Sse/Emitter.php`          | Emitter   | Structured SSE event emitter with `emit()` and `comment()` methods       |
| `tests/Unit/Sse/EmitterTest.php` | Emitter | Tests for wire-format correctness, multiline splitting, named events     |

### Files to Modify

| Path                                     | Component | Description of Changes                                                                                             |
|------------------------------------------|-----------|---------------------------------------------------------------------------------------------------------------------|
| `tests/Fixtures/Overrides/functions.php` | Overrides | Add a `SineMacula\ApiToolkit\Sse` namespace block with overrides for `connection_aborted`, `sleep`, `flush`, `ob_flush`, and `ob_get_level` |

---

## Specification

### Emitter Class

**Namespace:** `SineMacula\ApiToolkit\Sse`

**Class declaration:** `final class Emitter` -- concrete, not abstract. No constructor needed (stateless).

**Method: `emit(string|array $data, ?string $event = null): void`**

1. When `$event` is non-null, write `"event: {$event}\n"` to output.
2. When `$data` is an array, JSON-encode it into a single string. Then process as string data.
3. When `$data` is a string, split on `"\n"` and write each line as a separate `"data: {$line}\n"` line per the SSE specification.
4. Write a terminating `"\n"` (producing the required blank line that ends an SSE event block -- the last `data:` line already has its own `"\n"`, so one more produces `"\n\n"`).
5. Call `flush()` (the unqualified call, so it resolves within the `Sse` namespace for testability).

**Method: `comment(string $text = ''): void`**

1. Write `":{$text}\n\n"` to output (SSE comment line with blank line terminator).
2. Call `flush()`.

**Wire-format examples:**

- `emit('hello')` produces: `"data: hello\n\n"` followed by flush
- `emit('hello', 'greeting')` produces: `"event: greeting\ndata: hello\n\n"` followed by flush
- `emit("line1\nline2")` produces: `"data: line1\ndata: line2\n\n"` followed by flush
- `emit(['key' => 'value'])` produces: `"data: {\"key\":\"value\"}\n\n"` followed by flush
- `comment()` produces: `":\n\n"` followed by flush
- `comment(' keep-alive')` produces: `": keep-alive\n\n"` followed by flush

**Docblocks:**

- Class docblock: describe it as the structured SSE event emitter. Include a note that `flush()` behaviour depends on the PHP SAPI -- under PHP-FPM, output buffering layers may require additional configuration (e.g., disabling `output_buffering` or using `ob_implicit_flush`). Include `@author` and `@copyright` tags.
- `emit()` docblock: describe parameters, note multiline splitting behaviour, `@param`, `@return void`.
- `comment()` docblock: describe usage for keep-alive signals, `@param`, `@return void`.

### Function Overrides Modification

**File:** `tests/Fixtures/Overrides/functions.php`

Add a new namespace block at the end of the file:

```
namespace SineMacula\ApiToolkit\Sse;
```

This block must contain overrides for ALL built-in functions called (unqualified) from classes in the `Sse` namespace. Based on the architecture, both `Emitter` and `EventStream` will call the following functions:

- `flush(): void` -- called by `Emitter::emit()` and `Emitter::comment()`, and by `EventStream` internally
- `connection_aborted(): int` -- called by `EventStream` in the polling loop
- `sleep(int $seconds): int` -- called by `EventStream` at the end of each iteration
- `ob_flush(): void` -- called by `EventStream` to flush output buffers
- `ob_get_level(): int` -- called by `EventStream` to check output buffer depth

Each override follows the exact same pattern as the existing `Http\Routing` namespace block: delegate to `FunctionOverrides::get($name)`, call the override if present, fall back to the real built-in (prefixed with `\`) otherwise.

The `use` statement at the top of the new namespace block must import `Tests\Fixtures\Support\FunctionOverrides`.

**Important:** The existing `Http\Routing` namespace block must be preserved unchanged. The existing controller tests use those overrides during the transition period (the controller delegates to `EventStream`, but the existing tests still exercise the controller, and the Sse namespace overrides will intercept the calls since the actual execution happens in the `Sse` namespace after extraction). However, the `Http\Routing` block may be needed for any built-in calls that remain directly in the controller namespace (if any).

Examining the current controller code after extraction: the controller's `respondWithEventStream()` will only instantiate `EventStream` and call `toResponse()`. It will no longer call `connection_aborted`, `sleep`, `flush`, `ob_flush`, or `ob_get_level` directly. Those calls move to `EventStream`. So the `Http\Routing` namespace overrides for `connection_aborted`, `sleep`, `ob_flush`, and `flush` will become dead code after Task 03 completes. However, they must remain in place during Task 01 and Task 02 to keep existing controller tests passing (the controller still has the old implementation until Task 03). Do NOT remove the existing `Http\Routing` namespace block in this task. That cleanup would be a separate concern outside this workflow's scope.

**SuppressWarnings annotations:** Follow the existing pattern. `ob_flush` and `ob_get_level` functions use `@SuppressWarnings("php:S100")` for the underscore naming. Functions that duplicate bodies from other namespace blocks use `@SuppressWarnings("php:S4144")`. Apply both suppressions where applicable to match the existing pattern. The `ob_get_level` override is new and needs `@SuppressWarnings("php:S100")` plus `@SuppressWarnings("php:S4144")` since it duplicates the pattern.

### Existing Patterns to Follow

**Override function pattern** (from the existing file):

```php
function flush(): void
{
    $override = FunctionOverrides::get('flush');

    if ($override !== null) {
        $override();
        return;
    }

    \flush();
}
```

For functions returning `int` (e.g., `connection_aborted`, `sleep`):

```php
function connection_aborted(): int
{
    $override = FunctionOverrides::get('connection_aborted');

    if ($override !== null) {
        /** @phpstan-ignore cast.int */
        return (int) $override();
    }

    return \connection_aborted();
}
```

**Test file patterns** (from `tests/Unit/Http/Routing/ControllerTest.php`):

- Extend `Tests\TestCase` (which provides `FunctionOverrides::reset()` in `tearDown()`)
- Use `#[CoversClass(ClassName::class)]` attribute on the test class
- Use `@author` and `@copyright` tags plus `@internal` in the test class docblock
- Test method names: `test{DescriptionInCamelCase}`
- Use `static::assertSame()`, `static::assertStringContainsString()`, etc.
- Use `ob_start()` / `ob_get_clean()` to capture output from SSE emission
- Set `FunctionOverrides::set('flush', fn () => null)` to suppress real flush calls during tests

---

## Test Expectations

| Test                                                    | Type | Description                                                                                   |
|---------------------------------------------------------|------|-----------------------------------------------------------------------------------------------|
| `testEmitWritesSingleLineDataWithTerminator`            | Unit | `emit('hello')` produces `"data: hello\n\n"` captured via `ob_start`/`ob_get_clean`          |
| `testEmitWritesNamedEventBeforeData`                    | Unit | `emit('hello', 'greeting')` produces `"event: greeting\ndata: hello\n\n"`                    |
| `testEmitSplitsMultilineDataIntoSeparateDataLines`      | Unit | `emit("line1\nline2")` produces `"data: line1\ndata: line2\n\n"`                             |
| `testEmitJsonEncodesArrayData`                          | Unit | `emit(['key' => 'value'])` produces `"data: {\"key\":\"value\"}\n\n"`                        |
| `testEmitJsonEncodesArrayDataWithNamedEvent`            | Unit | `emit(['k' => 'v'], 'update')` produces `"event: update\ndata: {\"k\":\"v\"}\n\n"`          |
| `testCommentWritesEmptyCommentLine`                     | Unit | `comment()` produces `":\n\n"`                                                                |
| `testCommentWritesTextCommentLine`                      | Unit | `comment(' keep-alive')` produces `": keep-alive\n\n"`                                       |
| `testEmitCallsFlush`                                    | Unit | Override `flush` via `FunctionOverrides::set`; assert it was called after `emit()`            |
| `testCommentCallsFlush`                                 | Unit | Override `flush` via `FunctionOverrides::set`; assert it was called after `comment()`         |

---

## Acceptance Criteria

| ID      | Criterion                                                                                                                                                      | Traces to |
|---------|----------------------------------------------------------------------------------------------------------------------------------------------------------------|-----------|
| AC-01-1 | `Emitter::emit()` produces correct SSE wire-format for single-line string data                                                                                 | REQ-02    |
| AC-01-2 | `Emitter::emit()` produces correct SSE wire-format with a named event type                                                                                     | REQ-02    |
| AC-01-3 | `Emitter::emit()` splits multiline string data into multiple `data:` lines per the SSE specification                                                           | REQ-02    |
| AC-01-4 | `Emitter::emit()` JSON-encodes array data before emitting                                                                                                      | REQ-02    |
| AC-01-5 | `Emitter::comment()` produces a valid SSE comment line                                                                                                         | REQ-02    |
| AC-01-6 | Both `emit()` and `comment()` call `flush()` after writing                                                                                                     | REQ-02    |
| AC-01-7 | The `Sse` namespace block in the overrides file contains stubs for `connection_aborted`, `sleep`, `flush`, `ob_flush`, and `ob_get_level`                      | REQ-08    |
| AC-01-8 | Existing tests in `ControllerTest` continue to pass without modification                                                                                       | NFR-02    |
| AC-01-9 | All new code passes PHPStan level 8 (`composer check`)                                                                                                         | NFR-01    |
| AC-01-10 | Class and method docblocks include SAPI-specific considerations for output buffering                                                                           | REQ-07    |

---

## Language Pack Rules

### Naming

- Names descriptive and unambiguous {#php-nam-001}
- Directory context replaces prefixes -- `Sse/Emitter` not `Sse/SseEmitter` {#php-nam-007}
- One class per file {#php-nam-030}
- Namespace mirrors directory: `SineMacula\ApiToolkit\Sse` maps to `src/Sse/` {#php-nam-031}
- Test file: `EmitterTest.php` {#php-nam-011}

### Structure

- Single-line method signatures by default {#php-str-001}
- Simple control blocks: no blank line padding {#php-str-007}
- Group related prep statements {#php-str-009}

### Documentation

- Class docblock with `@author` and `@copyright` tags {#php-doc-009, #php-doc-030}
- `@param`, `@return`, `@throws` on all methods {#php-doc-010}
- Fully qualified types in docblocks {#php-doc-005}
- Author: `Ben Carey <bdmc@sinemacula.co.uk>`, Copyright: `2026 Sine Macula Limited.`

### Testing

- Extend `Tests\TestCase` base class
- Use `#[CoversClass(Emitter::class)]` attribute {#php-tst-016}
- Test method names: `test{DescriptionInCamelCase}` {#php-tst-010}
- Each test asserts one logical concept {#php-tst-004}
- Use `static::assertSame()` (existing codebase convention)
- Mark test class docblock with `@internal`

---

## References

- Traces to: [Architecture](../.sinemacula/build/workflows/sse-stream-extraction/architecture.md)
- Spec Extract: [Spec](../.sinemacula/build/workflows/sse-stream-extraction/spec.md)
