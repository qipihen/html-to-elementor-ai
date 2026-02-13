#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SKILL_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PATCH_SCRIPT="$SKILL_DIR/scripts/apply_elementor_patch.php"
ROUTE_SCRIPT="$SKILL_DIR/scripts/decide_delivery_route.php"
VALIDATE_SCRIPT="$SKILL_DIR/scripts/validate_elementor_json.php"
RUNNER_SCRIPT="$SKILL_DIR/scripts/patch_runner.php"
CONTRACT_SCRIPT="$SKILL_DIR/scripts/check_import_contract.php"
GUARD_SCRIPT="$SKILL_DIR/scripts/seo_fact_guard.php"
PIPELINE_SCRIPT="$SKILL_DIR/scripts/run_delivery_pipeline.php"
ASSET_REWRITE_SCRIPT="$SKILL_DIR/scripts/rewrite_wp_asset_paths.php"
HTML_SKELETON_SCRIPT="$SKILL_DIR/scripts/html_to_elementor_skeleton.php"
FIXTURE_DIR="$SCRIPT_DIR/fixtures"

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

ok() { echo "[ok] $1"; }
fail() { echo "[fail] $1" >&2; exit 1; }

# 1) Valid patch must write output and exit 0.
php "$PATCH_SCRIPT" \
  --input "$FIXTURE_DIR/simple-page.json" \
  --patch "$FIXTURE_DIR/patch-valid-set.json" \
  --output "$TMP_DIR/out-valid.json" \
  --log "$TMP_DIR/log-valid.json" >/dev/null

[[ -f "$TMP_DIR/out-valid.json" ]] || fail "valid patch did not write output"
VALID_TITLE="$(php -r '$d=json_decode(file_get_contents($argv[1]), true); echo $d["content"][0]["elements"][0]["settings"]["title"] ?? "";' "$TMP_DIR/out-valid.json")"
[[ "$VALID_TITLE" == "Updated Heading" ]] || fail "valid patch output unexpected title: $VALID_TITLE"
ok "valid patch writes expected output"

# 2) Strict mode invalid move must rollback and not write output.
set +e
php "$PATCH_SCRIPT" \
  --input "$FIXTURE_DIR/simple-page.json" \
  --patch "$FIXTURE_DIR/patch-invalid-move.json" \
  --output "$TMP_DIR/out-invalid.json" \
  --log "$TMP_DIR/log-invalid.json" >/dev/null
STATUS=$?
set -e
[[ "$STATUS" -eq 2 ]] || fail "invalid move strict mode should exit 2, got $STATUS"
[[ ! -f "$TMP_DIR/out-invalid.json" ]] || fail "strict rollback should not write output file"
ROLLBACK_FLAG="$(php -r '$d=json_decode(file_get_contents($argv[1]), true); echo ($d["rolled_back"] ?? false) ? "1" : "0";' "$TMP_DIR/log-invalid.json")"
[[ "$ROLLBACK_FLAG" == "1" ]] || fail "strict rollback log flag missing"
ok "strict rollback blocks invalid move and skips output write"

# 3) --dry-run should work without --output and still return non-zero on error.
set +e
php "$PATCH_SCRIPT" \
  --input "$FIXTURE_DIR/simple-page.json" \
  --patch "$FIXTURE_DIR/patch-invalid-move.json" \
  --dry-run \
  --log "$TMP_DIR/log-dryrun.json" >/dev/null
STATUS=$?
set -e
[[ "$STATUS" -eq 2 ]] || fail "dry-run invalid move should exit 2, got $STATUS"
[[ ! -f "$TMP_DIR/out-dryrun.json" ]] || fail "dry-run should never write output file"
ok "dry-run works without output path"

# 4) --allow-partial should write partial output when one op fails.
set +e
php "$PATCH_SCRIPT" \
  --input "$FIXTURE_DIR/simple-page.json" \
  --patch "$FIXTURE_DIR/patch-partial.json" \
  --output "$TMP_DIR/out-partial.json" \
  --allow-partial \
  --log "$TMP_DIR/log-partial.json" >/dev/null
STATUS=$?
set -e
[[ "$STATUS" -eq 2 ]] || fail "allow-partial with one error should exit 2, got $STATUS"
[[ -f "$TMP_DIR/out-partial.json" ]] || fail "allow-partial should write output file"
PARTIAL_TITLE="$(php -r '$d=json_decode(file_get_contents($argv[1]), true); echo $d["content"][0]["elements"][0]["settings"]["title"] ?? "";' "$TMP_DIR/out-partial.json")"
[[ "$PARTIAL_TITLE" == "Partial Applied" ]] || fail "allow-partial should preserve successful prior op"
ok "allow-partial writes partial output as expected"

# 5) Route decision should not be triggered by generic "if (...)"
cat > "$TMP_DIR/source.txt" <<'TXT'
function demo() {
  if (true) {
    return "static block";
  }
}
TXT
ROUTE="$(php "$ROUTE_SCRIPT" --source "$TMP_DIR/source.txt" --json | php -r '$d=json_decode(stream_get_contents(STDIN), true); echo $d["route"] ?? "";')"
[[ "$ROUTE" == "elementor-json" ]] || fail "route detector overfitted generic syntax, got: $ROUTE"
ok "route detector avoids generic if() false positives"

# 6) patch_runner should convert request text and pass dry-run.
php "$RUNNER_SCRIPT" \
  --input "$FIXTURE_DIR/simple-page.json" \
  --request-file "$FIXTURE_DIR/request-simple.txt" \
  --report "$TMP_DIR/runner-report.md" \
  --ops-out "$TMP_DIR/generated-ops.json" >/dev/null
