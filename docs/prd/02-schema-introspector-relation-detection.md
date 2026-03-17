# PRD: SchemaIntrospector Relation Detection

Replace `SchemaIntrospector::isRelation()` runtime method invocation with return-type inspection via `ReflectionMethod`,
add dynamic relation detection, and remove the silent failure mode -- eliminating unnecessary method invocation,
side-effect risks, and hidden errors.

---

## Governance

| Field     | Value                                                                                                                                |
|-----------|--------------------------------------------------------------------------------------------------------------------------------------|
| Created   | 2026-03-16                                                                                                                           |
| Status    | approved                                                                                                                             |
| Owned by  | Product Analyst                                                                                                                      |
| Traces to | [Prioritization](../../.sinemacula/blueprint/workflows/schema-introspector-relation-detection/prioritization.md)                     |
| Problems  | P1 (invocation-based detection), P3 (silent exception catching), P5 (dynamic relation blindness), P6 (detection/resolution coupling) |

---

## Background
 
`SchemaIntrospector::isRelation()` determines whether a key corresponds to an Eloquent relation on a model. It is called
by `FilterApplier` during filter tree traversal (for every unrecognized key) and indirectly by `AttributeSetter` via
`resolveRelation()`.

The current implementation invokes `$model->{$key}()` at runtime and checks if the result is `instanceof Relation`.
This approach:

- **Constructs a full Relation query builder** (joins, constraints, scopes) when only a boolean answer is needed
- **Risks invoking non-relation methods** that happen to share a name with a filter key
- **Silently catches `LogicException` and `ReflectionException`**, hiding broken relation definitions behind a log
  message and `return false`
- **Cannot detect dynamically registered relations** (`Model::resolveRelationUsing()`) because `method_exists()` returns
  `false` for them

Results are cached via `Cache::memo()->rememberForever()` per (model, key) pair, mitigating repeated cost within a
request but not the first-call cost or correctness issues.

PHP 8.3's `ReflectionMethod` API can determine the return type of a method without invoking it, providing a faster,
safer, and more correct detection path. The package already requires PHP ^8.3.

This is a v2 change. Breaking changes to the detection contract are acceptable.

---

## User Capabilities

### UC-1: Relation detection uses return-type inspection instead of method invocation

When a developer sends a request with filter keys, the `FilterApplier` determines whether each key is a relation using
return-type inspection rather than invoking the method on the model.

**Acceptance criteria:**

- `isRelation()` uses `ReflectionMethod::getReturnType()` to check if the method's return type is a subclass of
  `Illuminate\Database\Eloquent\Relations\Relation`
- No model method is invoked during `isRelation()` -- only the return type is inspected
- Standard relation types are correctly detected: `HasOne`, `HasMany`, `BelongsTo`, `BelongsToMany`, `MorphTo`,
  `MorphMany`, `MorphToMany`, `MorphOne`, `HasOneThrough`, `HasManyThrough`
- Union return types (e.g., `HasMany|MorphMany`) are correctly handled: if any member type is a Relation subclass, the
  method is detected as a relation
- Methods without a return type hint that is a Relation subclass are not detected as relations. There is no invocation
  fallback -- return type hints are required
- Methods with non-Relation return types (e.g., `Builder`, `void`, `string`) are correctly identified as non-relations
  without invocation
- The memo cache is retained for `isRelation()` results, keyed by `(model_class, key)`, to avoid repeated reflection
- `isRelation()` has no exception handling -- reflection does not invoke the method, so there are no exceptions to catch

### UC-2: Dynamically registered relations are detected

When a developer registers a relation via `Model::resolveRelationUsing()`, the introspector detects it as a valid
relation.

**Acceptance criteria:**

- `isRelation()` checks `Model::$relationResolvers[$model::class][$key]` as an additional detection path
- Dynamic relations are detected even though `method_exists()` returns `false` for them
- Dynamic relations work with `FilterApplier` (filter keys are recognized as relations)
- Dynamic relations work with `resolveRelation()` (the dynamic resolver is invoked to return the Relation instance)
- The detection result is cached via the same memo cache as static relations

### UC-3: resolveRelation() surfaces errors instead of hiding them

When a relation method has a correct return type hint but throws when invoked (via `resolveRelation()`), the error is
handled deliberately rather than silently swallowed.

**Acceptance criteria:**

- `resolveRelation()` invokes the method only when `isRelation()` has already confirmed it is a relation (via return
  type). This means invocation only happens when the caller genuinely needs the Relation instance
- `resolveRelation()` catches `LogicException` and returns `null` (method exists and is typed as a relation but cannot
  produce one at runtime -- e.g., a MorphTo without a morph type set)
