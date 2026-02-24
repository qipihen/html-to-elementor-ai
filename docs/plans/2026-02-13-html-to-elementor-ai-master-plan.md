# HTML To Elementor AI 增强版 - 详细实施计划

**版本**: 1.0
**日期**: 2026-02-13
**状态**: 待开发

---

## 一、项目目标

在原有 HTML To Elementor 浏览器插件基础上，添加 AI 智能优化功能，并与 Claude Code Skill（gemini-to-wordpress-acf-plugin）形成混合工作流：

- **轻量任务** → 插件端独立完成（捕获、简单转换、AI 优化、补丁微调）
- **重量任务** → 导出到 Skill 端处理（ACF 字段生成、插件代码生成、批量处理）

---

## 二、技术架构

### 2.1 整体架构

```
┌─────────────────────────────────────────────────────────────────────────┐
│                           整体架构图                                     │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                         │
│   ┌─────────────────────────────────────────────────────────────────┐  │
│   │                    本地浏览器 (插件端)                            │  │
│   ├─────────────────────────────────────────────────────────────────┤  │
│   │                                                                 │  │
│   │   ┌─────────────┐    ┌─────────────┐    ┌─────────────┐        │  │
│   │   │   捕获模块   │───►│   识别模块   │───►│  转换模块   │        │  │
│   │   │  元素/CSS    │    │ 静态/动态    │    │  规则引擎   │        │  │
│   │   └─────────────┘    └─────────────┘    └──────┬──────┘        │  │
│   │                                                │                 │  │
│   │                                                ▼                 │  │
│   │   ┌─────────────┐    ┌─────────────┐    ┌─────────────┐        │  │
│   │   │   AI 增强    │◄───│   补丁模块   │◄───│   输出模块   │        │  │
│   │   │ OpenRouter  │    │ 用户微调     │    │ JSON/代码   │        │  │
│   │   └─────────────┘    └─────────────┘    └─────────────┘        │  │
│   │                                                                 │  │
│   └────────────────────────────┬────────────────────────────────────┘  │
│                                │                                       │
│                                │ 数据导出 (JSON/CSV)                   │
│                                ▼                                       │
│   ┌─────────────────────────────────────────────────────────────────┐  │
│   │                    Claude Code (Skill 端)                       │  │
│   ├─────────────────────────────────────────────────────────────────┤  │
│   │                                                                 │  │
│   │   ┌─────────────┐    ┌─────────────┐    ┌─────────────┐        │  │
│   │   │   批量处理   │───►│ ACF 生成    │───►│ 插件代码    │        │  │
│   │   │  路由判定    │    │  字段映射    │    │   生成      │        │  │
│   │   └─────────────┘    └─────────────┘    └─────────────┘        │  │
│   │                                                                 │  │
│   └─────────────────────────────────────────────────────────────────┘  │
│                                                                         │
└─────────────────────────────────────────────────────────────────────────┘
```

### 2.2 技术栈

| 层级 | 技术选型 |
|------|----------|
| 扩展架构 | Chrome Manifest V3 |
| 前端语言 | JavaScript (ES6+) |
| UI 框架 | 原生 JS + Shadow DOM |
| AI 服务 | OpenRouter API (支持多模型) |
| 本地存储 | chrome.storage.local |
| 网络请求 | fetch API |
| 构建工具 | Webpack / Rollup |

---

## 三、功能模块详细设计

### 3.1 捕获模块（原有功能，保留）

**功能**：点击页面元素，提取 HTML 和 CSS

**实现要点**：
- 内容脚本监听页面点击事件
- 使用 `element.outerHTML` 获取完整 HTML
- 使用 `getComputedStyle()` 获取计算后样式
- 通过 Shadow DOM 注入高亮遮罩

**文件**：`content/content.js`

---

### 3.2 区块识别模块（新增）

**功能**：自动识别元素类型（静态/动态/混合）

**实现逻辑**：

