# Clean Plan: Repositories

Prioritised cleanup of 46 issues across 6 files in `src/Repositories/` with zero functional change.

---

## Governance

| Field     | Value                                                                              |
|-----------|------------------------------------------------------------------------------------|
| Created   | 2026-02-26                                                                         |
| Status    | draft                                                                              |
| Owned by  | Orchestrator                                                                       |
| Traces to | [Clean Analysis](.build/workflows/clean-repositories/clean-analysis.md)            |

---

## Selected Categories

| Category      | Selected | Issue Count |
|---------------|----------|-------------|
| Naming        | yes      | 21          |
| Complexity    | yes      | 4           |
| Structure     | yes      | 3           |
| Tests         | yes      | 0           |
| Style         | yes      | 1           |
| Debt          | yes      | 4           |
| Documentation | yes      | 13          |

---

## Estimated Scope

| Field                            | Value                                                                            |
|----------------------------------|----------------------------------------------------------------------------------|
| Files Affected                   | 6                                                                                |
| Total Issues                     | 46                                                                               |
| Quick Wins (naming, style, doc)  | 35                                                                               |
| Moderate (complexity, structure) | 7                                                                                |
| Involved (debt)                  | 4                                                                                |
| Expected Impact                  | Consistent camelCase naming, accurate documentation, reduced complexity and debt  |

---

## Plan

| #  | Priority | File | Issues to Address | Category | Approach |
|----|----------|------|-------------------|----------|----------|
| 1  | 1 | `src/Repositories/ApiRepository.php` | Naming #1-7: 7 snake_case variables/params (`$resource_class`, `$sync_attributes`, `$native_cast`, `$laravel_casts`, `$laravel_cast` x2, `$base_cast`) | Naming | Rename each variable/parameter from snake_case to camelCase per naming.md. Update all references within the file. No public API signatures change (these are local variables and private method params). |
| 2  | 1 | `src/Repositories/ApiRepository.php` | Documentation #10: `!is_null($value) ? $value : null` no-op expression at line 376 | Documentation | Replace `!is_null($value) ? $value : null` with `$value` per code-quality.md AI slop patterns. |
| 3  | 1 | `src/Repositories/ApiRepository.php` | Documentation #11-12: `@author` and `@copyright` tags at lines 30-31 | Documentation | Remove `@author` and `@copyright` tags from class docblock per code-quality.md (version control handles authorship). |
| 4  | 1 | `src/Repositories/Criteria/ApiCriteria.php` | Naming #8-14: 7 snake_case variables/params (`$last_logical_operator` x3, `$requested_counts`, `$with_counts`, `$logical_operator`, `$base_method`) | Naming | Rename each variable/parameter from snake_case to camelCase per naming.md. These are all local variables and method parameters internal to the class. |
| 5  | 1 | `src/Repositories/Criteria/Concerns/AppliesFilterConditions.php` | Naming #15-21: 7 snake_case params/vars (`$last_logical_operator` x5, `$is_null`, `$sql_operator`/`$formatted_value`) | Naming | Rename each parameter/variable from snake_case to camelCase per naming.md. All are internal to the trait methods. |
| 6  | 1 | `src/Repositories/Criteria/Concerns/AppliesFilterConditions.php` | Documentation #9: Redundant `is_string()` check on `?string` param at line 177 | Documentation | Remove the redundant `is_string($string)` check; `empty($string)` already handles the null case per code-quality.md AI slop patterns. |
| 7  | 1 | `src/Repositories/Traits/HasRepositories.php` | Style #1: Scalar type hint documentation gap at line 29 | Style | Add `string` type hint for `$method` parameter in docblock and remove the `phpcs:ignore` suppression per analysis.md. |
| 8  | 1 | `src/Repositories/Traits/HasRepositories.php` | Documentation #13: Misapplied `#[\SensitiveParameter]` on `$method` and `$arguments` in `__call()` at line 29 | Documentation | Remove `#[\SensitiveParameter]` from both parameters per analysis.md -- method names and arguments are not secrets. |
| 9  | 1 | `src/Repositories/Traits/HasRepositories.php` | Documentation #6: `@return mixed` should be `@return RepositoryInterface` on `resolveRepository()` at line 52 | Documentation | Update `@return mixed` to match the native return type `RepositoryInterface` per code-quality.md. |
| 10 | 1 | `src/Repositories/Traits/HasRepositories.php` | Documentation #7-8: Generic `@throws \Exception` at lines 27, 54 | Documentation | Replace `@throws \Exception` with specific exception types: `\BadMethodCallException` and `\RuntimeException` per code-quality.md. |
| 11 | 1 | `src/Repositories/Traits/InteractsWithModelSchema.php` | Documentation #1-5: 5 untyped `@var`/`@param`/`@return array` docblocks at lines 18, 25, 36, 55, 65 | Documentation | Add value type specifications to all array docblocks (e.g., `array<int, string>`, `array<string, array<int, string>>`) per analysis.md PHPDoc type qualification rules. |
| 12 | 2 | `src/Repositories/Criteria/Concerns/AppliesFilterConditions.php` | Complexity #1-3: `applyConditionOperator()`, `applyCondition()`, `applyDefaultCondition()` each have 5 parameters (threshold: 4) | Complexity | Reduce parameter count. The `$last_logical_operator` (becoming `$lastLogicalOperator`) parameter is common across all three; consider restructuring to reduce the parameter list while preserving identical call semantics. |
| 13 | 2 | `src/Repositories/Criteria/ApiCriteria.php` | Complexity #4: `applyHasFilter()` nesting depth 4 (threshold: 3) | Complexity | Extract inner conditional blocks into private methods to reduce nesting depth per pack.toml thresholds. |
| 14 | 2 | `src/Repositories/Criteria/Concerns/AppliesFilterConditions.php` | Structure #2: Empty catch block silently swallowing `\Throwable` at lines 141-142 | Structure | Add an explanatory comment documenting why the exception is intentionally swallowed, per structure.md. If the swallow is not intentional, narrow and handle the exception. |
| 15 | 2 | `src/Repositories/Criteria/ApiCriteria.php` | Structure #3: Overly broad `\Throwable` catch at line 405 in `isRelation()` | Structure | Narrow the catch to a specific exception type (e.g., `\BadMethodCallException` or `\RuntimeException`) per structure.md. |
| 16 | 2 | `src/Repositories/RepositoryResolver.php` | Structure #1: Service locator usage (`resolve()` at line 47, `config()` at lines 29, 70, 73) | Structure | Document as a constraint. `RepositoryResolver` is a static utility class; refactoring to constructor injection would change its public API and usage pattern. Add a comment acknowledging the service locator usage per structure.md. |
| 17 | 3 | `src/Repositories/Criteria/ApiCriteria.php` | Debt #1-3: Three `@SuppressWarnings` annotations at lines 25-27 suppressing unused field, unused method, and class size detection | Debt | Evaluate whether the suppressions are still needed. If the underlying issues are resolved by other plan items (naming, complexity), remove the suppressions. If still needed, retain with a comment explaining why. |
| 18 | 3 | `src/Repositories/RepositoryResolver.php` | Debt #4: PHPStan `return.type` mismatch on `map()` at line 29 | Debt | Fix the return type annotation or add a PHPStan type assertion to resolve the `return.type` mismatch per analysis.md. |

