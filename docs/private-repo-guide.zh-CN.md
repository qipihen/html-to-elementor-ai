# 私有仓库接入与发布指南（GitHub）

本文用于把 `Elementor AI Capture Studio` 安全托管到私有 GitHub 仓库，并支持团队协作。

## 1. 目标

- 仓库保持 `Private`
- 只允许授权成员访问
- 文档可执行（安装、捕获、故障处理）
- 不泄露 API Key、业务截图、导出 JSON

## 2. 推荐仓库结构

```text
.
├── README.md
├── docs/
│   ├── usage-guide.zh-CN.md
│   ├── fidelity-playbook.zh-CN.md
│   └── private-repo-guide.zh-CN.md
├── content-ui/
├── lib/
├── options/
├── export/
├── manifest.json
└── scripts/
```

## 3. 创建私有仓库

### 方式 A：GitHub 网页

1. 新建仓库
2. 选择 `Private`
3. 关闭公开可见性
4. 创建后复制仓库地址（HTTPS/SSH）

### 方式 B：GitHub CLI

```bash
gh repo create <your-org-or-user>/<repo-name> --private --source=. --remote=origin --push
```

## 4. 本地关联远程

```bash
git remote set-url origin <your-private-repo-url>
git push -u origin main
```

> 若默认分支不是 `main`，替换为你的分支名。

## 5. 安全设置（强烈建议）

1. 开启主分支保护（Branch protection）。
2. 限制直接 push 到主分支。
3. 开启 Secret scanning / Push protection。
4. 团队账号启用 2FA。
5. 禁止提交真实 API Key 与客户私有资料。

## 6. `.gitignore` 必查

必须排除：

- 本地调试日志
- 中间导出 JSON（含业务数据）
- 临时截图与录屏
- 凭据文件（如 `.env`）

## 7. 内部发布流程

1. 本地测试：

```bash
npm test
```

2. 生成安装包：

```bash
npm run package
```

3. 产物路径：

- `dist/elementor-ai-capture-studio-v<version>.zip`

4. 团队安装：
- 解压后 `Load unpacked`
- 或从源码目录直接 `Load unpacked`

## 8. 文档维护规范

每次涉及用户流程变更，必须同步更新：

1. `README.md`
2. `docs/usage-guide.zh-CN.md`
3. `docs/fidelity-playbook.zh-CN.md`（若影响高还原流程）

## 9. 发布前检查清单（可复制）

1. 功能检查：`Capture -> Ctrl+V` 可用。
2. 复杂区块检查：至少验证一个含交互的区块。
3. AI Enhance 检查：捕获模式与 JSON-only 模式都能运行。
4. 测试通过：`npm test`。
5. 打包成功：`npm run package`。
6. 文档已更新（README + usage + fidelity）。
7. 仓库确认仍是 `Private`。
