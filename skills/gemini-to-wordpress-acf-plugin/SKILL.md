---
name: gemini-to-wordpress-acf-plugin
description: This skill should be used when converting AI-generated page code into Elementor JSON, WordPress plugins, or hybrid Elementor+plugin delivery with strict visual parity and reusable ACF data mapping.
---

# Gemini To WordPress ACF Plugin

## Overview

Convert AI-generated page code into production-ready WordPress delivery using a route-based workflow:
- `elementor-json`: fast path for static/simple pages.
- `hybrid`: Elementor layout + plugin-rendered hard blocks.
- `plugin`: full plugin for dynamic data and complex behaviors.

Prevent repeat failures by enforcing: route decision, section inventory, field-reuse interview, ACF mapping, scoped implementation, interaction hardening, and parity verification.

## When To Use

Use this skill when any of the following is true:
- Receive Gemini/React/Tailwind/Vue/Svelte HTML output and need WordPress delivery.
- Need Elementor JSON output for a single page.
- Need to patch an existing Elementor JSON file without rebuilding from scratch.
- Need reusable taxonomy/product fields (ACF) instead of hardcoded content.
- Need hybrid fallback because Elementor cannot faithfully reproduce motion/layout.
- Need to debug style mismatch, animation mismatch, icon distortion, popup failures, or theme override issues.

## Workflow

## Step 0: Choose Delivery Route

Choose route before coding.

Scoring (0-3 each):
- Data complexity (filters, queries, taxonomy coupling)
- Motion/layout complexity (scroll timeline, layered absolute positioning, advanced micro-interactions)
- Runtime logic complexity (stateful behavior, conditional rendering)

Route rule:
- Total `0-2`: Route A (`elementor-json`)
- Total `3-5`: Route B (`hybrid`)
- Total `>=6`: Route C (`plugin`)

Hard rule:
- If Route A parity fails at validation, auto-downgrade to Route B or C.

Route helper script:

```bash
php scripts/decide_delivery_route.php --source /path/to/source.html --json
```

## Step 1: Collect Inputs And Constraints

Collect all required sources before writing code:
- AI demo source file(s): HTML/React output.
- Optional existing Elementor JSON for in-place patch mode.
- Existing ACF exports (`.json`) and field docs.
- Runtime constraints: theme, Elementor, WooCommerce, popup behavior.
- Delivery mode: append to existing template vs full override.

Load `references/question-flow.md` and run interview prompts in order.

Hard rule:
- If user says fields already exist, inspect export JSON first.
- Avoid assuming missing fields before checking provided ACF files.

## Step 2: Build Section Inventory

Create a section table from the demo and mark each block as:
- `static`: no per-category/per-product variation.
- `dynamic`: varies by taxonomy/product/page context.
- `mixed`: static layout with dynamic text/media.

For each dynamic or mixed section, define:
- Data owner: taxonomy (`product_cat`) vs product (`product`) vs global option.
- Field type: text, textarea, image, file, URL, repeater, WYSIWYG.
- Fallback policy: strict-empty vs demo-default fallback.
- Variability pattern: literal text, derived title, optional caption, optional section, list cardinality, query scope.

Hard rule:
- Infer likely-variable fields proactively and confirm before coding.

## Step 3: Run Field-Reuse Interview

Ask and confirm:
- Which fields must be reused exactly.
- Which fields are new.
- Which sections must hide when data is empty.
- Which controls are external integrations (popup ID, form target, etc).
- Whether fallback should be globally togglable.
- Whether cards are fixed-count or taxonomy-scoped dynamic.

Use `references/question-flow.md`.

## Step 4: Generate ACF Import JSON

When new fields are required, generate deterministic ACF import JSON.

Use:
- `scripts/generate_acf_import.py`
- `assets/acf-import-config.sample.json`

Then:
- Review groups and field names.
- Provide JSON to user.
- Wait for confirmation fields are imported.

## Step 5: Implement By Route

### Route A: Elementor JSON

- Generate valid Elementor JSON structure.
- Preserve official keys (`id`, `elType`, `isInner`, `settings`, `elements`, plus `widgetType` on widgets, and page-level `page_settings`/`content` where applicable).
- Keep unknown control keys untouched when patching.
- Prefer Elementor-native controls first; if parity risk is high, route to hybrid.

### Route B: Hybrid (Recommended For Hard Parity)

- Keep major layout editable in Elementor.
- Render parity-critical blocks through plugin shortcode/widget.
- Scope CSS/JS per block to avoid theme/Elementor collision.

### Route C: Full Plugin

Minimum layout:
- Main plugin bootstrap.
- `includes/data.php` for field loading/normalization.
- `includes/render-sections.php` for markup.
- `assets/css` and `assets/js` with scoped behavior.
- Admin settings for fallback and render mode.
- Shortcode entry points for manual placement.

Performance rule:
- Enqueue assets only on target pages.

## Step 5A: Existing Elementor JSON Patch Mode

When user provides an existing JSON file:
- Parse and build node index by `id`, `elType`, `widgetType`, tree path.
- Apply targeted operations only:
  - copy edit
  - section/widget insert/remove/move/duplicate
  - layout change (direction/gap/alignment/width)
  - style change (color/typography/spacing/border/shadow)
  - motion change (entrance/hover/scroll controls when supported)
