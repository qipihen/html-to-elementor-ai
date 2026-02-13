# Field Reuse Interview Flow

Run this flow before implementation.

## Mandatory Questions

1. Confirm delivery route:
- Route A (`elementor-json`), Route B (`hybrid`), or Route C (`plugin`)?
- If undecided, confirm complexity scoring (data/motion/runtime) and let route auto-decide.
- Define downgrade rule if Route A parity fails.

2. Confirm source scope:
- Which file is source-of-truth (AI HTML/React output, or existing Elementor JSON)?
- Is this new build or in-place patch of existing JSON?
- Which sections are in-scope for this delivery?

3. Confirm rendering strategy:
- Append below existing template?
- Full override?
- Shortcode/manual placement mode?
- Need admin UI for hook/position toggle?

4. Confirm field reuse:
- Which taxonomy fields already exist and must be reused exactly?
- Which product fields already exist and must be reused exactly?

5. Confirm new fields:
- Which missing fields should be added to ACF?
- Field type per missing item?
- Optional caption fields with empty=hide behavior?
- Should product count be configurable (`products_limit`, empty=no limit)?

6. Confirm behavior dependencies:
- Popup integration required (Elementor popup ID)?
- Download file behavior and target rules?
- Video source format and invalid URL behavior?
- Banner background source field and fallback rules?
- Key specification icon source and fallback rules?
- Related products title/query source: must follow current taxonomy, never hardcoded category.

7. Confirm title/SEO rules:
- Any category title transformations (pluralization, suffix `Solutions`)?
- Two-line headings require different transforms?
- SEO plugin in use (Yoast / Rank Math / AIOSEO)?
- Keyword anti-cannibalization split (category intent vs detail intent)?

8. Confirm fallback policy:
- Keep demo defaults for unfilled fields?
- Hide empty sections instead?
- Fallback only in data layer (no renderer default merge)?
- Need toggle for category/detail fallback?
- If section data is empty, hide section title too?
- Disable generic ALT/copy fallback contamination?

9. Confirm query scope and visibility:
- Product cards taxonomy-only?
- Card count default unlimited?
- Visibility: all users / logged-in / visitors only?

10. Confirm Elementor JSON patch operations (if patch mode):
- Allowed operations: copy, section move, layout tweak, style tweak, motion tweak.
- Node targeting method: by section label, widget type, or explicit element `id`.
- Preserve unknown settings keys? (default yes)

## Required Confirmation Format

Record final mapping in this format:

- `route`: elementor-json | hybrid | plugin
- `source_mode`: ai-code | existing-elementor-json
- `section`: Hero
- `data_owner`: taxonomy:product_cat
- `fields_reused`: hero_bg_image, intro_title, category_subtitle
- `fields_new`: feature_tags
- `fallback`: strict-empty | demo-default
- `derived_rules`: pluralize_title=true, append_suffix="Solutions"
- `empty_behavior`: hide_section=true, hide_title=true
- `query_scope`: taxonomy_only=true, limit=all
- `parity_gate`: pass_threshold=defined, fail_action=route_downgrade

Repeat for each section.

## Refusal Rule

Do not assume fields are missing before checking provided ACF export files.
