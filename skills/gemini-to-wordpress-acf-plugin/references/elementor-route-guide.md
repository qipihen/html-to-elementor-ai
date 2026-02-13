# Elementor Route Guide (Official-First)

This guide captures official Elementor references used by this skill.

## Official References

- General element model:
  - https://developers.elementor.com/docs/data-structure/general-elements/
- Widget element model:
  - https://developers.elementor.com/docs/data-structure/widget-element/
- Container element model:
  - https://developers.elementor.com/docs/data-structure/container-element/
- Page settings model:
  - https://developers.elementor.com/docs/data-structure/page-settings/
- Page content model:
  - https://developers.elementor.com/docs/data-structure/page-content/
- Template library import/export (UI):
  - https://elementor.com/help/export-import-elementor-templates/
- Site kit export/import (UI):
  - https://elementor.com/help/import-export-kit/
- WP-CLI kit import/export:
  - https://developers.elementor.com/docs/cli/kit-import-export/

## Canonical JSON Keys (Must Preserve)

At minimum preserve:
- `id`
- `elType`
- `settings`
- `elements`

When applicable:
- `widgetType` (widget nodes)
- `isInner`
- page-level `page_settings`
- page-level `content`

## Route Rules

- Use `elementor-json` when layout+motion can be represented with Elementor controls and parity is acceptable.
- Use `hybrid` when structure is editable in Elementor but one or more blocks need custom runtime parity.
- Use `plugin` when data/runtime complexity is high or behavior is reusable across many pages.

## Patch Mode Rules (Existing JSON)

- Patch in place, do not regenerate whole file.
- Preserve unknown keys and untouched subtrees.
- Always output change log with target path and changed keys.

## Known Documentation Gaps

Some Elementor developer pages are marked as work-in-progress.
When schema ambiguity exists:
- Prefer structure from official docs above.
- Validate against a known-good JSON exported from target Elementor version.
- Preserve existing keys from exported JSON when patching.
