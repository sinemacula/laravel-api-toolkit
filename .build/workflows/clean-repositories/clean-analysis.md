# Clean Analysis: Repositories

Analysis of 8 PHP files in `src/Repositories/` against the PHP language pack standards.

---

## Governance

| Field     | Value                                      |
|-----------|--------------------------------------------|
| Created   | 2026-02-26                                 |
| Status    | draft                                      |
| Owned by  | Reviewer                                   |
| Traces to | Scope: `src/Repositories/` (8 PHP files)   |

---

## Summary

| Category      | Issues | High | Medium | Low |
|---------------|--------|------|--------|-----|
| Naming        | 21     | 0    | 21     | 0   |
| Complexity    | 4      | 0    | 4      | 0   |
| Structure     | 3      | 0    | 3      | 0   |
| Tests         | 0      | 0    | 0      | 0   |
| Style         | 1      | 1    | 0      | 0   |
| Debt          | 4      | 0    | 1      | 3   |
| Documentation | 13     | 0    | 11     | 2   |
| **Total**     | **46** | **1**| **40** | **5**|

---

## Naming Issues

| # | File | Line | Current Name | Expected Name | Rule Violated |
|---|------|------|--------------|---------------|---------------|
| 1 | `src/Repositories/ApiRepository.php` | 43 | `$resource_class` | `$resourceClass` | naming.md: Method parameters use camelCase |
| 2 | `src/Repositories/ApiRepository.php` | 124 | `$sync_attributes` | `$syncAttributes` | naming.md: Variables use camelCase |
| 3 | `src/Repositories/ApiRepository.php` | 235 | `$native_cast` | `$nativeCast` | naming.md: Variables use camelCase |
| 4 | `src/Repositories/ApiRepository.php` | 236 | `$laravel_casts` | `$laravelCasts` | naming.md: Variables use camelCase |
| 5 | `src/Repositories/ApiRepository.php` | 237 | `$laravel_cast` | `$laravelCast` | naming.md: Variables use camelCase |
| 6 | `src/Repositories/ApiRepository.php` | 317 | `$laravel_cast` (param) | `$laravelCast` | naming.md: Method parameters use camelCase |
| 7 | `src/Repositories/ApiRepository.php` | 322 | `$base_cast` | `$baseCast` | naming.md: Variables use camelCase |
| 8 | `src/Repositories/Criteria/ApiCriteria.php` | 116 | `$last_logical_operator` (param) | `$lastLogicalOperator` | naming.md: Method parameters use camelCase |
| 9 | `src/Repositories/Criteria/ApiCriteria.php` | 174 | `$requested_counts` | `$requestedCounts` | naming.md: Variables use camelCase |
| 10 | `src/Repositories/Criteria/ApiCriteria.php` | 175 | `$with_counts` | `$withCounts` | naming.md: Variables use camelCase |
| 11 | `src/Repositories/Criteria/ApiCriteria.php` | 281 | `$logical_operator` (param) | `$logicalOperator` | naming.md: Method parameters use camelCase |
| 12 | `src/Repositories/Criteria/ApiCriteria.php` | 301 | `$last_logical_operator` (param) | `$lastLogicalOperator` | naming.md: Method parameters use camelCase |
| 13 | `src/Repositories/Criteria/ApiCriteria.php` | 344 | `$last_logical_operator` (param) | `$lastLogicalOperator` | naming.md: Method parameters use camelCase |
| 14 | `src/Repositories/Criteria/ApiCriteria.php` | 349 | `$base_method` | `$baseMethod` | naming.md: Variables use camelCase |
| 15 | `src/Repositories/Criteria/Concerns/AppliesFilterConditions.php` | 25 | `$last_logical_operator` (param) | `$lastLogicalOperator` | naming.md: Method parameters use camelCase |
| 16 | `src/Repositories/Criteria/Concerns/AppliesFilterConditions.php` | 43 | `$last_logical_operator` (param) | `$lastLogicalOperator` | naming.md: Method parameters use camelCase |
| 17 | `src/Repositories/Criteria/Concerns/AppliesFilterConditions.php` | 69 | `$last_logical_operator` (param) | `$lastLogicalOperator` | naming.md: Method parameters use camelCase |
| 18 | `src/Repositories/Criteria/Concerns/AppliesFilterConditions.php` | 97 | `$last_logical_operator` (param) | `$lastLogicalOperator` | naming.md: Method parameters use camelCase |
| 19 | `src/Repositories/Criteria/Concerns/AppliesFilterConditions.php` | 189 | `$is_null` (param) | `$isNull` | naming.md: Method parameters use camelCase |
| 20 | `src/Repositories/Criteria/Concerns/AppliesFilterConditions.php` | 211 | `$last_logical_operator` (param) | `$lastLogicalOperator` | naming.md: Method parameters use camelCase |
| 21 | `src/Repositories/Criteria/Concerns/AppliesFilterConditions.php` | 217 | `$sql_operator` / `$formatted_value` | `$sqlOperator` / `$formattedValue` | naming.md: Variables use camelCase |

