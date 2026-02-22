---
name: php-documenter
description: >
  Enforce strict, consistent, and concise PHP documentation and comment
  standards across codebases, including fully qualified types, 80-character
  wrapping, and anti-churn safeguards.
---

# PHP Documenter

## Purpose

Ensure documentation and comments are complete, accurate, concise, and human-readable across the entire codebase.

End goal: a human should be able to understand what the code does and why it exists by reading the documentation and
comments, without unnecessary verbosity or noise.

This skill exists to document intent, not to restate code, generate prose, or overwrite meaningful attribution.

## Use This Skill When

- Any PHP code is added, modified, or refactored
- Any documentation or comments may have become outdated, incorrect, missing, or overly verbose
- Code changes affect behavior, responsibilities, configuration, or public surfaces

## References

- `references/documentation-patterns.md`: Load for repository-grounded templates and examples before writing docblocks.

## Scope and Coverage

- Doc comments are mandatory for **everything that would reasonably be expected to have them**
- This includes, but is not limited to:
  - Classes, traits, interfaces, enums
  - Methods and functions (all visibilities)
  - Properties and constants (class-level and file-level)
  - Constructor-promoted properties
  - Configuration arrays and logical configuration sections
- If something looks like it should be documented, it must be documented

## Hard Guardrails

- Do not invent documentation where none is warranted
- Do not remove or alter existing attribution unless it is missing or malformed
- Do not remove custom or tool-specific comments (e.g. formatter directives)
- Do not introduce documentation churn; keep changes scoped to files touched by the task
- Preserve runtime behavior exactly

## Attribution Rules

### Author

- If an author tag already exists and includes a name and email, do not modify it
- If an author tag is missing, do not guess or fabricate one
- This skill does not exist to enforce or normalize authorship

### Copyright

- If present and correct, leave unchanged
- If missing where required, add using the current year and the organization name

## Line Wrapping and Formatting Rules

- All comments and doc comments must wrap at **80 characters**
- Do not break words; wrap whole words only
- Maintain proper indentation when wrapping
- Never wrap lines where wrapping would break meaning or tooling:
  - @param
  - @return
  - @throws
  - @method
- In those cases:
  - Keep the content on a single line
  - Surround the block with formatter on/off directives

## Documentation Formats

### 1. Class / Trait / Interface / Enum Doc Comments

- Required for all type declarations
- Must include:
  - A concise title or description
  - Optional additional context only if it materially improves understanding
- Avoid verbosity and repetition
- If existing documentation is overly verbose, simplify it while preserving intent

Reference:

- Use class/type templates in `references/documentation-patterns.md`.

### Facades, Proxies, and Magic APIs

- If a type exposes behavior via __call,__callStatic, or similar mechanisms, document the intended public surface using
  @method tags
- Use fully qualified types
- Include only supported and intentional methods

Reference:

- Use facade/proxy `@method` and formatter templates in `references/documentation-patterns.md`.

### 2. Properties and Constants

- Every property and constant must have a doc comment
- Properties and constants use the same format
- Use @var with a fully qualified or generic type
- Descriptions are optional and only required when intent is not obvious

Reference:

- Use property/constant templates in `references/documentation-patterns.md`.

Note:

- @const is not used or required
- This format is intentionally consistent with properties

### 3. Constructor-Promoted Properties

- Promoted properties may include inline doc comments
- Inline comments describe intent only; types belong in the constructor docblock
- Inline comments must wrap at 80 characters and align with property indentation
- For multi-line constructor signatures with promoted properties, insert a single blank line between the final parameter
  line and the closing `)` line
- When a method mixes promoted properties and regular parameters, each non-promoted parameter must include a preceding
  inline `//` comment in the signature
- In mixed signatures, this inline parameter comment rule is mandatory and not optional

Reference:

- Use constructor and mixed-signature templates in `references/documentation-patterns.md`.

### 4. Methods and Functions

- Every method and function must have a doc comment
- Required tags:
  - @param (for every parameter)
  - @return (always required, including void)
  - @throws (when exceptions may be thrown)
- All types must be fully qualified
- Descriptions must be concise and avoid restating obvious behavior
- Simplify verbose or AI-generated descriptions
- Do NOT wrap directive lines (@param, @method, etc.). group with formatter directives

Reference:

- Use formatter-on/off tag-block templates in `references/documentation-patterns.md`.

### 5. Inline Comments

- Inline comments must use the following format only:

```php
// This is a comment
```

- Inline comments should be rare
- Use them only when code is genuinely non-obvious or requires clarification
- Do not narrate what the code already makes clear
- Exception: mixed signatures (regular parameters + promoted properties) must use inline `//` comments for each regular
  parameter

### 6. Configuration and Section Header Comments

- Use pipe-banner blocks to separate logical configuration sections
- Use intelligent grouping; do not blindly add banners to every array
- Banners explain why a section exists, not how configuration works
- The full banner line (including the leading |) must be 80 characters wide

Reference:

- Use pipe-banner templates in `references/documentation-patterns.md`.

### 7. Configuration Arrays

- Doc comments inside arrays must:
  - Be indented to match the array item
  - Wrap at 80 characters
- Use comments sparingly and only where clarity is improved

### 8. Enums and Enum Cases

- Enums require a doc comment at the enum level
- Enum cases do not require individual documentation when self-explanatory
- If one or more enum cases require clarification:
  - Every enum case must receive a description
  - Descriptions must be concise and consistent

## Anti-Churn Guardrails

- Do not rewrite documentation purely for stylistic preference
- Simplify documentation only when it is:
  - Excessively verbose
  - Repetitive
  - Clearly AI-generated filler
- Avoid re-touching unchanged documentation across runs
- This skill must converge and not repeat indefinitely

## Workflow

1. **Run `composer check -- --all --no-cache --fix` first**
    - This step is mandatory
    - It applies deterministic auto-fixes, including formatting and docblock normalization
    - Documentation work must be performed on the formatted output, not before
2. Identify the files touched by the task
3. Ensure all required documentation and comments are present
4. Enforce strict formatting, wrapping, and fully qualified types
5. Simplify overly verbose documentation while preserving intent
6. Add or adjust section banners only when they improve structure
7. Run tests if executable code was modified
8. Summarize results using the standard outcome contract

## Output Contract

- `status`: `completed-no-changes` | `changed` | `blocked` | `approval-required`
- `changed_files`: explicit list, or `[]` if no changes
- `rerun_required`: `true` | `false`
- `approval_items`: explicit list, or `[]`
- `blockers`: explicit list, or `[]`