[[ -f "$TMP_DIR/runner-report.md" ]] || fail "patch_runner did not write preview report"
grep -q "Dry-run status: \`PASSED\`" "$TMP_DIR/runner-report.md" || fail "patch_runner dry-run did not pass"
ok "patch_runner dry-run succeeds from human-readable request"

# 7) import contract checker should pass for valid fixture.
php "$CONTRACT_SCRIPT" \
  --csv "$FIXTURE_DIR/import-valid.csv" \
  --acf "$FIXTURE_DIR/acf-sample.json" \
  --must-have "ID,slug" \
  --report "$TMP_DIR/contract-report.md" >/dev/null
[[ -f "$TMP_DIR/contract-report.md" ]] || fail "contract checker report missing"
grep -q 'Status: `PASS`' "$TMP_DIR/contract-report.md" || fail "contract checker did not pass valid fixture"
ok "import contract checker passes valid fixture"

# 8) seo/fact guard should detect known critical issues.
set +e
php "$GUARD_SCRIPT" \
  --csv "$FIXTURE_DIR/seo-issues.csv" \
  --report "$TMP_DIR/guard-report.md" >/dev/null
STATUS=$?
set -e
[[ "$STATUS" -eq 2 ]] || fail "seo/fact guard should return 2 for critical findings, got $STATUS"
grep -q "CRITICAL_FOUND" "$TMP_DIR/guard-report.md" || fail "seo/fact guard report missing critical marker"
ok "seo/fact guard catches critical issues"

# 9) delivery pipeline should pass and generate package on clean input.
PIPE_OUT="$TMP_DIR/pipeline-pass"
php "$PIPELINE_SCRIPT" \
  --category-csv "$FIXTURE_DIR/import-valid.csv" \
  --detail-csv "$FIXTURE_DIR/import-detail-valid.csv" \
  --category-acf "$FIXTURE_DIR/acf-sample.json" \
  --detail-acf "$FIXTURE_DIR/acf-sample.json" \
  --out-dir "$PIPE_OUT" \
  --package-name "bundle.zip" >/dev/null
[[ -f "$PIPE_OUT/bundle.zip" ]] || fail "pipeline pass run did not generate bundle.zip"
grep -q 'Status: `PASS`' "$PIPE_OUT/summary.md" || fail "pipeline pass summary status mismatch"
ok "delivery pipeline passes on clean fixtures"

# 10) delivery pipeline should pass on critical-SEO fixture when SEO guard is disabled (default).
php "$PIPELINE_SCRIPT" \
  --category-csv "$FIXTURE_DIR/seo-issues.csv" \
  --out-dir "$TMP_DIR/pipeline-seo-skipped" \
  --package-name "bundle.zip" >/dev/null
grep -q 'Status: `PASS`' "$TMP_DIR/pipeline-seo-skipped/summary.md" || fail "pipeline should pass when SEO guard is disabled"
ok "delivery pipeline skips SEO guard by default"

# 11) delivery pipeline should fail gate on critical SEO when guard is enabled.
set +e
php "$PIPELINE_SCRIPT" \
  --category-csv "$FIXTURE_DIR/seo-issues.csv" \
  --with-seo-guard \
  --out-dir "$TMP_DIR/pipeline-fail" \
  --package-name "bundle.zip" >/dev/null
STATUS=$?
set -e
[[ "$STATUS" -eq 2 ]] || fail "pipeline should fail on critical SEO findings, got $STATUS"
grep -q 'Status: `FAIL`' "$TMP_DIR/pipeline-fail/summary.md" || fail "pipeline fail summary status mismatch"
ok "delivery pipeline blocks critical SEO findings when SEO guard is enabled"

# 12) asset path rewriter should convert local assets and keep external/page links.
php "$ASSET_REWRITE_SCRIPT" \
  --input "$FIXTURE_DIR/html-source/index.html" \
  --output "$TMP_DIR/index.rewritten.html" \
  --site-root "$FIXTURE_DIR/html-source" \
  --mode token \
  --report "$TMP_DIR/asset-report.md" >/dev/null

grep -q '{{THEME_URI}}/css/main.css' "$TMP_DIR/index.rewritten.html" || fail "asset rewriter did not rewrite stylesheet href"
grep -q '{{THEME_URI}}/images/hero.jpg' "$TMP_DIR/index.rewritten.html" || fail "asset rewriter did not rewrite image src"
grep -q 'https://cdn.example.com/keep.jpg' "$TMP_DIR/index.rewritten.html" || fail "asset rewriter should keep external URL unchanged"
grep -q 'href="about.html"' "$TMP_DIR/index.rewritten.html" || fail "asset rewriter should keep page link href unchanged"
ok "asset path rewriter works for local assets and preserves external/page links"

# 13) html->elementor skeleton generator should produce valid Elementor JSON.
php "$HTML_SKELETON_SCRIPT" \
  --input "$FIXTURE_DIR/html-source/index.html" \
  --output "$TMP_DIR/index.skeleton.json" \
  --report "$TMP_DIR/index.skeleton.md" >/dev/null

php "$VALIDATE_SCRIPT" --input "$TMP_DIR/index.skeleton.json" --strict >/dev/null
grep -q '"widgetType": "heading"' "$TMP_DIR/index.skeleton.json" || fail "skeleton should contain heading widget from <h*> tags when present"
grep -q '"widgetType": "image"' "$TMP_DIR/index.skeleton.json" || fail "skeleton should contain image widget from <img>"
ok "html to elementor skeleton generator outputs valid structure"

echo "All smoke tests passed."
