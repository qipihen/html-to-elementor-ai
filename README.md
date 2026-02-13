# HTML To Elementor 浏览器插件完整文档

## 一、项目概述

### 1.1 项目简介

**HTML To Elementor** 是一个 Chrome 浏览器扩展程序（Chrome Extension），版本号 1.1.3，采用 Manifest V3 架构。该插件的主要功能是帮助用户将任意网页的 HTML 元素或区块转换为 WordPress Elementor 页面构建器兼容的小部件（Widget）。

### 1.2 核心价值

- **设计迁移工具**：帮助设计师和开发者将现有网页设计快速转换为 Elementor 可用的组件
- **提升工作效率**：自动化提取 HTML 和 CSS，减少手动复制粘贴和样式重写的工作量
- **响应式测试支持**：内置设备视口模拟功能，方便测试不同屏幕尺寸下的显示效果

### 1.3 目标用户

- WordPress 开发者
- 使用 Elementor 的网站设计师
- 需要将现有 HTML 网站迁移到 WordPress 的用户
- 前端开发者（用于快速原型设计）

---

## 二、技术原理

### 2.1 整体架构

```
┌─────────────────────────────────────────────────────────────┐
│                     Chrome 浏览器                             │
├─────────────────────────────────────────────────────────────┤
│  ┌─────────────────┐      ┌─────────────────────────────┐  │
│  │   用户网页页面   │      │     扩展程序                  │  │
│  │                 │      │  ┌─────────────────────────┐ │  │
│  │  (content.js)  │◄────►│  │  后台服务 Worker       │ │  │
│  │                 │      │  │  (background.js)       │ │  │
│  │                 │      │  └─────────────────────────┘ │  │
│  └─────────────────┘      └─────────────────────────────┘  │
│           │                          │                       │
│           ▼                          ▼                       │
│     DOM 操作                  Chrome APIs                   │
│     元素捕获                   存储/调试                      │
└─────────────────────────────────────────────────────────────┘
```

### 2.2 消息通信机制

插件采用 Chrome 的消息传递系统实现各部分之间的通信：

```javascript
// 内容脚本 -> 后台服务 Worker
chrome.runtime.sendMessage({
  action: "elementClicked",
  data: elementData
});

// 后台服务 Worker -> 内容脚本
chrome.tabs.sendMessage(tabId, {
  action: "VIEWPORT_CHANGED",
  viewport: { width, height }
});
```

### 2.3 核心实现原理

#### 2.3.1 元素捕获原理

1. **事件监听**：内容脚本在页面中注入点击事件监听器
2. **DOM 提取**：获取被点击元素的完整 HTML 和样式信息
   - `element.outerHTML` - 获取完整 HTML 结构
   - `getComputedStyle(element)` - 获取所有计算后的 CSS 样式
   - `element.tagName`, `element.className`, `element.id` - 获取元素属性
3. **数据封装**：将提取的数据封装为结构化对象
4. **存储与通知**：通过 `chrome.storage.local` 存储，并发送消息通知 UI 更新

#### 2.3.2 CSS 转 Elementor 原理

1. **样式遍历**：遍历 `getComputedStyle()` 返回的所有 CSS 属性
2. **属性映射**：将 CSS 属性映射为 Elementor 控件：
   - `padding` → Elementor Padding 控件
   - `margin` → Elementor Margin 控件
   - `color` / `background-color` → Elementor 颜色控件
   - `font-size` / `font-family` → Elementor 排版控件
   - 等等...
3. **代码生成**：输出 Elementor 兼容的 JSON 格式或 HTML/CSS 代码
4. **复制输出**：用户可一键复制转换后的代码到剪贴板

#### 2.3.3 响应式视口模拟原理

使用 Chrome Debugger API 实现设备视口模拟：

1. **附加调试器**：
   ```javascript
   chrome.debugger.attach({ tabId }, "1.3")
   ```

2. **设置设备尺寸**：
   ```javascript
   chrome.debugger.sendCommand(
     { tabId },
     "Emulation.setDeviceMetricsOverride",
     { width: 375, height: 667, deviceScaleFactor: 2 }
   )
   ```

3. **清除设置**：
   ```javascript
   chrome.debugger.sendCommand(
     { tabId },
     "Emulation.clearDeviceMetricsOverride"
   )
   ```

### 2.4 Chrome APIs 使用

| API | 用途 | 权限要求 |
|-----|------|----------|
| `chrome.runtime.onMessage` | 内容脚本与后台通信 | 无 |
| `chrome.storage.local` | 本地数据持久化 | storage |
| `chrome.debugger` | 控制视口和设备模拟 | debugger |
| `chrome.tabs.sendMessage` | 向标签页发送消息 | activeTab |
| `chrome.action.onClicked` | 监听扩展图标点击 | 无 |
| `navigator.clipboard.writeText` | 复制到剪贴板 | clipboardWrite |

---

## 三、功能实现

### 3.1 核心功能列表

| 功能 | 描述 | 状态 |
|------|------|------|
| 元素捕获 | 点击页面元素，提取其 HTML 和 CSS | ✅ |
| 高亮显示 | 选中的元素有视觉标记 | ✅ |
| CSS 提取 | 获取元素的计算后样式 | ✅ |
| Elementor 转换 | 将 CSS 转换为 Elementor 控件配置 | ✅ |
| 代码复制 | 一键复制转换后的代码 | ✅ |
| 设备预览 | 模拟不同设备（手机/平板/桌面）尺寸 | ✅ |
| 自定义视口 | 设置任意宽高的视口 | ✅ |
| 数据持久化 | 保存捕获的元素数据 | ✅ |

### 3.2 使用流程