```javascript
const BlockType = {
  STATIC: 'static',      // 静态区块：无数据依赖
  DYNAMIC: 'dynamic',   // 动态区块：依赖数据库/API
  MIXED: 'mixed'        // 混合区块：静态布局+动态内容
};

function identifyBlockType(element) {
  // 检查动态数据特征
  const hasDataBinding = checkDataBinding(element);    // Vue/React 数据绑定
  const hasCMSContent = checkCMSContent(element);      // WordPress 占位符
  const hasRepeater = checkRepeater(element);          // 重复模式
  const hasQuery = checkQuery(element);                // 查询模式

  if (hasDataBinding || hasQuery) return BlockType.DYNAMIC;
  if (hasRepeater || hasCMSContent) return BlockType.MIXED;
  return BlockType.STATIC;
}

function checkDataBinding(element) {
  // 检查 Vue/React/Angular 等框架的数据绑定
  const attrs = element.attributes;
  for (let attr of attrs) {
    if (attr.name.startsWith('v-') ||           // Vue
        attr.name.startsWith(':') ||             // Vue 缩写
        attr.name.startsWith('ng-') ||           // Angular
        /\{.*\}/.test(attr.value)) {             // React/模板语法
      return true;
    }
  }
  return false;
}

function checkCMSContent(element) {
  // 检查 WordPress/Drupal 等 CMS 占位符
  const patterns = [
    /\{\{.*\}\}/,              // 模板语法
    /\[.*\]/,                  // 短代码
    /__\w+__/,                // WordPress 占位符
    /%[\w_]+%/                // 旧版 WP 占位符
  ];
  return patterns.some(p => p.test(element.innerHTML));
}

function checkRepeater(element) {
  // 检查重复模式（如产品列表、卡片组）
  const children = element.children;
  if (children.length < 2) return false;

  // 检查子元素结构是否相似
  const firstChildClass = children[0].className;
  return Array.from(children).every(child =>
    child.className === firstChildClass
  );
}

function checkQuery(element) {
  // 检查查询相关属性
  const patterns = [
    /query|filter|search|sort|pagination/i,
    /wp:|data:|\.php\?/i
  ];
  const html = element.outerHTML;
  return patterns.some(p => p.test(html));
}
```

**建议字段输出**：

```json
{
  "id": "block_001",
  "type": "dynamic",
  "suggested_fields": ["product_title", "product_image", "product_price", "product_link"],
  "confidence": 0.85
}
```

**文件**：`content/block-identifier.js`（新增）

---

### 3.3 路由判定模块（新增）

**功能**：根据复杂度评分，建议最佳交付路径

**实现逻辑**：

```javascript
function calculateComplexityScore(elementData) {
  let score = 0;

  // 数据复杂度 (0-3)
  if (elementData.hasFilters) score += 1;
  if (elementData.hasQueries) score += 1;
  if (elementData.hasTaxonomyCoupling) score += 1;

  // 动效复杂度 (0-3)
  if (elementData.hasScrollTimeline) score += 1;
  if (elementData.hasAbsolutePositioning) score += 1;
  if (elementData.hasMicroInteractions) score += 1;

  // 运行时复杂度 (0-3)
  if (elementData.hasStatefulBehavior) score += 1;
  if (elementData.hasConditionalRendering) score += 1;
  if (elementData.hasComplexAnimations) score += 1;

  return score;
}

function suggestRoute(score) {
  if (score <= 2) return {
    route: 'elementor-json',
    description: '适合纯 Elementor JSON 导入',
    steps: ['直接导出 Elementor JSON']
  };
  if (score <= 5) return {
    route: 'hybrid',
    description: '建议混合模式',
    steps: ['Elementor 布局', '插件渲染复杂区块']
  };
  return {
    route: 'plugin',
    description: '建议完整插件',
    steps: ['ACF 字段生成', '插件代码生成']
  };
}
```

**文件**：`lib/route-decider.js`（新增）

---

### 3.4 转换模块（原有功能，保留+增强）

**功能**：将 HTML/CSS 转换为 Elementor 控件配置

