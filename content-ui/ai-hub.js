(function () {
  'use strict';

  if (window.top !== window) {
    return;
  }

  const STORAGE_KEYS = {
    LAST_CAPTURE: 'lastClickedElementData',
    UI_STATE: 'aiHubUiState'
  };

  const ACTIONS = {
    ANALYZE_BLOCK: 'analyzeBlock',
    AI_OPTIMIZE: 'aiOptimize',
    EXPORT_FOR_SKILL: 'exportForSkill',
    GET_AI_CONFIG: 'getAIConfig'
  };
  const MAX_PREVIEW_LENGTH = 80000;

  const state = {
    isOpen: false,
    activeTab: 'analyze',
    lastCapture: null,
    analysis: null,
    aiResult: null,
    exportResult: null,
    drag: {
      active: false,
      startX: 0,
      startY: 0,
      originRight: 18,
      originBottom: 18
    }
  };

  const rootHost = document.createElement('div');
  rootHost.id = 'eacs-capture-hub-root';
  rootHost.style.all = 'initial';
  rootHost.style.position = 'fixed';
  rootHost.style.inset = '0';
  rootHost.style.pointerEvents = 'none';
  rootHost.style.zIndex = '2147483646';
  document.documentElement.appendChild(rootHost);

  const shadow = rootHost.attachShadow({ mode: 'open' });
  shadow.innerHTML = `
    <style>
      :host {
        all: initial;
      }
      .hub-toggle {
        pointer-events: auto;
        position: fixed;
        right: 18px;
        bottom: 18px;
        border: 0;
        border-radius: 999px;
        padding: 10px 16px;
        cursor: pointer;
        color: #f8fbff;
        font: 600 13px/1.2 "Segoe UI", "Helvetica Neue", Arial, sans-serif;
        background: linear-gradient(135deg, #0b6bf2, #13b1d7);
        box-shadow: 0 10px 24px rgba(11, 107, 242, 0.35);
      }
      .hub-panel {
        pointer-events: auto;
        position: fixed;
        right: 18px;
        bottom: 62px;
        width: min(420px, calc(100vw - 24px));
        max-height: min(78vh, 760px);
        display: flex;
        flex-direction: column;
        background: #f7fbff;
        color: #0f172a;
        border: 1px solid #d0ddef;
        border-radius: 14px;
        box-shadow: 0 20px 50px rgba(15, 23, 42, 0.28);
        overflow: hidden;
        transform-origin: bottom right;
        animation: hteHubIn .2s ease;
      }
      .hub-panel.hidden {
        display: none;
      }
      .hub-head {
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 10px;
        align-items: center;
        padding: 10px 12px;
        border-bottom: 1px solid #d0ddef;
        background: linear-gradient(135deg, #0d1d3f, #12356b);
        color: #f8fbff;
        cursor: move;
        user-select: none;
      }
      .hub-title {
        margin: 0;
        font: 600 14px/1.2 "Segoe UI", "Helvetica Neue", Arial, sans-serif;
      }
      .hub-head-actions {
        display: flex;
        gap: 8px;
      }
      .hub-head-btn {
        border: 1px solid rgba(245, 250, 255, 0.35);
        border-radius: 8px;
        background: rgba(245, 250, 255, 0.06);
        color: #f8fbff;
        font: 500 12px/1 "Segoe UI", "Helvetica Neue", Arial, sans-serif;
        padding: 7px 10px;
        cursor: pointer;
      }
      .hub-tabs {
        display: flex;
        gap: 8px;
        padding: 10px 12px;
        border-bottom: 1px solid #d0ddef;
        background: #eef5ff;
      }
      .hub-tab {
        border: 1px solid #bfd0ea;
        border-radius: 8px;
        background: #fff;
        color: #0f172a;
        font: 600 12px/1 "Segoe UI", "Helvetica Neue", Arial, sans-serif;
        padding: 7px 10px;
        cursor: pointer;
      }
      .hub-tab.active {
        background: #0b6bf2;
        border-color: #0b6bf2;
        color: #fff;
      }
      .hub-body {
        overflow: auto;
        padding: 12px;
        display: none;
        flex-direction: column;
        gap: 10px;
      }
      .hub-body.active {
        display: flex;
      }
      .hub-card {
        background: #fff;
        border: 1px solid #d0ddef;
        border-radius: 10px;
        padding: 10px;
      }
      .hub-row {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
      }
      .hub-btn {
        border: 1px solid #c8d7ec;
        border-radius: 8px;
        background: #fff;
        color: #0f172a;
        font: 600 12px/1 "Segoe UI", "Helvetica Neue", Arial, sans-serif;
        padding: 8px 10px;
        cursor: pointer;
      }
      .hub-btn.primary {
        border-color: #0b6bf2;
        background: #0b6bf2;
        color: #fff;
      }
      .hub-btn.warn {
        border-color: #c25b00;
        background: #ff8f1f;
        color: #fff;
      }
      .hub-meta {
        margin: 0;
        color: #334155;
        font: 12px/1.5 "Segoe UI", "Helvetica Neue", Arial, sans-serif;
      }
      .hub-title-sm {
        margin: 0 0 6px;
        color: #0f172a;
        font: 600 12px/1.4 "Segoe UI", "Helvetica Neue", Arial, sans-serif;
      }
      .hub-input,
      .hub-textarea {
        width: 100%;
        border: 1px solid #c8d7ec;
        border-radius: 8px;
        padding: 8px 10px;
        background: #fff;
        color: #0f172a;
        font: 12px/1.5 Menlo, Consolas, monospace;
        outline: none;
      }
      .hub-textarea {
        min-height: 90px;
        resize: vertical;
      }
      .hub-input:focus,
      .hub-textarea:focus {
        border-color: #0b6bf2;
        box-shadow: 0 0 0 3px rgba(11, 107, 242, 0.14);
      }
      .hub-pre {
        margin: 0;
        border-radius: 8px;
        border: 1px solid #d0ddef;
        background: #f8fbff;
        padding: 10px;
        max-height: 180px;
        overflow: auto;
        color: #0f172a;
        font: 11px/1.5 Menlo, Consolas, monospace;
        white-space: pre-wrap;
        word-break: break-word;
      }
      .hub-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
      }
      .hub-status {
        margin: 0;
        min-height: 18px;
        color: #334155;
        font: 12px/1.4 "Segoe UI", "Helvetica Neue", Arial, sans-serif;
      }
      .hub-status.ok {
        color: #0a8b47;
      }
      .hub-status.err {
        color: #bf1d1d;
      }
      @keyframes hteHubIn {
        from {
          opacity: 0;
          transform: translateY(10px) scale(0.98);
        }
        to {
          opacity: 1;
          transform: translateY(0) scale(1);
        }
      }
      @media (max-width: 620px) {
        .hub-panel {
          right: 8px;
          bottom: 58px;
          width: calc(100vw - 16px);
          max-height: 82vh;
        }
        .hub-toggle {
          right: 8px;
          bottom: 10px;
        }
        .hub-grid {
          grid-template-columns: 1fr;
        }
      }
    </style>
    <button class="hub-toggle" id="hubToggle" type="button">Capture Hub</button>
    <section class="hub-panel hidden" id="hubPanel" aria-label="Elementor Capture Hub">
      <header class="hub-head" id="hubHead">
        <h2 class="hub-title">Elementor Capture Hub</h2>
        <div class="hub-head-actions">
          <button class="hub-head-btn" id="openOptionsBtn" type="button">Settings</button>
          <button class="hub-head-btn" id="closePanelBtn" type="button">Close</button>
        </div>
      </header>
      <nav class="hub-tabs">
        <button class="hub-tab active" type="button" data-tab="analyze">Capture</button>
        <button class="hub-tab" type="button" data-tab="compare">AI Enhance</button>
        <button class="hub-tab" type="button" data-tab="export">Export</button>
      </nav>
      <div class="hub-body active" data-pane="analyze">
        <section class="hub-card">
          <p class="hub-title-sm">Capture Summary</p>
          <p class="hub-meta">Main workflow: Start Capture -> click element -> paste with Ctrl+V in Elementor.</p>
          <p class="hub-meta" id="captureSummary">No capture loaded.</p>
          <div class="hub-row" style="margin-top:8px">
            <button class="hub-btn primary" id="startCaptureBtn" type="button">Start Capture</button>
            <button class="hub-btn" id="stopCaptureBtn" type="button">Stop Capture</button>
            <button class="hub-btn primary" id="loadCaptureBtn" type="button">Load Last Capture</button>
            <button class="hub-btn" id="analyzeBtn" type="button">Check Structure</button>
          </div>
        </section>
        <section class="hub-card">
          <p class="hub-title-sm">Structure Check</p>
          <pre class="hub-pre" id="analysisOutput">-</pre>
          <p class="hub-status" id="analysisStatus"></p>
        </section>
      </div>
      <div class="hub-body" data-pane="compare">
        <section class="hub-card">
          <p class="hub-title-sm">AI Enhance (Optional)</p>
          <p class="hub-meta">Capture and paste works without AI. Use this only for optional optimization.</p>
          <p class="hub-title-sm" style="margin-top:8px">Base Elementor JSON</p>
          <textarea class="hub-textarea" id="baseJsonInput" placeholder='{"elType":"section","settings":{}}'></textarea>
          <div class="hub-row" style="margin-top:8px">
            <button class="hub-btn primary" id="optimizeBtn" type="button">Run AI Enhance</button>
          </div>
          <p class="hub-status" id="optimizeStatus"></p>
        </section>
        <section class="hub-grid">
          <div class="hub-card">
            <p class="hub-title-sm">Base</p>
            <pre class="hub-pre" id="basePreview">-</pre>
          </div>
          <div class="hub-card">
            <p class="hub-title-sm">Optimized</p>
            <pre class="hub-pre" id="optimizedPreview">-</pre>
          </div>
        </section>
      </div>
      <div class="hub-body" data-pane="export">
        <section class="hub-card">
          <p class="hub-title-sm">Advanced Export</p>
          <p class="hub-meta">Optional: generate payload for external workflow/skill processing.</p>
          <div class="hub-row">
            <button class="hub-btn primary" id="generateExportBtn" type="button">Generate Export</button>
            <button class="hub-btn" id="copyExportBtn" type="button">Copy</button>
            <button class="hub-btn" id="downloadExportBtn" type="button">Download</button>
          </div>
          <p class="hub-status" id="exportStatus"></p>
          <pre class="hub-pre" id="exportOutput">-</pre>
        </section>
      </div>
    </section>
  `;

  const elements = {
    hubToggle: shadow.getElementById('hubToggle'),
    hubPanel: shadow.getElementById('hubPanel'),
    hubHead: shadow.getElementById('hubHead'),
    closePanelBtn: shadow.getElementById('closePanelBtn'),
    openOptionsBtn: shadow.getElementById('openOptionsBtn'),
    tabs: Array.from(shadow.querySelectorAll('[data-tab]')),
    panes: Array.from(shadow.querySelectorAll('[data-pane]')),
    loadCaptureBtn: shadow.getElementById('loadCaptureBtn'),
    startCaptureBtn: shadow.getElementById('startCaptureBtn'),
    stopCaptureBtn: shadow.getElementById('stopCaptureBtn'),
    analyzeBtn: shadow.getElementById('analyzeBtn'),
    captureSummary: shadow.getElementById('captureSummary'),
    analysisOutput: shadow.getElementById('analysisOutput'),
    analysisStatus: shadow.getElementById('analysisStatus'),
    baseJsonInput: shadow.getElementById('baseJsonInput'),
    optimizeBtn: shadow.getElementById('optimizeBtn'),
    basePreview: shadow.getElementById('basePreview'),
    optimizedPreview: shadow.getElementById('optimizedPreview'),
    optimizeStatus: shadow.getElementById('optimizeStatus'),
    generateExportBtn: shadow.getElementById('generateExportBtn'),
    copyExportBtn: shadow.getElementById('copyExportBtn'),
    downloadExportBtn: shadow.getElementById('downloadExportBtn'),
    exportOutput: shadow.getElementById('exportOutput'),
    exportStatus: shadow.getElementById('exportStatus')
  };

  bindEvents();
  void initialize();

  async function initialize() {
    const uiState = await getFromStorage(STORAGE_KEYS.UI_STATE);
    if (uiState && typeof uiState === 'object') {
      state.activeTab = uiState.activeTab || 'analyze';
      state.isOpen = Boolean(uiState.isOpen);
      if (Number.isFinite(uiState.right)) {
        elements.hubToggle.style.right = `${uiState.right}px`;
        elements.hubPanel.style.right = `${uiState.right}px`;
      }
      if (Number.isFinite(uiState.bottom)) {
        elements.hubToggle.style.bottom = `${uiState.bottom}px`;
        elements.hubPanel.style.bottom = `${uiState.bottom + 44}px`;
      }
    }

    setActiveTab(state.activeTab);
    setPanelOpen(state.isOpen);
    await loadLastCapture({ silent: true });
  }

  function bindEvents() {
    elements.hubToggle.addEventListener('click', () => {
      setPanelOpen(!state.isOpen);
      void persistUiState();
    });

    elements.closePanelBtn.addEventListener('click', () => {
      setPanelOpen(false);
      void persistUiState();
    });

    elements.openOptionsBtn.addEventListener('click', () => {
      chrome.runtime.openOptionsPage();
    });

    elements.tabs.forEach((tabButton) => {
      tabButton.addEventListener('click', () => {
        setActiveTab(tabButton.dataset.tab || 'analyze');
        void persistUiState();
      });
    });

    elements.loadCaptureBtn.addEventListener('click', () => {
      void loadLastCapture({ silent: false });
    });

    elements.startCaptureBtn.addEventListener('click', () => {
      void toggleCaptureMode(true);
    });

    elements.stopCaptureBtn.addEventListener('click', () => {
      void toggleCaptureMode(false);
    });

    elements.analyzeBtn.addEventListener('click', () => {
      void analyzeLastCapture();
    });

    elements.optimizeBtn.addEventListener('click', () => {
      void runAIOptimize();
    });

    elements.generateExportBtn.addEventListener('click', () => {
      void generateSkillExport();
    });

    elements.copyExportBtn.addEventListener('click', () => {
      void copyExport();
    });

    elements.downloadExportBtn.addEventListener('click', () => {
      downloadExport();
    });

    window.addEventListener('keydown', (event) => {
      if (event.ctrlKey && event.shiftKey && event.key.toLowerCase() === 'y') {
        setPanelOpen(!state.isOpen);
        void persistUiState();
      }
    });

    elements.hubHead.addEventListener('pointerdown', onDragStart);
    window.addEventListener('pointermove', onDragMove);
    window.addEventListener('pointerup', onDragEnd);
  }

  function onDragStart(event) {
    state.drag.active = true;
    state.drag.startX = event.clientX;
    state.drag.startY = event.clientY;
    state.drag.originRight = parseFloat(elements.hubPanel.style.right || '18') || 18;
    state.drag.originBottom = parseFloat(elements.hubToggle.style.bottom || '18') || 18;
  }

  function onDragMove(event) {
    if (!state.drag.active) {
      return;
    }

    const deltaX = event.clientX - state.drag.startX;
    const deltaY = event.clientY - state.drag.startY;
    const right = Math.max(8, state.drag.originRight - deltaX);
    const bottom = Math.max(8, state.drag.originBottom - deltaY);

    elements.hubToggle.style.right = `${right}px`;
    elements.hubPanel.style.right = `${right}px`;
    elements.hubToggle.style.bottom = `${bottom}px`;
    elements.hubPanel.style.bottom = `${bottom + 44}px`;
  }

  function onDragEnd() {
    if (!state.drag.active) {
      return;
    }
    state.drag.active = false;
    void persistUiState();
  }

  function setPanelOpen(isOpen) {
    state.isOpen = isOpen;
    elements.hubPanel.classList.toggle('hidden', !isOpen);
  }

  function setActiveTab(tabName) {
    state.activeTab = tabName;
    elements.tabs.forEach((tabButton) => {
      tabButton.classList.toggle('active', tabButton.dataset.tab === tabName);
    });
    elements.panes.forEach((pane) => {
      pane.classList.toggle('active', pane.dataset.pane === tabName);
    });
  }

  async function loadLastCapture(options) {
    const capture = await getFromStorage(STORAGE_KEYS.LAST_CAPTURE);
    state.lastCapture = capture || null;
    renderCaptureSummary();

    if (!options?.silent) {
      setStatus(elements.analysisStatus, state.lastCapture ? 'Loaded last capture.' : 'No capture found.', state.lastCapture ? 'ok' : 'err');
    }
  }

  function renderCaptureSummary() {
    if (!state.lastCapture) {
      elements.captureSummary.textContent = 'No capture loaded.';
      return;
    }

    const source = state.lastCapture;
    const html = safeString(source.html || source.outerHTML || '');
    const tagGuess = source.tagName || matchTagName(html) || 'unknown';
    const classes = source.className || extractClassGuess(html);
    const cssCount = countKeys(source.cssAttributes || source.css || source.styles);

    elements.captureSummary.textContent = `tag=${tagGuess}${classes ? ` class=${classes}` : ''} | html=${html.length} chars | css=${cssCount} keys`;
  }

  async function analyzeLastCapture() {
    if (!state.lastCapture) {
      setStatus(elements.analysisStatus, 'Load a capture first.', 'err');
      return;
    }

    setStatus(elements.analysisStatus, 'Analyzing block...');

    try {
      const payload = normalizeCaptureForProcessing(state.lastCapture);
      const response = await sendMessage({
        action: ACTIONS.ANALYZE_BLOCK,
        elementData: payload
      });

      if (!response?.success) {
        throw new Error(response?.error || 'Analyze failed');
      }

      state.analysis = response.data || null;
      elements.analysisOutput.textContent = stringifyForPreview(state.analysis || {});
      setStatus(elements.analysisStatus, 'Structure checked.', 'ok');
    } catch (error) {
      setStatus(elements.analysisStatus, getErrorMessage(error), 'err');
    }
  }

  async function toggleCaptureMode(enable) {
    const statusNode = elements.analysisStatus;
    setStatus(statusNode, enable ? 'Enabling capture...' : 'Disabling capture...');

    try {
      const response = await sendMessage({
        action: enable ? 'startCapture' : 'stopCapture'
      });
      if (!response?.success) {
        throw new Error(response?.error || 'Capture toggle failed');
      }
      setStatus(statusNode, enable ? 'Capture enabled. Click target element, then use Ctrl+V in Elementor.' : 'Capture disabled.', 'ok');
    } catch (error) {
      setStatus(statusNode, getErrorMessage(error), 'err');
    }
  }

  async function runAIOptimize() {
    const hasBaseJsonInput = safeString(elements.baseJsonInput.value).trim().length > 0;
    if (!state.lastCapture && !hasBaseJsonInput) {
      setStatus(elements.optimizeStatus, 'Load a capture or paste base JSON first.', 'err');
      return;
    }

    setStatus(elements.optimizeStatus, 'Running optional AI enhance...');

    let baseResult;
    try {
      baseResult = parseJsonInput(elements.baseJsonInput.value, { settings: {} });
    } catch (error) {
      setStatus(elements.optimizeStatus, `Invalid base JSON: ${getErrorMessage(error)}`, 'err');
      return;
    }

    elements.basePreview.textContent = stringifyForPreview(baseResult);

    try {
      const aiConfigResponse = await sendMessage({ action: ACTIONS.GET_AI_CONFIG });
      const aiConfig = aiConfigResponse?.success ? aiConfigResponse.data : null;
      const elementData = state.lastCapture
        ? normalizeCaptureForProcessing(state.lastCapture)
        : normalizeBaseJsonForProcessing(baseResult);
      if (!state.lastCapture) {
        setStatus(elements.optimizeStatus, 'No capture loaded. Running AI in JSON-only mode...');
      }

      const response = await sendMessage({
        action: ACTIONS.AI_OPTIMIZE,
        elementData,
        baseResult,
        aiConfig
      });

      if (!response?.success) {
        throw new Error(response?.error || 'AI optimize failed');
      }

      state.aiResult = response.data;
      elements.optimizedPreview.textContent = stringifyForPreview(response.data?.data || {});

      const usedAI = Boolean(response.data?.used_ai);
      const explanation = response.data?.ai?.explanation || (usedAI ? 'AI enhancement completed.' : 'AI not enabled. Kept base result.');
      setStatus(elements.optimizeStatus, explanation, usedAI ? 'ok' : '');
    } catch (error) {
      setStatus(elements.optimizeStatus, getErrorMessage(error), 'err');
    }
  }

  async function generateSkillExport() {
    if (!state.lastCapture) {
      setStatus(elements.exportStatus, 'Load a capture first.', 'err');
      return;
    }

    setStatus(elements.exportStatus, 'Generating advanced export...');

    try {
      const analysisRoute = state.analysis?.route || {};
      const captureElement = toCapturedElement(state.lastCapture, state.analysis, state.aiResult);
      const response = await sendMessage({
        action: ACTIONS.EXPORT_FOR_SKILL,
        capturedData: [captureElement],
        routeSuggestion: {
          route: analysisRoute.route || 'elementor-json',
          score: analysisRoute.score || 0
        },
        context: {
          pageUrl: location.href,
          viewport: {
            width: window.innerWidth,
            height: window.innerHeight
          }
        },
        sourceMode: 'ai-code'
      });

      if (!response?.success) {
        throw new Error(response?.error || 'Export failed');
      }

      state.exportResult = response.data?.skillPayload || null;
      elements.exportOutput.textContent = stringifyForPreview(state.exportResult || {});
      setStatus(elements.exportStatus, 'Export ready.', 'ok');
    } catch (error) {
      setStatus(elements.exportStatus, getErrorMessage(error), 'err');
    }
  }

  async function copyExport() {
    if (!state.exportResult) {
      setStatus(elements.exportStatus, 'Generate export first.', 'err');
      return;
    }

    try {
      await navigator.clipboard.writeText(JSON.stringify(state.exportResult, null, 2));
      setStatus(elements.exportStatus, 'Export copied.', 'ok');
    } catch {
      setStatus(elements.exportStatus, 'Copy failed.', 'err');
    }
  }

  function downloadExport() {
    if (!state.exportResult) {
      setStatus(elements.exportStatus, 'Generate export first.', 'err');
      return;
    }

    const content = JSON.stringify(state.exportResult, null, 2);
    const blob = new Blob([content], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = `skill-export-${Date.now()}.json`;
    anchor.click();
    URL.revokeObjectURL(url);
    setStatus(elements.exportStatus, 'Export downloaded.', 'ok');
  }

  function toCapturedElement(rawCapture, analysis, aiResult) {
    return {
      id: rawCapture.id || `capture_${Date.now()}`,
      blockType: analysis?.block?.type || 'static',
      html: safeString(rawCapture.html || rawCapture.outerHTML || ''),
      css: toCssText(rawCapture.cssAttributes || rawCapture.css || rawCapture.styles || {}),
      convertedResult: aiResult?.data || parseJsonInput(elements.baseJsonInput.value, { settings: {} }),
      suggestedFields: analysis?.block?.suggested_fields || []
    };
  }

  function normalizeCaptureForProcessing(rawCapture) {
    return {
      html: safeString(rawCapture.html || rawCapture.outerHTML || ''),
      innerHTML: safeString(rawCapture.innerHTML || ''),
      attributes: normalizeAttributes(rawCapture.attributes),
      children: normalizeChildren(rawCapture.children),
      css: toCssText(rawCapture.cssAttributes || rawCapture.css || rawCapture.styles || {})
    };
  }

  function normalizeBaseJsonForProcessing(baseResult) {
    const settings = baseResult && typeof baseResult === 'object' ? baseResult.settings || {} : {};
    const html = safeString(settings.html || '');
    const css = safeString(settings.custom_css || '');
    return {
      html,
      innerHTML: html,
      attributes: [],
      children: [],
      css
    };
  }

  function normalizeAttributes(attributes) {
    if (Array.isArray(attributes)) {
      return attributes.map((item) => {
        if (!item) {
          return { name: '', value: '' };
        }
        if (typeof item.name === 'string') {
          return { name: item.name, value: safeString(item.value) };
        }
        if (Array.isArray(item) && item.length >= 2) {
          return { name: safeString(item[0]), value: safeString(item[1]) };
        }
        return { name: '', value: '' };
      });
    }

    if (attributes && typeof attributes === 'object') {
      return Object.entries(attributes).map(([name, value]) => ({ name, value: safeString(value) }));
    }

    return [];
  }

  function normalizeChildren(children) {
    if (!Array.isArray(children)) {
      return [];
    }

    return children
      .filter((child) => child && typeof child === 'object')
      .map((child) => ({
        tagName: safeString(child.tagName).toLowerCase(),
        className: safeString(child.className),
        id: safeString(child.id)
      }));
  }

  function toCssText(value) {
    if (typeof value === 'string') {
      return value;
    }
    if (!value || typeof value !== 'object') {
      return '';
    }
    return Object.entries(value)
      .filter((entry) => typeof entry[0] === 'string')
      .map(([key, item]) => `${key}: ${safeString(item)};`)
      .join('\n');
  }

  function parseJsonInput(text, fallback) {
    const raw = safeString(text).trim();
    if (!raw) {
      return fallback;
    }
    const parsed = JSON.parse(raw);
    if (!parsed || typeof parsed !== 'object') {
      throw new Error('Expected JSON object');
    }
    return parsed;
  }

  function matchTagName(html) {
    const match = safeString(html).match(/^<([a-z0-9-]+)/i);
    return match ? match[1].toLowerCase() : '';
  }

  function extractClassGuess(html) {
    const match = safeString(html).match(/class=["']([^"']+)["']/i);
    return match ? match[1].trim() : '';
  }

  function countKeys(obj) {
    return obj && typeof obj === 'object' ? Object.keys(obj).length : 0;
  }

  async function persistUiState() {
    await chrome.storage.local.set({
      [STORAGE_KEYS.UI_STATE]: {
        isOpen: state.isOpen,
        activeTab: state.activeTab,
        right: parseFloat(elements.hubPanel.style.right || '18') || 18,
        bottom: parseFloat(elements.hubToggle.style.bottom || '18') || 18
      }
    });
  }

  async function getFromStorage(key) {
    return new Promise((resolve) => {
      chrome.storage.local.get([key], (result) => {
        resolve(result?.[key]);
      });
    });
  }

  async function sendMessage(payload) {
    return new Promise((resolve) => {
      chrome.runtime.sendMessage(payload, (response) => {
        const error = chrome.runtime?.lastError;
        if (error) {
          resolve({ success: false, error: error.message });
          return;
        }
        resolve(response);
      });
    });
  }

  function setStatus(node, text, type) {
    node.textContent = text;
    node.className = `hub-status ${type || ''}`.trim();
  }

  function getErrorMessage(error) {
    return error instanceof Error ? error.message : String(error || 'Unknown error');
  }

  function safeString(value) {
    return typeof value === 'string' ? value : value == null ? '' : String(value);
  }

  function stringifyForPreview(value) {
    const text = JSON.stringify(value, null, 2);
    if (text.length <= MAX_PREVIEW_LENGTH) {
      return text;
    }
    return `${text.slice(0, MAX_PREVIEW_LENGTH)}\n... [truncated ${text.length - MAX_PREVIEW_LENGTH} chars]`;
  }
})();