---

## Excluded Issues

No issues excluded. All analysed issues are addressed in the plan.

---

## Constraints

The following MUST NOT change as a result of cleanup execution:

- **Functionality:** All existing behaviour must be preserved. No feature additions, removals, or modifications.
- **Public APIs:** Method signatures, return types, parameter lists, and class interfaces must remain identical. The naming changes in this plan affect only local variables and internal method parameters.
- **Test behaviour:** Existing tests must continue to pass with identical assertions. Test files that reference renamed variables/parameters must be updated to match.
- **Configuration:** Application configuration, environment variables, and deployment settings must not change.
- **Data:** Database schemas, data formats, serialisation contracts must not change.
- **Out-of-scope files:** Files outside `src/Repositories/` must not be modified. If a rename propagates outside scope (e.g., callers of renamed parameters), those changes are out of scope for this plan.

---

## References

- Traces to: [Clean Analysis](.build/workflows/clean-repositories/clean-analysis.md)
- Language pack: `/Users/ben/.claude/plugins/cache/sinemacula/build/0.1.0/packs/php/`
- Naming rules: `/Users/ben/.claude/plugins/cache/sinemacula/build/0.1.0/packs/php/naming.md`
- Structure rules: `/Users/ben/.claude/plugins/cache/sinemacula/build/0.1.0/packs/php/structure.md`
- Testing rules: `/Users/ben/.claude/plugins/cache/sinemacula/build/0.1.0/packs/php/testing.md`