**增强点**：
- 新增更多 CSS 属性到 Elementor 控件的映射
- 支持更多 Widget 类型

**文件**：`content/converter.js`（修改）

---

### 3.5 AI 增强模块（核心新增）

**功能**：通过 OpenRouter API 智能优化转换结果

#### 3.5.1 AI 服务封装

```javascript
// 文件：lib/ai-service.js

class AIService {
  constructor() {
    this.config = null;
  }

  async init(config) {
    // config = { provider: 'openrouter', model: 'gpt-4o-mini', apiKey: '...' }
    this.config = config;
  }

  async optimize(elementData) {
    const prompt = this.buildPrompt(elementData);
    const response = await this.callAPI(prompt);
    return this.parseResponse(response);
  }

  buildPrompt(elementData) {
    return {
      role: 'system',
      content: `你是一个 CSS 优化专家和 Elementor 专家。你的任务是：
1. 优化冗余的 CSS 样式，生成最精简的 Elementor 控件配置
2. 补全可能丢失的布局样式，还原设计意图
3. 只返回 JSON，不要其他内容`
    },
    {
      role: 'user',
      content: `请优化以下 Elementor 转换结果：

原始 HTML:
${elementData.html}

原始 CSS:
${elementData.css}

当前转换结果:
${elementData.currentResult}

请返回优化后的 JSON，包含：
- optimized_settings: 优化后的控件设置
- layout_enhancements: 布局增强建议
- simplified_count: 简化了多少个属性
- explanation: 用简短中文说明优化了什么`
    };
  }

  async callAPI(prompt) {
    const response = await fetch('https://openrouter.ai/api/v1/chat/completions', {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${this.config.apiKey}`,
        'Content-Type': 'application/json',
        'HTTP-Referer': 'https://chrome.google.com/webstore',
      },
      body: JSON.stringify({
        model: this.config.model,
        messages: [prompt.system, prompt.user]
      })
    });
    return response.json();
  }

  parseResponse(response) {
    try {
      const content = response.choices[0].message.content;
      return JSON.parse(content);
    } catch (e) {
      return { error: '解析失败', raw: response };
    }
  }

  async testConnection() {
    try {
      const response = await fetch('https://openrouter.ai/api/v1/models', {
        headers: { 'Authorization': `Bearer ${this.config.apiKey}` }
      });
      return response.ok;
    } catch {
      return false;
    }
  }
}
```

#### 3.5.2 优化类型

| 类型 | 描述 | 示例 |
|------|------|------|
| CSS 精简 | 移除冗余/重复属性 | `border: 1px solid #000; border-radius: 0;` → `border: 1px solid #000;` |
| 颜色简化 | rgba 转 hex | `rgba(255, 255, 255, 1)` → `#ffffff` |
| 布局补全 | 还原缺失间距 | 补全 `margin`/`padding` |
| 字体还原 | 补全字体层级 | 补全 `font-size`, `font-weight` |
| Flex/Grid | 还原布局意图 | 补全 `display: flex` 相关属性 |

#### 3.5.3 错误处理

```javascript
async function enhanceWithAI(elementData) {
  try {
    const result = await aiService.optimize(elementData);
    return {
      success: true,
      data: result,
      used_ai: true
    };
  } catch (error) {
    // AI 失败，降级到原规则引擎结果
    console.error('AI 优化失败:', error);
    return {
      success: false,
      data: elementData.baseResult,  // 降级使用
      error: error.message,
      used_ai: false
    };
  }
}
```

---

### 3.6 补丁模块（新增）

**功能**：用户可以微调转换结果，无需重新捕获

