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

## Escalation Triggers

- Requires config change to pass.
- Requires suppressions or ignores.
- Requires likely breaking or cross-cutting refactor.

In those cases return `approval-required` with:

- exact failing rule/tool message
- minimal proposed change
- risk and scope summary