- `resolveRelation()` catches `ReflectionException` and returns `null`
- Both cases log at `warning` level with context: relation name, model class, exception message
- Generic exceptions (e.g., `RuntimeException`) are not caught -- they propagate to the caller

### UC-4: Schema validation catches missing return type hints at boot time

When a model's relation method (declared via `Relation::to()` in a resource schema) lacks a return type hint, the
schema validation rule reports it as an error at boot time.

**Acceptance criteria:**

- `ValidateRelationMethods` checks that each relation method has a return type hint that is a subclass of `Relation`
- Methods without return type hints are reported as validation errors (not warnings -- this is a v2 requirement)
- Methods with return type hints that are not Relation subclasses are reported as validation errors
- The error message identifies the resource class, field key, method name, and model class

---

## Out of Scope

- **Schema-driven detection:** Using `ApiResource::schema()` as the source of truth for relation detection would create
  cross-layer coupling (repository layer depending on HTTP resource layer). Deferred.
- **Invocation fallback for untyped methods:** v2 requires return type hints. There is no fallback to runtime
  invocation.
- **Performance benchmarking:** The qualitative improvement (no query builder construction) is sufficient justification.

---

## Modified Classes

| Class                     | Change                                                                                                                                                                                                                                                                                                             |
|---------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `SchemaIntrospector`      | Replace `isRelation()` internals: use `ReflectionMethod::getReturnType()` for detection, add `Model::$relationResolvers` check for dynamic relations, remove exception catch block entirely. Narrow `resolveRelation()` exception handling to `LogicException`/`ReflectionException` only, log at `warning` level. |
| `ValidateRelationMethods` | Extend to validate return type hints: report missing or non-Relation return types as errors.                                                                                                                                                                                                                       |

---

## Success Metrics

| Metric                                    | Baseline                                      | Target                                | Measurement                                                   |
|-------------------------------------------|-----------------------------------------------|---------------------------------------|---------------------------------------------------------------|
| Method invocations during isRelation()    | 1 per unique (model, key) pair                | 0                                     | Code inspection: no `$model->{$key}()` call in `isRelation()` |
| Dynamic relation detection                | 0% (method_exists fails)                      | 100% for `$relationResolvers` entries | Test: dynamic relation returns true from isRelation()         |
| Silent false returns from exceptions      | Unknown count (errors caught, false returned) | 0 (no catch block in isRelation)      | Code inspection: no catch block in `isRelation()`             |
| Boot-time detection of missing type hints | 0%                                            | 100% via ValidateRelationMethods      | Test: validation error reported for untyped relation methods  |

---

## Testing Strategy

- **Unit tests for reflection-based detection:**
    - Method with `HasMany` return type -> `true`
    - Method with `BelongsTo` return type -> `true`
    - Method with `MorphTo` return type -> `true`
    - Method with `MorphToMany` return type -> `true`
    - Method with union type `HasMany|MorphMany` -> `true`
    - Method with `Builder` return type -> `false`
    - Method with `void` return type -> `false`
    - Method with `string` return type -> `false`
    - Method with no return type -> `false` (no fallback)
    - Non-existent method -> `false`
- **Unit tests for dynamic relation detection:**
    - Relation registered via `resolveRelationUsing()` -> `isRelation()` returns `true`
    - Dynamic relation works with `resolveRelation()` -> returns Relation instance
- **Unit tests for error handling:**
    - `isRelation()`: no catch block, no exceptions (reflection only)
    - `resolveRelation()`: `LogicException` caught, returns `null`, logs warning
    - `resolveRelation()`: `ReflectionException` caught, returns `null`, logs warning
    - `resolveRelation()`: `RuntimeException` not caught, propagates
- **Unit tests for ValidateRelationMethods:**
    - Relation method with correct return type -> no error
    - Relation method with no return type -> validation error
    - Relation method with non-Relation return type -> validation error
- **Integration tests:**
    - `FilterApplier` correctly identifies relation filter keys using reflection
    - `AttributeSetter` correctly resolves relation types via `resolveRelation()`
- **Existing test updates:** Tests that assert fallback invocation behavior or exception catching in `isRelation()` are
  updated to reflect the new reflection-only approach

---

## References

- Prioritization: .sinemacula/blueprint/workflows/schema-introspector-relation-detection/prioritization.md
- Problem Map: .sinemacula/blueprint/workflows/schema-introspector-relation-detection/problem-map.md
- Spike: .sinemacula/blueprint/workflows/schema-introspector-relation-detection/spikes/spike-detection-strategies.md
- Intake Brief: .sinemacula/blueprint/workflows/schema-introspector-relation-detection/intake-brief.md
- Source: ISSUES.md (ISSUE-07)