```javascript
// 文件：lib/patch-engine.js

const PatchOperations = {
  SET: 'set',           // 设置值
  DELETE: 'delete',    // 删除节点
  MOVE: 'move',        // 移动位置
  COPY: 'copy',        // 复制
  INSERT: 'insert',    // 插入新节点
  UPDATE: 'update'     // 更新属性
};

class PatchEngine {
  constructor(elementJson) {
    this.original = JSON.parse(JSON.stringify(elementJson));
    this.current = JSON.parse(JSON.stringify(elementJson));
    this.changeLog = [];
  }

  // 设置属性值
  setValue(path, value) {
    this.applyOperation({
      op: PatchOperations.SET,
      path,
      oldValue: this.getValue(path),
      newValue: value
    });
  }

  // 删除节点
  deleteNode(path) {
    this.applyOperation({
      op: PatchOperations.DELETE,
      path,
      oldValue: this.getValue(path)
    });
  }

  // 移动位置
  move(fromPath, toPath) {
    const value = this.getValue(fromPath);
    this.applyOperation({
      op: PatchOperations.MOVE,
      fromPath,
      toPath,
      value
    });
  }

  applyOperation(operation) {
    switch (operation.op) {
      case PatchOperations.SET:
        this.setValueAt(operation.path, operation.newValue);
        break;
      case PatchOperations.DELETE:
        this.deleteAt(operation.path);
        break;
      case PatchOperations.MOVE:
        this.setValueAt(operation.toPath, operation.value);
        this.deleteAt(operation.fromPath);
        break;
    }
    this.changeLog.push(operation);
  }

  getValue(path) {
    // 路径解析，如 "settings.button_text"
    return path.split('.').reduce((obj, key) => obj[key], this.current);
  }

  getChangeLog() {
    return this.changeLog;
  }

  export() {
    return {
      original: this.original,
      patched: this.current,
      changeLog: this.changeLog
    };
  }
}
```

**支持的补丁操作**：

| 操作 | UI 表现 | 示例 |
|------|---------|------|
| SET | 输入框修改 | 修改按钮文字 |
| DELETE | 删除按钮 | 删除某个设置项 |
| MOVE | 拖拽 | 调整区块顺序 |

---

### 3.7 配置管理模块（新增）

**功能**：管理 AI 配置和用户设置

```javascript
// 文件：lib/config-manager.js

class ConfigManager {
  static STORAGE_KEY = 'html-to-elementor-config';

  static DEFAULT_CONFIG = {
    ai: {
      enabled: false,
      provider: 'openrouter',
      model: 'gpt-4o-mini',
      apiKey: '',
      autoOptimize: false
    },
    general: {
      highlightElements: true,
      showCaptureHint: true
    }
  };

  async getConfig() {
    const stored = await chrome.storage.local.get(this.STORAGE_KEY);
    return { ...this.DEFAULT_CONFIG, ...stored[this.STORAGE_KEY] };
  }

  async setConfig(config) {
    await chrome.storage.local.set({
      [this.STORAGE_KEY]: config
    });
  }

  isAIEnabled() {
    return this.config?.ai?.enabled && this.config?.ai?.apiKey;
  }

  isAutoOptimize() {
    return this.config?.ai?.autoOptimize;
  }
}
```

---

### 3.8 数据导出模块（新增）

**功能**：导出数据供 Skill 端处理

```javascript
// 文件：lib/export-service.js

function exportForSkill(capturedData, routeSuggestion) {
  return {
    version: '1.0',
    source: 'html-to-elementor-plugin',
    exportTime: new Date().toISOString(),

    route: routeSuggestion.route,
    complexity_score: routeSuggestion.score,

    elements: capturedData.map(el => ({
      id: el.id,
      type: el.blockType,
      html: el.html,
      css: el.css,
      converted: el.convertedResult,
      screenshot: el.screenshot,
      suggested_fields: el.suggestedFields || []
    })),

    metadata: {
      page_url: window.location.href,
      viewport: { width: window.innerWidth, height: window.innerHeight },
      device_hints: detectDevices()
    }
  };
}

function detectDevices() {
  const width = window.innerWidth;
  if (width < 768) return ['mobile'];
  if (width < 1024) return ['mobile', 'tablet'];
  return ['mobile', 'tablet', 'desktop'];
}
```

**导出格式**：