---

## Complexity Issues

| # | File | Method / Class | Metric | Current Value | Threshold | Suggestion |
|---|------|----------------|--------|---------------|-----------|------------|
| 1 | `src/Repositories/Criteria/Concerns/AppliesFilterConditions.php` | `applyConditionOperator()` | parameter count | 5 | 4 | Introduce a parameter object or restructure to reduce parameter count |
| 2 | `src/Repositories/Criteria/Concerns/AppliesFilterConditions.php` | `applyCondition()` | parameter count | 5 | 4 | Introduce a parameter object or restructure to reduce parameter count |
| 3 | `src/Repositories/Criteria/Concerns/AppliesFilterConditions.php` | `applyDefaultCondition()` | parameter count | 5 | 4 | Introduce a parameter object or restructure to reduce parameter count |
| 4 | `src/Repositories/Criteria/ApiCriteria.php` | `applyHasFilter()` | nesting depth | 4 | 3 | Flatten by extracting inner blocks into separate methods |

---

## Structural Issues

| # | File | Issue | Anti-Pattern | Recommended Fix |
|---|------|-------|--------------|-----------------|
| 1 | `src/Repositories/RepositoryResolver.php` | Uses `resolve()` service locator at line 47 and `config()` helper at lines 29, 70, 73 outside a service provider | structure.md: Avoid the service locator pattern (`app()`, `resolve()`) outside service providers | Inject dependencies via constructor or receive config values through constructor injection |
| 2 | `src/Repositories/Criteria/Concerns/AppliesFilterConditions.php` | Empty catch block at lines 141-142 silently swallows `\Throwable` with no comment explaining why | structure.md: Never swallow exceptions silently | Add a comment explaining why the exception is intentionally swallowed, or handle it |
| 3 | `src/Repositories/Criteria/ApiCriteria.php` | Catches `\Throwable` at line 405 in `isRelation()` -- catching `\Throwable` should be reserved for top-level error handlers | structure.md: Never catch `\Throwable` except in top-level error handlers |  Narrow the catch to a more specific exception type |

---

## Testing Gaps

No testing gaps found.

---

## Style Issues

| # | File | Line | Issue | Auto-Fixable |
|---|------|------|-------|--------------|
| 1 | `src/Repositories/Traits/HasRepositories.php` | 29 | PHPStan/CodeSniffer reports: Type hint "string" missing for `$method` parameter in docblock (`Squiz.Commenting.FunctionComment.ScalarTypeHintMissing`). The `phpcs:ignore` suppression on the same line prevents the tool from flagging it, but the underlying type hint documentation gap remains. | no |

---

## Tech Debt

| # | File | Line | Type | Description |
|---|------|------|------|-------------|
| 1 | `src/Repositories/Criteria/ApiCriteria.php` | 25 | suppressed-warning | `@SuppressWarnings("php:S1068")` suppresses unused private field detection across the entire class |
| 2 | `src/Repositories/Criteria/ApiCriteria.php` | 26 | suppressed-warning | `@SuppressWarnings("php:S1144")` suppresses unused private method detection across the entire class |
| 3 | `src/Repositories/Criteria/ApiCriteria.php` | 27 | suppressed-warning | `@SuppressWarnings("php:S1448")` suppresses class method count detection across the entire class |
| 4 | `src/Repositories/RepositoryResolver.php` | 29 | static-analysis | PHPStan reports `return.type` mismatch: `map()` should return typed array but returns `mixed` from `config()` |

---

