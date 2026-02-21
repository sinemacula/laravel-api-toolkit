# Markdown Edge Cases

Use this reference only when a Markdown file includes non-prose structures that should not be reflowed mechanically.

## Badge and Link Lines

- Keep badge/image link lines intact when wrapping would split the label from the URL.
- It is acceptable for a pure badge/link line to exceed 120 characters.
- Wrap surrounding prose around the badge block, not inside the badge syntax.

## YAML Frontmatter

- Preserve frontmatter delimiters (`---`) and YAML indentation.
- Keep folded/scalar blocks valid; do not collapse multi-line YAML values into invalid single lines.
- Reflow only human prose values where safe, not keys or structural punctuation.

## Fenced Code Blocks

- Do not unwrap or rewrap fenced code block content.
- Preserve language tags and fence boundaries exactly.
- Reflow prose before/after the fence independently.

## Tables and Block Quotes

- Do not reflow pipe table rows.
- Keep alignment rows intact.
- Preserve block quote markers (`>`) and wrap quote text only when wrapping does not break structure.

## Lists and Continuation Lines

- Keep continuation lines indented under their parent list item.
- Preserve nested list levels and numbering style.
- Do not collapse subordinate `Format:` / `Example:` lines into a single sentence when they are meant to be separate.

## Final Check

- Confirm every changed Markdown file remains valid and readable in plain-text diff form.
- Prioritize structural correctness over strict width limits for non-prose syntactic lines.