```json
{
  "version": "1.0",
  "source": "html-to-elementor-plugin",
  "exportTime": "2026-02-13T10:00:00Z",
  "route": "hybrid",
  "complexity_score": 4,
  "elements": [
    {
      "id": "hero_001",
      "type": "static",
      "html": "<section class='hero'>...</section>",
      "css": ".hero { height: 100vh; ... }",
      "converted": { "elType": "section", "settings": {...} },
      "screenshot": "data:image/png;base64,...",
      "suggested_fields": []
    },
    {
      "id": "product_list_001",
      "type": "dynamic",
      "html": "<div class='products'>...</div>",
      "css": ".products { display: grid; ... }",
      "converted": { "elType": "container", "settings": {...} },
      "screenshot": "data:image/png;base64,...",
      "suggested_fields": [
        "product_title",
        "product_image",
        "product_price",
        "product_link"
      ]
    }
  ],
  "metadata": {
    "page_url": "https://example.com/products",
    "viewport": { "width": 1920, "height": 1080 },
    "device_hints": ["mobile", "tablet", "desktop"]
  }
}
```

---

## 四、UI 界面设计

### 4.1 主面板（增强原有设计）

```
┌─────────────────────────────────────────────────────────────┐
│  🔧 HTML To Elementor         [AI: 开/关]  [设置]  [导出]  │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │  📱 响应式预览                        [📺] [📱] [💻] │   │
│  │  ┌───────────────────────────────────────────────┐   │   │
│  │  │                                               │   │   │
│  │  │            页面预览区域                         │   │   │
│  │  │         (显示当前选中元素的预览)                │   │   │
│  │  │                                               │   │   │
│  │  └───────────────────────────────────────────────┘   │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │  🏷️ 区块信息                                       │   │
│  │  类型: 动态区块  |  建议: Hybrid 模式              │   │
│  │  建议字段: product_title, product_image, ...      │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │  📋 转换结果                                         │   │
│  │                                                     │   │
│  │  [原版] [AI优化版] [补丁模式] ◄── 新增             │   │
│  │                                                     │   │
│  │  优化说明:                                           │   │
│  │  • 简化了 12 个冗余样式                             │   │
│  │  • 补全了缺失的字体大小和颜色                        │   │
│  │                                                     │   │
│  │  ┌─────────────────────────────────────────────┐    │   │
│  │  │ 代码预览:                                     │    │   │
│  │  │ [Elementor JSON] [HTML/CSS] [补丁操作]      │    │   │
│  │  └─────────────────────────────────────────────┘    │   │
│  │                                                     │   │
│  │  ┌──────────┐  ┌──────────┐  ┌──────────┐        │   │
│  │  │ 📋 复制   │  │ 📋 复制  │  │ 📋 导出  │        │   │
│  │  │ Elementor │  │ HTML/CSS │  │  Skill   │        │   │
│  │  └──────────┘  └──────────┘  └──────────┘        │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### 4.2 设置页面

```
┌─────────────────────────────────────────────────────────────┐
│                      设置                                    │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  🤖 AI 配置                                               │
│  ───────────────────────────────────────────────────────   │
│                                                             │
│  AI 提供商: [OpenRouter ▼]                                  │
│                                                             │
│  模型:      [GPT-4o-mini ▼]                               │
│              (可选: gpt-4o, claude-3.5-sonnet,             │
│               gemini-2.0-flash, etc.)                      │
│                                                             │
│  API 密钥:  [••••••••••••••••••••••••]    [测试连接]     │
│                                                             │
│  ───────────────────────────────────────────────────────   │
│                                                             │
│  [ ] 启用 AI 优化                                          │
│                                                             │
│  [ ] 每次自动优化 (不勾选则手动点击触发)                    │
│                                                             │
│  ───────────────────────────────────────────────────────   │
│  💡 API 密钥仅本地存储，不会上传                            │
│                                                             │
│                        [保存]                               │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### 4.3 导出确认弹窗

