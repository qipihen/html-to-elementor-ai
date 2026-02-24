# Elementor AI Capture Studio

一个用于捕获网页区块并转换为 Elementor 可粘贴 JSON 的 Chrome 扩展。  
当前版本：`1.1.3`

## 核心定位

- 主流程：`Capture -> Ctrl+V 到 Elementor`
- AI：可选增强，不是必须步骤
- 复杂区块：优先保真（必要时会落为 HTML Widget）

## 安装（Chrome）

1. 打开 `chrome://extensions/`
2. 开启右上角 `Developer mode`
3. 点击 `Load unpacked`
4. 选择项目目录：`html-to-elementor-ai`
5. 固定扩展图标

如果之前安装过旧商店版，请先禁用/卸载旧版，避免冲突。

## 3 步最短流程

1. 打开目标页面，先让目标区块完整可见（懒加载图片先滚出来）。
2. 打开 `Capture Hub`，点击 `Start Capture`，在页面点击目标区块。
3. 切到 Elementor，直接 `Ctrl+V`。

## 什么时候用 AI

- 推荐：先捕获粘贴，只有差异较大时再用 `AI Enhance`。
- `AI Enhance` 现在支持两种模式：
  - 捕获 + Base JSON
  - 仅 Base JSON（JSON-only）

## 文档索引（实操优先）

- 详细使用手册：`docs/usage-guide.zh-CN.md`
- 高还原作业手册（截图复刻 -> 再转 Elementor）：`docs/fidelity-playbook.zh-CN.md`
- 私有仓库接入与发布：`docs/private-repo-guide.zh-CN.md`

## 开发命令

```bash
npm test
npm run package
```

打包输出：

- `dist/elementor-ai-capture-studio-v<version>.zip`
