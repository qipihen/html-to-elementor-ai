# Failure Matrix: Root Cause And Fix

## Symptom: Popup click refreshes page, popup does not open

Root cause:
- Popup hash URL encoded by `esc_url` (for example `%3A` in `#elementor-action:...`).

Fix:
- Render popup hash with attribute escaping (`esc_attr`) and preserve raw fragment.
- Bind JS click handler to call Elementor popup API directly when available.

## Symptom: Section does not appear on category archive

Root cause:
- `woocommerce_after_shop_loop` not fired by current archive template (common with Elementor archives).

Fix:
- Add fallback append path in `wp_footer` with render guard flag.

## Symptom: Layout appears after footer

Root cause:
- Fallback render hooked to `wp_footer` is active while primary hook did not run.

Fix:
- Make hook position configurable from admin settings.
- Disable footer fallback by default.
- Provide shortcode/manual placement mode for Elementor templates.

## Symptom: Typography/colors/buttons differ from demo

Root cause:
- Theme CSS overrides plugin classes.
- Theme applies explicit font-family to headings/body elements inside product templates.

Fix:
- Add scoped wrapper selector.
- Add defensive scoped rules for `a`, `button`, and key components.
- Force unified font stack on text elements inside wrapper, then re-allow `.font-mono` where needed.

## Symptom: Card outline is unclear on light background

Root cause:
- Demo uses deeper panel elevation, but implementation uses mostly `shadow-sm`.
- Border color is too close to page background, reducing section separation.

Fix:
- Define dedicated panel token class (for example `guda-detail-panel`).
- Apply stronger shadow + slightly darker border to all major sections.
- Keep one stronger variant for hero container if demo depth is higher.

## Symptom: SVG icons become oval or stretched

Root cause:
- Parent flex/grid or theme CSS modifies svg dimensions.

Fix:
- Set fixed width/height/min/max in svg and icon wrapper.
- Keep icon wrapper with fixed circular dimensions.

## Symptom: FAQ plus/minus overlaps question text

Root cause:
- Button layout uses flex without fixed icon column.

Fix:
- Use 2-column grid layout for FAQ trigger: text column + icon column.
- Keep icon wrapper fixed width and non-shrinking.

## Symptom: Intro image becomes too tall/too short

Root cause:
- Aspect ratio tied to adjacent text block or fixed height mismatch.

Fix:
- Set explicit aspect ratio on image container by design requirement.

## Symptom: Hero banner collapses when background image is empty

Root cause:
- Hero height depends on image intrinsic size.
- Text/overlay layers are absolutely positioned, so container has no stable block height when image source is empty.

Fix:
- Apply a minimum-height token to hero container independent of image data.
- Render hero background image only when URL is present.
- Keep overlay/content in normal flow or with equivalent min-height so layout stays stable in strict-empty mode.

## Symptom: YouTube embed blocked or broken

Root cause:
- Non-embed URL format or malformed iframe source.

Fix:
- Normalize watch/short URLs to embed URL.
- Validate iframe allow attributes.

## Symptom: YouTube area becomes narrow vertical card or deforms under theme CSS

Root cause:
- Iframe width/height attributes overridden by theme/global iframe rules.
- Container has no enforced aspect-ratio.

Fix:
- Wrap iframe in a dedicated shell with enforced `aspect-ratio: 16/9`.
- Force iframe to `position:absolute; inset:0; width:100%; height:100%` with scoped class + `!important` where needed.
- If normalized URL is empty/invalid, hide the whole video block instead of rendering broken media.
- Add runtime JS enforcement for the shell/iframe dimensions after `DOMContentLoaded` and `load` to survive late theme/script overrides.

## Symptom: CSS fix is coded but frontend still shows old behavior

Root cause:
- Browser/CDN/plugin cache still serves previous CSS/JS asset versions.

Fix:
- Bump plugin version constant (or enqueue filemtime version) on each visual fix.
- Verify source URL query version changed on frontend before re-testing.

## Symptom: Unexpected long text in intro

Root cause:
- Wrong source mapped (taxonomy description vs dedicated field).

Fix:
- Define priority order for intro text fields and document it.

## Symptom: Top utility actions do not match target design

Root cause:
- Share/Print actions were copied from generic product demo, but target page does not require them.

Fix:
- Treat utility actions as optional module.
- Remove markup and listener bindings when project spec excludes them.

## Symptom: Category page heading is wrong for non-default term

Root cause:
- Section title was hardcoded to one category name (for example `Applications for Low Voltage Cables`).

Fix:
- Build heading from current term name token.
- Apply agreed title transform rules (pluralization/suffix) only where specified.

## Symptom: Hero and intro second title line use the same wording but should differ

Root cause:
- Single transformed variable reused for both lines (for example both become `Medium Voltage Cables`).

Fix:
- Split title builders by context:
- Hero: use pluralized `... Cables`.
- Intro/right heading: use singular + `solutions` suffix (for example `Medium Voltage Cable solutions`).
- Keep transforms idempotent to avoid double suffix/plural.