```
┌─────────────────────────────────────────────────────────────┐
│                      导出确认                                │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  已识别 3 个区块:                                           │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ ✓ hero_section (静态)                               │   │
│  │ ✓ product_list (动态) - 建议 ACF 字段              │   │
│  │ ✓ cta_section (静态)                                │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  路由建议: Hybrid 模式 (复杂度评分: 4)                     │
│                                                             │
│  下一步:                                                    │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ [复制 JSON 到剪贴板]                                 │   │
│  │ [下载 JSON 文件]                                     │   │
│  │ [直接导入到 Claude Code Skill 处理] ◄ 推荐          │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│                        [关闭]                               │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

---

## 五、数据流设计

### 5.1 插件端数据流

```
用户点击元素
       │
       ▼
┌─────────────────┐
│  捕获模块       │
│  - HTML 提取   │
│  - CSS 提取    │
│  - 截图        │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  区块识别       │
│  - 静态/动态   │
│  - 建议字段    │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  规则引擎       │
│  基础转换      │
└────────┬────────┘
         │
         ▼
    ┌────┴────┐
    │         │
    ▼         ▼
┌───────┐ ┌───────┐
│ 原版  │ │ AI   │
│ 结果  │ │ 优化  │
└───┬───┘ └───┬───┘
    │         │
    └────┬────┘
         │
         ▼
┌─────────────────┐
│  结果展示        │
│  - 截图对比    │
│  - 优化说明    │
│  - 补丁模式    │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  用户选择        │
│  - 复制         │
│  - 导出         │
└─────────────────┘
```

### 5.2 与 Skill 端对接

```
插件端                               Skill 端
  │                                     │
  │  导出 JSON/CSV                      │
  ├────────────────────────────────────►│
  │                                     │  加载数据
  │                                     │     │
  │                                     │  运行路由判定
  │                                     │     │
  │                                     │  如需 ACF:
  │                                     │  generate_acf_import.py
  │                                     │     │
  │                                     │  如需插件:
  │                                     │  hybrid/plugin 模板
  │                                     │
  │  返回处理结果                       │
  ◄────────────────────────────────────┤
  │                                     │
  ▼                                     ▼
展示结果                          输出最终包
```

---

## 六、文件结构

```
html-to-elementor-ai/
│
├── manifest.json                    # 扩展清单（更新）
│
├── background/
│   ├── background.js                # 后台服务 Worker
│   └── background.module.js         # 模块化代码
│
├── content/
│   ├── content.js                   # 内容脚本（原有）
│   ├── block-identifier.js          # 区块识别（新增）
│   ├── converter.js                 # 转换引擎（增强）
│   ├── screenshot.js                # 截图功能（新增）
│   └── ui/
│       ├── panel.js                 # 面板 UI
│       └── panel.css                # 面板样式
│
├── lib/
│   ├── ai-service.js                # AI 服务（新增）
│   ├── config-manager.js            # 配置管理（新增）
│   ├── route-decider.js             # 路由判定（新增）
│   ├── patch-engine.js              # 补丁引擎（新增）
│   └── export-service.js            # 导出服务（新增）
│
├── popup/
│   ├── popup.html                   # 设置页面
│   ├── popup.js                     # 设置逻辑
│   ├── popup.css                    # 设置样式
│   └── settings.js                  # 设置组件
│
├── assets/
│   └── icon-256.png                 # 扩展图标
│
├── _locales/
│   └── en/
│       └── messages.json            # 国际化
│
└── tests/
    ├── block-identifier.test.js    # 区块识别测试
    ├── ai-service.test.js          # AI 服务测试
    └── patch-engine.test.js        # 补丁引擎测试
