# Triage Matrix

Use this matrix to classify `composer check -- --all --no-cache --fix` output quickly and route remediation correctly.

## Classification Order

1. Formatting/layout violation
2. Documentation/comment violation
3. Naming/readability violation
4. Complexity threshold violation
5. Type/static analysis correctness violation
6. Other deterministic violation

## Routing

- Formatting: remediate directly or via `$php-styling`.
- Documentation: use `$php-documenter`.
- Naming: use `$php-naming-normalizer`.
- Complexity: use `$php-complexity-refactor`.
- Type correctness: apply minimal behavior-preserving code fixes directly.

## Known Circular Conflicts

Some conflicts oscillate between two tools and cannot be resolved by addressing
either rule in isolation. When a formatting/check loop is detected, consult
this table before stopping.

### PSR12.Traits.UseDeclaration.NoBlankLineAfterUse vs CS-Fixer blank-line removal

**Pattern:**
- `phpcs` reports `PSR12.Traits.UseDeclaration.NoBlankLineAfterUse` on a
  trait `use` statement — requires a blank line after it.
- PHP-CS-Fixer removes that blank line because the member directly below the
  `use` statement has no doc block (no visual separator is needed in its model).
- Re-running produces the same conflict each cycle.

**Root cause:** The member immediately after `use Trait;` is missing a doc
block. When a `/** @var type */` (or full method docblock) is present, both
tools interpret the blank line as meaningful and agree on its placement.

**Resolution:**
1. Identify the member (property, constant, or method) immediately below the
   offending `use Trait;` line.
2. If it lacks a doc block, route to `$php-documenter` to add one.
3. Re-run `composer check -- --all --no-cache --fix` — both tools should pass.

Do NOT add a `// phpcs:ignore` suppression as a first resort for this pattern.

## Escalation Triggers

- Requires config change to pass.
- Requires suppressions or ignores.
- Requires likely breaking or cross-cutting refactor.

In those cases return `approval-required` with:

- exact failing rule/tool message
- minimal proposed change
- risk and scope summary
