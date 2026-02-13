# Prompt Templates

Use these templates when generating section HTML or migration plans with an external model.

## Prompt: Generate WordPress Plugin From Gemini Demo

```text
Act as a senior WordPress plugin engineer.

Goal:
- Convert the provided Gemini-generated page (React/Tailwind/HTML) into a WordPress plugin.
- Target WooCommerce product category pages (product_cat).
- Preserve visual parity: structure, typography, spacing, colors, interactions.

Constraints:
- Do not use WPCode snippets.
- Use plugin architecture with scoped CSS/JS.
- Inline SVG icons (Lucide style).
- Bind dynamic data to ACF fields.
- If fields are missing, produce an ACF import plan.

Input files:
- Demo source: [PATH]
- ACF exports: [PATHS]

Required output:
1) Plugin file tree.
2) Data mapping table (section -> ACF fields).
3) Exact render strategy (append vs override).
4) Known risk list and mitigation.
```

## Prompt: Generate HTML For Manual ACF HTML Fields

```text
Generate pure HTML for 3 ACF HTML fields:
- standards_html
- model_reference_html
- key_specifications_html

Rules:
- Output only HTML (no markdown, no explanations).
- Keep class names exactly as provided template.
- Replace only content values.
- No script/style tags.
- Remove empty rows/cards when data is missing.

Source data:
[PASTE CATEGORY DATA]

Output format:
<!-- standards_html -->
...html...
<!-- model_reference_html -->
...html...
<!-- key_specifications_html -->
...html...
```

