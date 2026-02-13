# Skills 目录说明（中文）

本目录用于存放可复用的交付技能（Skill）。

当前包含：
- `gemini-to-wordpress-acf-plugin/`

该 Skill 支持三条交付路径：
1. `elementor-json`：单页静态优先，交付快
2. `hybrid`：Elementor 布局 + 插件渲染复杂区块
3. `plugin`：复杂动态逻辑，完整插件交付

并支持第四种工作模式：
- 读取**已有 Elementor JSON**后做定向 patch（文案/布局/样式/动效参数）

## 快速命令

### 1) 路由判定

```bash
php skills/gemini-to-wordpress-acf-plugin/scripts/decide_delivery_route.php \
  --source /path/to/source.html \
  --json
```

### 2) 校验 Elementor JSON

```bash
php skills/gemini-to-wordpress-acf-plugin/scripts/validate_elementor_json.php \
  --input /path/page.json \
  --strict \
  --report /path/validation-report.json
```

### 3) 对已有 JSON 打补丁

```bash
# 先 dry-run 预检查
php skills/gemini-to-wordpress-acf-plugin/scripts/apply_elementor_patch.php \
  --input /path/original.json \
  --patch /path/ops.json \
  --dry-run \
  --log /path/patch-log.json

# 再正式输出 patched JSON
php skills/gemini-to-wordpress-acf-plugin/scripts/apply_elementor_patch.php \
  --input /path/original.json \
  --patch /path/ops.json \
  --output /path/patched.json \
  --log /path/patch-log.json
```

补丁格式参考：
- `skills/gemini-to-wordpress-acf-plugin/references/elementor-json-patch-spec.md`

### 4) 回归自测

```bash
bash skills/gemini-to-wordpress-acf-plugin/tests/run-smoke.sh
```

### 5) 非技术同学一键改 JSON（推荐）

先写一个变更文本（每行一条）：

```text
set heading1 title = "New Heading"
删除 old_section_01
移动 cta_block 到 __root__ 位置 end
```

预演（不落盘）：

```bash
php skills/gemini-to-wordpress-acf-plugin/scripts/patch_runner.php \
  --input /path/original.json \
  --request-file /path/changes.txt \
  --report /path/preview-report.md \
  --ops-out /path/generated-ops.json
```

确认后应用：

```bash
php skills/gemini-to-wordpress-acf-plugin/scripts/patch_runner.php \
  --input /path/original.json \
  --request-file /path/changes.txt \
  --apply \
  --output /path/patched.json \
  --report /path/apply-report.md
```

### 6) 导入前契约检查

```bash
php skills/gemini-to-wordpress-acf-plugin/scripts/check_import_contract.php \
  --csv /path/import.csv \
  --acf /path/acf-export.json \
  --must-have ID,slug \
  --report /path/contract-check.md
```

### 7) SEO/事实防错检查

```bash
php skills/gemini-to-wordpress-acf-plugin/scripts/seo_fact_guard.php \
  --csv /path/import.csv \
  --report /path/seo-fact-guard.md
```

### 8) 一键串联（分类+详情）并打包

```bash
php skills/gemini-to-wordpress-acf-plugin/scripts/run_delivery_pipeline.php \
  --category-csv /path/category.csv \
  --detail-csv /path/detail.csv \
  --category-acf /path/category-acf.json \
  --detail-acf /path/detail-acf.json \
  --out-dir /path/pipeline-output \
  --package-name final-import-bundle.zip
```

说明：
- 默认只做“页面构建导入链路”检查（contract），不做 Meta/SEO 规则拦截。
- 需要时再加 `--with-seo-guard` 开启 SEO/事实守卫。

输出目录会包含：
- `summary.md`：总览结果（PASS/FAIL）
- `manifest.json`：机器可读结果
- `category/` 和 `detail/`：输入CSV、最终CSV、contract/seo报告
- `final-import-bundle.zip`：可交付压缩包

### 9) 静态 HTML 资源路径改写（迁移到 WordPress）

```bash
php skills/gemini-to-wordpress-acf-plugin/scripts/rewrite_wp_asset_paths.php \
  --input /path/static-html \
  --output /path/wp-ready-html \
  --site-root /path/static-html \
  --mode token \
  --report /path/asset-rewrite-report.md
```

说明：
- 把本地资源引用改成 `{{THEME_URI}}/...`（或 `--mode theme-php`）
- 外链保持不变
- 非资源页面链接（如 `about.html`）保持不变

### 10) HTML 一键转 Elementor 骨架 JSON

```bash
php skills/gemini-to-wordpress-acf-plugin/scripts/html_to_elementor_skeleton.php \
  --input /path/page.html \
  --output /path/page.elementor.json \
  --report /path/skeleton-report.md
```

说明：
- 输出可导入 Elementor 的基础结构（container/widget）
- 主打“先搭骨架再微调”，不是像素级样式复刻

## 官方参考（Elementor）

- General Element:
  - https://developers.elementor.com/docs/data-structure/general-elements/
- Widget Element:
  - https://developers.elementor.com/docs/data-structure/widget-element/
- Container Element:
  - https://developers.elementor.com/docs/data-structure/container-element/
- Page Content:
  - https://developers.elementor.com/docs/data-structure/page-content/
- Page Settings:
  - https://developers.elementor.com/docs/data-structure/page-settings/

## 建议实践

- 单页先走 `elementor-json`，不达标自动降级到 `hybrid`。
- 有筛选/查询/多页面复用，直接走 `plugin`。
- patch 模式优先，避免每次全量重建 JSON。
- patch 默认“事务模式”（全成或全不成）；只有明确接受部分成功时才用 `--allow-partial`。
