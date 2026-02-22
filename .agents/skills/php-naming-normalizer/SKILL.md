---
name: php-naming-normalizer
description: >
  Normalize PHP naming to maximize human readability and domain clarity, using
  AI judgment to propose or apply safe, non-breaking renames and structural
  grouping while preventing churn.
---

# PHP Naming Normalizer

## Purpose

Ensure PHP names (classes, methods, properties, variables) are **clear, concise, and domain-aligned**.

**End goal:** A human should be able to read through a class and understand what it does purely by reading the method
and property names, with minimal need to inspect implementations.

This skill is **AI-judgment driven**. It does not rely on tooling findings. Changes must improve human readability and
consistency while preserving behavior and public contracts.

## Use This Skill When

- New code is introduced and naming must align with project conventions
- Existing names obscure intent, misuse terminology, introduce redundancy, or reduce human readability
- Files would benefit from directory-based grouping that improves conceptual clarity

## References

- `references/naming-lexicon.md`: Load before renaming to align with established project terminology.

## Core Naming Principles

### 1. Intent Over Mechanics

- Names should describe **what** something represents or does, not **how** it is implemented
- Prefer domain language over technical or provider-specific terminology
- Avoid leaking infrastructure, vendor, or transport concerns into names unless the concept is inherently
  provider-specific

### 2. Code Should Read Like Language

- Method names should form clear, readable statements when scanned top-to-bottom
- A reader should be able to infer behavior and flow without opening method bodies
- If intent is not obvious from the name alone, the name is insufficient

### 3. Questions Read Like Questions

- Methods that return booleans should read naturally as questions
- Prefer prefixes such as:
  - `is*` (state)
  - `has*` (possession)
  - `can*` (capability/permission)
  - `should*` (decision)
- If a single, expressive verb communicates intent more clearly, prefer it
  - Example: prefer `can` over `doesHavePermission`

### 4. Be Descriptive, Not Verbose

- Names should be as short as possible **without losing meaning**
- Avoid unnecessary filler words such as `does`, `process`, `handle`, `data`, `value`, `object`
- Prefer precise verbs and nouns over long descriptive chains
- If a name becomes long, it is often a signal that the responsibility should be split

### 5. Acronyms Are Acceptable; Shorthand Is Not

- Acronyms and initialisms are allowed when they are:
  - Widely accepted
  - Unambiguous within the domain
- Examples of acceptable acronyms:
  - `SDK`, `HTTP`, `API`, `URL`, `UUID`
- Avoid informal or shortened word fragments
  - Use full words rather than truncations
  - Acronyms are encouraged; shorthand is not

### 6. Directory Context Replaces Name Prefixes

- When classes are grouped into a directory representing a clear domain or concept, **do not repeat that context in the
  class name**
- The directory (and resulting namespace) provides the necessary semantic prefix
- Class names should represent the **specific concept**, not restate the parent grouping

Guideline:

- Prefer `Context/Thing` over `Context/ContextThing`
- If the directory name answers “what kind of thing is this?”, the class name should answer “which one?”

### 7. Use Directories to Express Conceptual Grouping

- When there is a clear need to separate related concepts, prefer **directory-based grouping**
- Grouping should reflect:
  - Conceptual ownership
  - Domain boundaries
  - Mental models used by humans reading the code
- When files are grouped into a new directory:
  - Update class names to remove redundant prefixes
  - Let the directory and namespace carry the shared meaning

This rule exists to keep class names short, expressive, and readable while allowing the structure to convey hierarchy.

### 8. Domain Language Is Canonical

- Prefer domain-specific terminology over generic or technical language
- If the domain has a term of art, use it consistently everywhere
- Do not invent synonyms for the same concept across the codebase

### 9. Stability and Backward Compatibility

- Do not rename public APIs, methods, or symbols unless explicitly approved
- Internal renames should be scoped and justified by clarity gains
- Large renaming efforts should be incremental and focused

## Anti-Churn Guardrails

Naming is subjective. This section prevents pointless refactoring.

### Non-Goals

- Do not rename purely to align with personal preference
- Do not rename purely because a different naming style is also valid
- Do not perform sweeping rename passes across a class or directory without a clear, material readability win

### Rename Threshold

Only rename when at least one of the following is true:

- The current name is misleading or incorrect
- The name conflicts with established terminology elsewhere in the codebase
- The name hides intent (a reader cannot infer purpose without reading the method body)
- The name is redundant due to directory/namespace context (e.g. `ContextThing` within `Context/`)
- The change reduces cognitive load in a way that is obvious and defensible

If the change is merely “arguably better,” do not rename.

### Change Budget

- Keep naming changes **task-scoped**
- For files touched primarily for unrelated work, limit renames to the smallest set that materially improves readability
- Avoid renaming more than a small handful of symbols in a single file unless the task is explicitly about naming

If broader renaming seems beneficial, return `approval-required` with a proposed plan rather than applying it.

## Hard Guardrails

- Preserve runtime behavior exactly
- Do not introduce breaking changes without explicit approval
- Do not rename public APIs, exported symbols, or externally referenced classes without explicit approval
- Do not perform large-scale renaming across the codebase unless explicitly requested
- Avoid churn: keep naming changes scoped to the files and concepts touched by the task

## Approval Boundaries

Manual approval is required for:

- Any public API rename
- Any rename that affects external integrations or contracts
- Any large-scale or cross-cutting renaming effort
- Any directory restructuring that impacts consumers outside the repository (autoload paths, exported namespaces)

## Workflow

1. Identify the task scope and the files actually touched by the change request
2. Scan only the touched areas for naming issues that meet the rename threshold
3. Apply minimal, high-confidence naming improvements (avoid churn)
4. If broader renaming would be beneficial, propose a plan and request approval instead of applying it
5. Update references and tests as required
6. Run tests if any executable code was modified
7. Summarize results using the standard outcome contract

## Output Contract

- `status`: `completed-no-changes` | `changed` | `blocked` | `approval-required`
- `changed_files`: explicit list, or `[]` if no changes
- `rerun_required`: `true` | `false`
- `approval_items`: explicit list, or `[]`
- `blockers`: explicit list, or `[]`
