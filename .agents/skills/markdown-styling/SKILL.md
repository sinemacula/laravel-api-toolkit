---
name: markdown-styling
description: >
  Enforce consistent Markdown style by removing duplication, unwrapping legacy
  wrapped prose, rewrapping at 120 characters, and keeping
  heading/list/code formatting uniform across docs.
---

# Markdown Styling

## Purpose

Keep Markdown content readable, consistent, and low-noise across repository documentation.

End goal: docs should avoid repeated content, use predictable formatting rules, and wrap prose at 120 characters.

## Use This Skill When

- Adding or editing any Markdown file (`*.md`)
- Updating documentation sections or examples
- Refactoring docs for readability and consistency

## References

- `references/edge-cases.md`: Load when files include badges, tables, frontmatter, nested lists, or link-heavy lines.

## Core Rules

### 1. Always Check for Duplication

- Identify repeated paragraphs, repeated bullets, and repeated section intent
- Keep the clearest version and remove redundant copies
- Merge overlapping sections instead of leaving near-duplicates

### 2. Wrap Lines at 120 Characters

- Unwrap existing hard-wrapped prose and bullets first, then rewrap to 120 characters maximum
- Do not keep legacy 80-column wrapping
- Preserve readability when wrapping links, inline code, and long lists
- Never wrap content inside fenced code blocks

### 3. Enforce Consistent Styling

- Keep heading capitalization and hierarchy consistent within a file
- Keep list marker style consistent within each list (`-` or numbered list)
- Keep spacing consistent around headings, lists, and fenced code blocks
- Keep terminology and phrasing consistent for repeated concepts

## Guardrails

- Preserve meaning and documented behavior while editing style
- Avoid speculative rewrites unrelated to clarity or consistency
- Keep edits scoped to files touched by the task

## Workflow

1. Scan changed Markdown files for repeated or overlapping content
2. Remove or merge duplicated content
3. Unwrap hard-wrapped prose and bullets, then reflow to 120 characters
4. Normalize heading, list, spacing, and terminology consistency
5. Apply edge-case rules from `references/edge-cases.md` when relevant
6. Verify code fences and examples remain intact and unwrapped