## Symptom: Packaging caption is incorrect for some categories

Root cause:
- Intro image caption was hardcoded or forced by demo fallback.

Fix:
- Add dedicated optional field (for example `intro_image_caption`).
- If field is empty, render no caption and no caption container.

## Symptom: Product cards show unrelated products or fixed placeholders

Root cause:
- Query fallback used demo list or fixed-card count regardless of term inventory.

Fix:
- Query by current taxonomy term only.
- Set default limit to all products in term; make limit optional/configurable.
- Use demo product fallback only when explicitly enabled.

## Symptom: Non-universal blocks appear on categories without data

Root cause:
- Section rendering ignored data emptiness and always showed fallback blocks and titles.

Fix:
- Add strict-empty mode: hide full section and section title when block data is absent.
- Add admin-level toggle to control demo fallback independently for category/detail pages.

## Symptom: Demo fallback toggle is off but demo blocks still render

Root cause:
- Renderer merged defaults unconditionally (for example `array_replace_recursive($defaults, $data)`), repopulating empty sections after data-layer filtering.

Fix:
- Keep fallback resolution in data layer only.
- In renderer, merge only shape defaults (empty arrays/strings), never demo content defaults.
- Gate each section by final data emptiness (`products`, `applications`, `faqs`, `standards`, `model reference`, `youtube/key specs`).

## Symptom: Category banner field exists in ACF editor but frontend still shows no banner

Root cause:
- Taxonomy field retrieval uses wrong context key (term object vs `term_{id}` vs `{taxonomy}_{id}`).

Fix:
- Resolve banner field by priority: term object -> `term_{id}` -> `{taxonomy}_{id}`.
- Add frontend debug marker/log when all banner lookups are empty.

## Symptom: Key specification icons render as plain circles

Root cause:
- Icon data not mapped (empty icon slug/class), while layout reserves circular placeholders.

Fix:
- Define explicit icon field mapping and fallback icon set.
- If icon data is empty and fallback disabled, hide icon node instead of showing empty circle.

## Symptom: Category download PDF button opens taxonomy page URL instead of PDF file

Root cause:
- Button href fallback points to term permalink when `cat_pdf` mapping fails.

Fix:
- Bind button strictly to file field URL (`cat_pdf`).
- If file is empty, disable/hide button; do not silently fall back to permalink.

## Symptom: YouTube play button jitters during scroll

Root cause:
- Multiple transforms/animations are applied to the same center overlay element by CSS/JS/theme.

Fix:
- Keep center button transform static (`translate(-50%, -50%)`) and animate only opacity/scale in one place.
- Remove duplicate transform animations from scroll handlers and hover rules.

## Symptom: Every page shows the same compliance sentence or ALT suffix (`low voltage cable`)

Root cause:
- Global fallback copy injected across all categories/details.

Fix:
- Move standards intro and ALT templates to per-category fields (for example `standards_intro_text`).
- Remove hardcoded global fallback sentence and enforce context-local copy generation.

## Symptom: Detail pages use commercial-intent keywords and cannibalize category pages

Root cause:
- SEO generation logic reuses category keyword template on detail records.

Fix:
- Split keyword intent:
- Category pages: head terms + commercial modifiers (manufacturer/supplier/factory).
- Detail pages: model/spec modifiers (voltage, conductor, insulation, armor, standards).
- Add automated lint rule to reject category-only commercial phrases on detail templates.

## Symptom: Elementor JSON imports but frontend layout is broken

Root cause:
- JSON element tree invalid (wrong nesting between container/section/widget) or missing required keys.

Fix:
- Validate each node contains official core keys (`id`, `elType`, `settings`, `elements`, and `widgetType` for widgets).
- Re-check parent-child structure before output.

## Symptom: Existing Elementor JSON patch wipes unrelated styles

Root cause:
- Full regeneration replaced untouched nodes; unknown settings keys dropped.

Fix:
- Use patch mode, not regenerate mode.
- Preserve untouched subtrees and unknown keys.
- Emit field-level change log.

## Symptom: Elementor-native animation cannot match source parity

Root cause:
- Source motion requires custom timeline/runtime beyond Elementor control model.

Fix:
- Route downgrade from `elementor-json` to `hybrid`.
- Keep editable shell in Elementor and move hard-motion block to plugin runtime.

## Symptom: Hybrid block appears correct in editor but breaks on live page

Root cause:
- Theme or Elementor frontend CSS/JS overrides plugin block behavior.

Fix:
- Scope plugin block CSS/JS strictly to wrapper class.
- Avoid global selectors and enforce container-level reset.

## Symptom: Elementor template import succeeds but content not editable as expected

Root cause:
- Complex interactions were encoded in raw HTML/JS instead of Elementor controls.

Fix:
- Keep editable content in Elementor control-backed widgets.
- Move non-editable advanced runtime logic to plugin block and document boundary.