- Emit patched JSON + change log (path, key, old/new).

Patch helper script:

```bash
# 先 dry-run 看变更和报错
php scripts/apply_elementor_patch.php \
  --input /path/original.json \
  --patch /path/ops.json \
  --dry-run \
  --log /path/patch-log.json

# 确认无误后再输出 patched 文件
php scripts/apply_elementor_patch.php \
  --input /path/original.json \
  --patch /path/ops.json \
  --output /path/patched.json \
  --log /path/patch-log.json
```

Hard rule:
- Never rebuild the whole JSON for a patch request.
- Default mode is transactional (all-or-nothing). Use `--allow-partial` only for emergency/manual merge workflows.
- For non-technical users, use `patch_runner.php` (human-readable request -> auto ops -> dry-run report).

Patch runner helper:

```bash
php scripts/patch_runner.php \
  --input /path/original.json \
  --request-file /path/changes.txt \
  --report /path/preview-report.md \
  --ops-out /path/generated-ops.json
```

Apply after preview passes:

```bash
php scripts/patch_runner.php \
  --input /path/original.json \
  --request-file /path/changes.txt \
  --apply \
  --output /path/patched.json \
  --report /path/apply-report.md
```

## Step 6: Harden Visual Parity

Load `references/parity-checklist.md`.

Required hardening:
- Scope all styles under dedicated wrapper.
- Add defensive CSS for anchor/button/theme resets.
- Force consistent font stack in scope.
- Fix icon geometry (avoid stretch/oval).
- Preserve image/video aspect ratios.
- Add empty-state geometry guards.
- Match typography/spacing/shadows, not only colors.
- Reproduce interaction states from source demo.

## Step 7: Harden Interactions

Load `references/failure-matrix.md` and apply known fixes.

Critical checks:
- Popup hash handling.
- Hook fallback and footer placement.
- YouTube normalization and hide-invalid behavior.
- Dynamic heading/query scope from current taxonomy.
- No global text/ALT contamination across categories.
- Download/PDF action must bind to correct field mapping.

## Step 8: Validate And Package

Before claiming completion:
- Run syntax checks.
- Run smoke regression:
  - `bash tests/run-smoke.sh`
- Run import contract check before CSV import:
  - `php scripts/check_import_contract.php --csv /path/file.csv --acf /path/acf.json --must-have ID,slug --report /path/contract.md`
- Run SEO/fact guard only when requested (kept optional because some teams use a separate SEO plugin pipeline):
  - `php scripts/seo_fact_guard.php --csv /path/file.csv --report /path/guard.md`
- Run asset path rewrite when migrating static HTML:
  - `php scripts/rewrite_wp_asset_paths.php --input /path/site-html --output /path/site-wp-ready --site-root /path/site-html --mode token --report /path/asset-report.md`
- Generate Elementor skeleton JSON from static HTML:
  - `php scripts/html_to_elementor_skeleton.php --input /path/page.html --output /path/page.elementor.json --report /path/skeleton-report.md`
- Prefer one-command pipeline for final release package:
  - `php scripts/run_delivery_pipeline.php --category-csv /path/cat.csv --detail-csv /path/detail.csv --category-acf /path/cat-acf.json --detail-acf /path/detail-acf.json --out-dir /path/output --package-name final.zip`
- Enable SEO guard in pipeline only when needed:
  - `php scripts/run_delivery_pipeline.php ... --with-seo-guard`
- Validate final route decision.
- Validate Elementor JSON structure against official element model.
- Run parity checks and list known deviations.
- Verify fallback behavior in strict-empty mode.
- Verify taxonomy/query scope correctness.
- Verify SEO intent split (category commercial intent vs detail spec intent).

Elementor JSON validator script:

```bash
php scripts/validate_elementor_json.php --input /path/page.json --strict --report /path/validation-report.json
```

Package by route:
- Route A: `elementor-template.json` (or kit zip if requested).
- Route B: `plugin.zip` + optional companion `elementor-template.json`.
- Route C: `plugin.zip`.

Provide:
- Artifact path(s)
- Install/import steps
- Change log
- Verification checklist

## Required Outputs

Always provide:
- Route decision summary
- Output artifact path(s)
- ACF mapping table
- ACF import JSON (if new fields added)
- Known fallback/deviation list
- Test checklist and feedback template

## References

Load progressively:
- `references/question-flow.md`
- `references/parity-checklist.md`
- `references/failure-matrix.md`
- `references/feedback-template.md`
- `references/prompt-templates.md`
- `references/elementor-route-guide.md`
- `references/elementor-json-patch-spec.md`

## Resources

- `scripts/generate_acf_import.py`
- `scripts/decide_delivery_route.php`
- `scripts/validate_elementor_json.php`
- `scripts/apply_elementor_patch.php`
- `scripts/patch_runner.php`
- `scripts/check_import_contract.php`
- `scripts/seo_fact_guard.php`
- `scripts/rewrite_wp_asset_paths.php`
- `scripts/html_to_elementor_skeleton.php`
- `scripts/run_delivery_pipeline.php`
- `assets/acf-import-config.sample.json`
