# Visual Parity Checklist

Use this checklist before declaring completion.

## Global

- Root wrapper class exists and scopes all plugin styling.
- Theme-level typography does not override section typography.
- Text elements (`h1-h6`, `p`, `span`, `a`, `li`, `th`, `td`, `button`) inherit one unified font stack inside scope.
- Color palette matches demo hex values.
- Spacing and corner radius match demo.
- Hover and transition timing match demo.

## Hero

- Hero image display mode matches requirement (full height vs cropped).
- Hero keeps agreed minimum height even when background image field is empty.
- Overlay gradient direction/intensity matches demo.
- Headline line breaks and font weights match demo.

## Intro + CTA Card

- Left image aspect ratio matches demo.
- CTA primary and secondary button colors and borders match demo.
- Hover text remains visible.
- Popup trigger works from CTA.

## Standards + Model Reference + Key Specs

- Outer panel border and shadow match demo.
- Panel edge remains clearly visible on light-gray page background.
- Table header typography/spacing match demo.
- Icon size does not stretch.

## Products

- Card geometry, shadows, and hover effects match demo.
- Circle icon wrappers remain circles on all breakpoints.
- Request Quote and View Details button styles match demo.

## FAQ

- Question and icon never overlap with long text.
- Card shadows match expected depth.
- Expand/collapse icon state and animation match demo.

## Integration

- Only target pages load plugin CSS/JS.
- Optional utility controls (share/print) follow project requirement and are removable without layout side effects.
- No duplicate footer if theme already has footer.
- Demo fallback content only appears by intended policy.
- Strict-empty mode does not deform surviving blocks; empty sections are hidden, and required sections keep stable geometry.
- Video wrapper keeps 16:9 ratio under theme CSS overrides and hides block on invalid URL.
