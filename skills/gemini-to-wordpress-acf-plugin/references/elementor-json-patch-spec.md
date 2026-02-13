# Elementor JSON Patch Spec

Use this spec with `scripts/apply_elementor_patch.php`.

## Patch Document Shape

```json
{
  "operations": [
    { "op": "update_settings", "element_id": "abc123", "settings": {"title":"New"} },
    { "op": "set_setting", "element_id": "abc123", "path": "typography.font_size.size", "value": 32 },
    { "op": "replace_text", "element_id": "abc123", "path": "title", "find": "Old", "replace": "New" },
    { "op": "remove_element", "element_id": "abc123" },
    { "op": "move_element", "element_id": "abc123", "new_parent_id": "parent001", "position": "end" },
    { "op": "duplicate_element", "element_id": "abc123", "new_parent_id": "parent001", "position": "end" },
    { "op": "insert_element", "parent_id": "parent001", "position": "end", "element": {"id":"newx1","elType":"widget","widgetType":"heading","settings":{"title":"Hello"},"elements":[]} }
  ]
}
```

## Supported Ops

- `update_settings`
  - Merge object into `node.settings` (recursive)
- `set_setting`
  - Set a nested value in `node.settings` by dot-path
- `replace_text`
  - String replace on one `node.settings` field
- `remove_element`
  - Remove one element by id
- `move_element`
  - Move element to another parent/root
- `duplicate_element`
  - Deep copy element and regenerate all ids recursively
- `insert_element`
  - Insert a new element under parent/root

## Position Values

- `start`
- `end`
- integer index (e.g. `0`, `3`)
- `after` (for `duplicate_element` in same parent)

## Reserved Parent Id

- `__root__` => top-level elements list
- `__same_parent__` => same parent (only `duplicate_element`)

## Notes

- Patch mode preserves untouched nodes and unknown keys.
- If inserted id already exists, script auto-generates a new unique id.
- Default behavior is transactional (all-or-nothing): if any op fails, no patched file is written.
- Use `--allow-partial` only when you explicitly accept partial success output.
- Use `--dry-run` first, then apply to output file after reviewing change log.
- `move_element` now blocks self/descendant moves to prevent tree corruption.
- Always review generated change log before importing into Elementor.
- If you do not want to write ops JSON manually, use `scripts/patch_runner.php` to generate ops from simple request lines.