## Documentation Issues

| # | File | Line | Issue | Rule Violated |
|---|------|------|-------|---------------|
| 1 | `src/Repositories/Traits/InteractsWithModelSchema.php` | 18 | `@var array<string, array>` missing value type for inner array; should specify element type e.g. `array<string, array<int, string>>` | analysis.md: PHPDoc Type Qualification -- all types must be fully specified |
| 2 | `src/Repositories/Traits/InteractsWithModelSchema.php` | 25 | `@return array` missing value type specification; should be `@return array<int, string>` | analysis.md: PHPDoc Type Qualification -- all types must be fully specified |
| 3 | `src/Repositories/Traits/InteractsWithModelSchema.php` | 36 | `@return array` missing value type specification; should be `@return array<int, string>` | analysis.md: PHPDoc Type Qualification -- all types must be fully specified |
| 4 | `src/Repositories/Traits/InteractsWithModelSchema.php` | 55 | `@return array` missing value type specification; should be `@return array<int, string>` | analysis.md: PHPDoc Type Qualification -- all types must be fully specified |
| 5 | `src/Repositories/Traits/InteractsWithModelSchema.php` | 65 | `@param array $columns` missing value type specification; should be `@param array<int, string> $columns` | analysis.md: PHPDoc Type Qualification -- all types must be fully specified |
| 6 | `src/Repositories/Traits/HasRepositories.php` | 52 | `@return mixed` on `resolveRepository()` is inaccurate; native return type is `RepositoryInterface` so docblock should be `@return \SineMacula\Repositories\Contracts\RepositoryInterface<\Illuminate\Database\Eloquent\Model>` | analysis.md: PHPDoc Documentation Format -- `@return` must match actual return type; code-quality.md: Required Documentation for public methods |
| 7 | `src/Repositories/Traits/HasRepositories.php` | 27 | `@throws \Exception` is a generic exception type; should document the specific exception types that can be thrown (`\BadMethodCallException`, `\RuntimeException`) | code-quality.md: Required Documentation -- parameter descriptions and return type |
| 8 | `src/Repositories/Traits/HasRepositories.php` | 54 | `@throws \Exception` is a generic exception type; should document the specific exception types (`\RuntimeException`) | code-quality.md: Required Documentation -- parameter descriptions and return type |
| 9 | `src/Repositories/Criteria/Concerns/AppliesFilterConditions.php` | 177 | `is_string($string)` check on a `?string` typed parameter is redundant; the only non-string value is `null` which is already caught by `empty($string)` | code-quality.md: AI Slop Patterns -- over-defensive null checks on typed parameters |
| 10 | `src/Repositories/ApiRepository.php` | 376 | `!is_null($value) ? $value : null` in `setArrayAttribute()` is a no-op expression equivalent to just `$value` | code-quality.md: AI Slop Patterns -- over-defensive null checks |
| 11 | `src/Repositories/ApiRepository.php` | 30 | `@author` tag in class docblock; version control handles authorship | code-quality.md: What NOT to Document -- file headers with author/date |
| 12 | `src/Repositories/ApiRepository.php` | 31 | `@copyright` tag in class docblock; version control handles this | code-quality.md: What NOT to Document -- file headers with author/date |
| 13 | `src/Repositories/Traits/HasRepositories.php` | 29 | `#[\SensitiveParameter]` on `$method` and `$arguments` parameters of `__call()` is incorrect; these are method names and arguments, not secrets like passwords or API keys | analysis.md: PHP Native Attributes -- `#[\SensitiveParameter]` applies when parameter represents secrets |

---

## References

- Traces to: Scope -- `src/Repositories/` (8 PHP files)
- Language pack: `/Users/ben/.claude/plugins/cache/sinemacula/build/0.1.0/packs/php/`
- Naming rules: `/Users/ben/.claude/plugins/cache/sinemacula/build/0.1.0/packs/php/naming.md`
- Structure rules: `/Users/ben/.claude/plugins/cache/sinemacula/build/0.1.0/packs/php/structure.md`
- Testing rules: `/Users/ben/.claude/plugins/cache/sinemacula/build/0.1.0/packs/php/testing.md`
- Code quality standards: `/Users/ben/.claude/plugins/cache/sinemacula/build/0.1.0/references/code-quality.md`
