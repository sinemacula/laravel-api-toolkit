# Complexity Refactor Patterns

Use these patterns only when objective tool-reported thresholds are exceeded, or in advisory proposals.

## Pattern: Reduce Nesting with Guard Clauses

Before:

- Deeply nested `if`/`else` blocks that gate early invalid states.

After:

- Return early for invalid states, keep the primary happy path linear.

## Pattern: Extract Cohesive Sub-Operation

Before:

- A method mixes parsing, validation, orchestration, and serialization.

After:

- Extract one private method per cohesive step with clear naming.
- Keep call order explicit in the parent method.

## Pattern: Flatten Condition Chains with `match`

Before:

- Long `if / else if` chain with mutually exclusive conditions.

After:

- Use `match` when conditions are value-based and deterministic.

## Advisory Mode Output Shape

When no objective threshold violation exists, return a proposal with:

- Hotspot summary (method/class and reason).
- Minimum refactor plan (1-3 changes).
- Risk assessment (behavioral, public API, testing impact).
- Approval request.
