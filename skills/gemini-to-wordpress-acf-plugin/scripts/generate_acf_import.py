#!/usr/bin/env python3
"""
Generate ACF import JSON from a declarative config.

Usage:
  python3 generate_acf_import.py --config acf-config.json --output acf-import.json
"""

import argparse
import json
import sys
import uuid
from pathlib import Path


def _key(prefix: str) -> str:
    return f"{prefix}_{uuid.uuid4().hex[:16]}"


def _wrapper() -> dict:
    return {"width": "", "class": "", "id": ""}


def _base_field(cfg: dict) -> dict:
    return {
        "key": _key("field"),
        "label": cfg.get("label", cfg.get("name", "Field")),
        "name": cfg.get("name", ""),
        "aria-label": "",
        "type": cfg.get("type", "text"),
        "instructions": cfg.get("instructions", ""),
        "required": int(bool(cfg.get("required", False))),
        "conditional_logic": 0,
        "wrapper": _wrapper(),
    }


def build_field(cfg: dict) -> dict:
    field_type = cfg.get("type", "text")
    field = _base_field(cfg)

    if field_type in {"text", "email", "url"}:
        field.update(
            {
                "default_value": cfg.get("default_value", ""),
                "maxlength": cfg.get("maxlength", ""),
                "placeholder": cfg.get("placeholder", ""),
                "prepend": cfg.get("prepend", ""),
                "append": cfg.get("append", ""),
            }
        )
    elif field_type == "number":
        field.update(
            {
                "default_value": cfg.get("default_value", ""),
                "min": cfg.get("min", ""),
                "max": cfg.get("max", ""),
                "step": cfg.get("step", ""),
                "placeholder": cfg.get("placeholder", ""),
                "prepend": cfg.get("prepend", ""),
                "append": cfg.get("append", ""),
            }
        )
    elif field_type == "textarea":
        field.update(
            {
                "default_value": cfg.get("default_value", ""),
                "maxlength": cfg.get("maxlength", ""),
                "rows": int(cfg.get("rows", 3)),
                "placeholder": cfg.get("placeholder", ""),
                "new_lines": cfg.get("new_lines", "br"),
            }
        )
    elif field_type == "wysiwyg":
        field.update(
            {
                "default_value": cfg.get("default_value", ""),
                "tabs": cfg.get("tabs", "all"),
                "toolbar": cfg.get("toolbar", "full"),
                "media_upload": int(cfg.get("media_upload", 1)),
                "delay": int(cfg.get("delay", 0)),
            }
        )
    elif field_type == "image":
        field.update(
            {
                "return_format": cfg.get("return_format", "array"),
                "preview_size": cfg.get("preview_size", "medium"),
                "library": cfg.get("library", "all"),
                "min_width": cfg.get("min_width", ""),
                "min_height": cfg.get("min_height", ""),
                "min_size": cfg.get("min_size", ""),
                "max_width": cfg.get("max_width", ""),
                "max_height": cfg.get("max_height", ""),
                "max_size": cfg.get("max_size", ""),
                "mime_types": cfg.get("mime_types", ""),
            }
        )
    elif field_type == "file":
        field.update(
            {
                "return_format": cfg.get("return_format", "array"),
                "library": cfg.get("library", "all"),
                "min_size": cfg.get("min_size", ""),
                "max_size": cfg.get("max_size", ""),
                "mime_types": cfg.get("mime_types", ""),
            }
        )
    elif field_type == "true_false":
        field.update(
            {
                "message": cfg.get("message", ""),
                "default_value": int(cfg.get("default_value", 0)),
                "ui": int(cfg.get("ui", 1)),
                "ui_on_text": cfg.get("ui_on_text", "Yes"),
                "ui_off_text": cfg.get("ui_off_text", "No"),
            }
        )
    elif field_type == "select":
        field.update(
            {
                "choices": cfg.get("choices", {}),
                "default_value": cfg.get("default_value", False),
                "allow_null": int(cfg.get("allow_null", 0)),
                "multiple": int(cfg.get("multiple", 0)),
                "ui": int(cfg.get("ui", 0)),
                "return_format": cfg.get("return_format", "value"),
                "ajax": int(cfg.get("ajax", 0)),
                "placeholder": cfg.get("placeholder", ""),
            }
        )
    elif field_type == "repeater":
        sub_cfg = cfg.get("sub_fields", [])
        field.update(
            {
                "layout": cfg.get("layout", "row"),
                "button_label": cfg.get("button_label", "Add Row"),
                "min": cfg.get("min", 0),
                "max": cfg.get("max", 0),
                "collapsed": cfg.get("collapsed", ""),
                "sub_fields": [build_field(item) for item in sub_cfg],
            }
        )
    else:
        field.update({"default_value": cfg.get("default_value", "")})

    return field


def default_location(cfg: dict) -> list:
    if "location" in cfg:
        return cfg["location"]

    target = cfg.get("target", "taxonomy")
    if target == "post_type":
        post_type = cfg.get("post_type", "post")
        return [[{"param": "post_type", "operator": "==", "value": post_type}]]

    taxonomy = cfg.get("taxonomy", "product_cat")
    return [[{"param": "taxonomy", "operator": "==", "value": taxonomy}]]


def build_group(cfg: dict) -> dict:
    fields = [build_field(item) for item in cfg.get("fields", [])]

    return {
        "key": _key("group"),
        "title": cfg.get("title", "Generated Field Group"),
        "fields": fields,
        "location": default_location(cfg),
        "menu_order": int(cfg.get("menu_order", 0)),
        "position": cfg.get("position", "normal"),
        "style": cfg.get("style", "default"),
        "label_placement": cfg.get("label_placement", "top"),
        "instruction_placement": cfg.get("instruction_placement", "label"),
        "hide_on_screen": cfg.get("hide_on_screen", ""),
        "active": int(bool(cfg.get("active", True))),
        "description": cfg.get("description", ""),
        "show_in_rest": int(cfg.get("show_in_rest", 0)),
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="Generate ACF import JSON from config")
    parser.add_argument("--config", required=True, help="Path to JSON config")
    parser.add_argument("--output", required=True, help="Output JSON path")
    args = parser.parse_args()

    config_path = Path(args.config).expanduser().resolve()
    output_path = Path(args.output).expanduser().resolve()

    if not config_path.exists():
        print(f"ERROR: config file not found: {config_path}", file=sys.stderr)
        return 1

    try:
        cfg = json.loads(config_path.read_text(encoding="utf-8"))
    except Exception as exc:
        print(f"ERROR: failed to parse config JSON: {exc}", file=sys.stderr)
        return 1

    groups_cfg = cfg.get("groups", [])
    if not isinstance(groups_cfg, list) or not groups_cfg:
        print("ERROR: config must include non-empty 'groups' array", file=sys.stderr)
        return 1

    groups = [build_group(group_cfg) for group_cfg in groups_cfg]

    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(json.dumps(groups, ensure_ascii=False, indent=2), encoding="utf-8")

    print(f"OK: generated {len(groups)} group(s) -> {output_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