```

---

## 七、权限要求

| 权限 | 用途 | 必需 |
|------|------|------|
| storage | 存储 AI 配置和元素数据 | ✅ |
| activeTab | 访问当前标签页 | ✅ |
| debugger | 视口控制 | ✅ |
| clipboardWrite | 复制代码 | ✅ |
| scripting | 执行脚本处理页面 | ✅ |
| *notifications* | 显示通知 | 可选 |

---

## 八、实施路线图

### 阶段 1：基础框架（1-2 周）

- [ ] 1.1 设置项目结构
- [ ] 1.2 配置构建工具（Webpack/Rollup）
- [ ] 1.3 迁移原有代码到新结构
- [ ] 1.4 配置 TypeScript（可选）

### 阶段 2：核心功能（2-3 周）

- [x] 2.1 实现区块识别模块
- [x] 2.2 实现路由判定模块
- [x] 2.3 增强转换引擎
- [x] 2.4 实现配置管理

### 阶段 3：AI 集成（1-2 周）

- [x] 3.1 实现 AI 服务封装
- [x] 3.2 实现 OpenRouter 调用
- [x] 3.3 实现错误处理和降级
- [x] 3.4 实现结果对比展示

### 阶段 4：补丁和导出（1-2 周）

- [x] 4.1 实现补丁引擎
- [x] 4.2 实现数据导出
- [x] 4.3 实现 Skill 对接格式

### 阶段 5：UI/UX（1 周）

- [x] 5.1 更新主面板 UI
- [x] 5.2 实现设置页面
- [x] 5.3 实现导出弹窗
- [x] 5.4 优化用户体验

### 阶段 6：测试和发布（1 周）

- [x] 6.1 单元测试
- [x] 6.2 集成测试
- [x] 6.3 性能优化
- [x] 6.4 打包发布

---

## 九、API 参考

### 9.1 OpenRouter API

**文档**：https://openrouter.ai/docs

**端点**：
```
POST https://openrouter.ai/api/v1/chat/completions
```

**请求头**：
```
Authorization: Bearer ${API_KEY}
Content-Type: application/json
HTTP-Referer: https://chrome.google.com/webstore
```

**可用模型**：
| 模型 | 描述 | 价格 |
|------|------|------|
| gpt-4o-mini | 快速、便宜 | $0.15/1M |
| gpt-4o | 最新 GPT-4 | $2.50/1M |
| claude-3.5-sonnet | Claude 最新 | $3.00/1M |
| gemini-2.0-flash | Google 快速 | $0.00/1M |

### 9.2 Elementor JSON 结构

**文档**：https://developers.elementor.com/docs/data-structure/

**核心结构**：
```json
{
  "id": "section_123",
  "elType": "section",
  "isInner": false,
  "settings": {
    "content_width": "full",
    "gap": "no"
  },
  "elements": [
    {
      "id": "column_456",
      "elType": "column",
      "settings": {},
      "elements": [
        {
          "id": "widget_789",
          "elType": "widget",
          "widgetType": "heading",
          "settings": {
            "title": "Hello World"
          }
        }
      ]
    }
  ]
}
```

---

## 十、常见问题

### Q1: AI 处理失败怎么办？
A: 自动降级到原规则引擎结果，并在 UI 中显示提示。

### Q2: API 密钥安全吗？
A: 密钥只存储在 `chrome.storage.local`，不会发送到服务器（除了调用 AI API 时）。

### Q3: 离线能用吗？
A: 基础功能可用，AI 优化需要网络连接。

### Q4: 如何与 Skill 配合使用？
A: 导出 JSON 后，在 Claude Code 中使用 gemini-to-wordpress-acf-plugin skill 处理。

---

## 十一、相关资源

- **原版插件**：./ (当前目录)
- **Skill**：../skills/gemini-to-wordpress-acf-plugin/
- **Elementor 文档**：https://developers.elementor.com/
- **OpenRouter**：https://openrouter.ai/

---

## 十二、注意事项

1. **代码压缩**：最终发布时需要压缩为 IIFE bundle
2. **Chrome Web Store**：需要签名发布
3. **用户隐私**：明确告知用户 API 密钥的存储方式
4. **错误日志**：实现客户端错误日志收集

---

*文档版本：1.0*
*创建日期：2026-02-13*
*最后更新：2026-02-13*