```
1. 安装扩展
       ↓
2. 点击扩展图标，启用捕获模式
       ↓
3. 在目标网页上点击要转换的元素
       ↓
4. 插件自动提取 HTML + CSS
       ↓
5. 转换为 Elementor 格式
       ↓
6. （可选）切换设备尺寸预览效果
       ↓
7. 复制代码到剪贴板
       ↓
8. 在 Elementor 编辑器中粘贴使用
```

### 3.3 UI 交互

- **浮动面板**：通过 Shadow DOM 注入 UI，与页面样式隔离
- **启用/禁用捕获**：点击扩展图标切换捕获模式
- **元素高亮**：选中元素显示半透明遮罩
- **代码预览**：在面板中显示转换后的代码
- **设备切换**：提供预设设备尺寸按钮

---

## 四、文件结构与说明

### 4.1 项目文件列表

```
html-to-elementor-project/
├── manifest.json                    # Chrome 扩展清单配置
├── background.iife.js              # 后台服务 Worker（压缩）
├── content-ui/
│   └── index.iife.js               # 内容脚本 + UI（压缩，1MB+）
├── _locales/
│   └── en/
│       └── messages.json           # 国际化字符串
├── _metadata/
│   ├── computed_hashes.json        # Chrome Web Store 验证哈希
│   └── verified_contents.json      # 已签名内容元数据
├── icon-256.png                    # 扩展图标（256x256）
└── html-to-elementor.zip           # 打包的扩展程序
```

### 4.2 文件详细说明

#### manifest.json

**作用**：Chrome 扩展程序的清单文件，定义扩展的基本信息、权限、入口点等。

**关键配置**：
- `manifest_version`: 3（使用 Chrome Manifest V3）
- `version`: 1.1.3
- `permissions`: storage, activeTab, debugger, clipboardWrite
- `content_scripts`: 自动注入到所有 HTTP/HTTPS/文件页面

#### background.iife.js

**作用**：后台服务 Worker，处理核心逻辑和 Chrome API 调用。

**主要功能**：
- 监听 `elementClicked` 消息，保存元素数据
- 处理视口设置请求（`setViewport`）
- 处理视口恢复请求（`restoreViewport`）
- 监听扩展图标点击，启用捕获模式
- 与 popup 通信（`updatePopup`）

**技术特点**：
- 已压缩为 IIFE 格式
- 包含 browser-polyfill 库
- 使用 Chrome Debugger API 进行视口控制

#### content-ui/index.iife.js

**作用**：内容脚本，负责页面元素捕获和 UI 渲染。

**主要功能**：
- 监听页面点击事件
- 提取 DOM 元素的 HTML 和 CSS
- 通过 Shadow DOM 渲染浮动操作面板
- 接收后台消息（视口变化、设备切换等）
- 高亮显示选中的元素

**技术特点**：
- 已压缩，文件大小 1MB+
- 包含完整的 UI 组件代码
- 包含 CSS 解析和 Elementor 代码生成逻辑

#### _locales/en/messages.json

**作用**：国际化字符串定义文件。

**内容**：
- 扩展名称和描述
- UI 字符串（loading, toggleTheme 等）
- 支持占位符替换

#### _metadata/ 目录

**作用**：Chrome Web Store 发布所需的验证和签名文件。

**文件**：
- `computed_hashes.json`：文件哈希值，用于验证完整性
- `verified_contents.json`：已签名的内容元数据

#### icon-256.png

**作用**：扩展程序图标，显示在 Chrome 工具栏中。

**尺寸**：256 x 256 像素

#### html-to-elementor.zip

**作用**：打包好的扩展程序，可直接安装到 Chrome 浏览器。

---

## 五、技术栈总结

| 类别 | 技术 |
|------|------|
| 扩展架构 | Chrome Manifest V3 |
| 编程语言 | JavaScript (ES6+) |
| 构建输出 | IIFE Bundle (已压缩) |
| 依赖库 | browser-polyfill |
| 分发平台 | Chrome Web Store |
| 目标平台 | Chrome/Chromium 内核浏览器 |

---

## 六、局限性

### 6.1 CSS 捕获局限

- ❌ 无法区分内联/嵌入/外链样式（只能获取计算后值）
- ❌ 丢失 CSS 优先级信息
- ❌ 无法捕获伪元素（::before, ::after）
- ❌ 无法捕获伪类（:hover, :focus）

### 6.2 动态内容局限

- ❌ JavaScript 动态渲染的内容无法捕获
- ❌ AJAX/React/Vue 加载的内容会丢失
- ❌ CSS 动画/过渡可能无法正确提取

### 6.3 外部资源局限

- ❌ 图片路径可能失效（相对路径问题）
- ❌ 自定义字体无法正确转换
- ❌ CDN 资源依赖可能断开

### 6.4 复杂布局局限

- ❌ 复杂 CSS Grid/Flexbox 布局支持有限
- ❌ CSS 变量（var()）可能无法解析
- ❌ 继承样式可能丢失

---

## 七、适合场景 vs 不适合场景

### ✅ 适合场景

- 简单静态页面的组件转换
- 基础按钮、卡片、列表等组件
- 固定布局的设计迁移
- 快速原型制作

### ❌ 不适合场景

- 复杂交互的应用（如 SPA）
- 动态内容页面（CMS 数据）
- 需要精确还原的精细设计
- 完整的网站迁移

---

## 八、总结

HTML To Elementor 是一个实用的 Chrome 扩展，专门为 WordPress/Elementor 开发者设计，用于快速将现有网页设计转换为 Elementor 可用的组件。

**核心价值**：自动化提取 HTML/CSS，减少手动工作

**技术特点**：采用 Chrome Manifest V3，使用 Debugger API 实现设备模拟

**使用建议**：作为辅助工具使用，复杂设计仍需手动调整

---

*文档版本：1.0*
*最后更新：2026-02-13*
