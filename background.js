import { AIService } from './lib/ai-service.js';
import { identifyBlockType } from './lib/block-identifier.js';
import { ConfigManager } from './lib/config-manager.js';
import { EnhancedConverter } from './lib/enhanced-converter.js';
import { exportForSkill } from './lib/export-service.js';
import { decideRoute } from './lib/route-decider.js';
import { buildSkillImportPayload } from './lib/skill-export-adapter.js';

const attachedDebuggerTabs = new Set();
const configManager = new ConfigManager();

const ACTIONS = Object.freeze({
  ELEMENT_CLICKED: 'elementClicked',
  SET_VIEWPORT: 'setViewport',
  RESTORE_VIEWPORT: 'restoreViewport',
  START_CAPTURE: 'startCapture',
  STOP_CAPTURE: 'stopCapture',
  ANALYZE_BLOCK: 'analyzeBlock',
  DECIDE_ROUTE: 'decideRoute',
  AI_OPTIMIZE: 'aiOptimize',
  TEST_AI_CONNECTION: 'testAIConnection',
  EXPORT_FOR_SKILL: 'exportForSkill',
  GET_LAST_EXPORT: 'getLastSkillExport',
  SET_AI_CONFIG: 'setAIConfig',
  GET_AI_CONFIG: 'getAIConfig'
});

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  const action = message?.action;
  if (!action) {
    return false;
  }

  handleMessage(action, message, sender)
    .then((data) => {
      sendResponse({ success: true, data });
    })
    .catch((error) => {
      sendResponse({
        success: false,
        error: error instanceof Error ? error.message : 'Unknown background error'
      });
    });

  return true;
});

chrome.action.onClicked.addListener(async () => {
  const tabs = await chrome.tabs.query({ active: true, currentWindow: true });
  if (!tabs.length) {
    return;
  }

  const tabId = tabs[0].id;
  if (tabId == null) {
    return;
  }

  await safeSendTabMessage(tabId, {
    action: 'enableCaptureData',
    tabId
  });
});

async function handleMessage(action, message, sender) {
  switch (action) {
    case ACTIONS.ELEMENT_CLICKED:
      return handleElementClicked(message);
    case ACTIONS.SET_VIEWPORT:
      return handleSetViewport(message);
    case ACTIONS.RESTORE_VIEWPORT:
      return handleRestoreViewport(message);
    case ACTIONS.START_CAPTURE:
      return handleStartCapture(sender);
    case ACTIONS.STOP_CAPTURE:
      return handleStopCapture(sender);
    case ACTIONS.ANALYZE_BLOCK:
      return handleAnalyzeBlock(message);
    case ACTIONS.DECIDE_ROUTE:
      return handleDecideRoute(message);
    case ACTIONS.AI_OPTIMIZE:
      return handleAIOptimize(message);
    case ACTIONS.TEST_AI_CONNECTION:
      return handleTestAIConnection(message);
    case ACTIONS.EXPORT_FOR_SKILL:
      return handleExportForSkill(message, sender);
    case ACTIONS.GET_LAST_EXPORT:
      return getStorageValue('lastSkillExport');
    case ACTIONS.SET_AI_CONFIG:
      return handleSetAIConfig(message);
    case ACTIONS.GET_AI_CONFIG:
      return handleGetAIConfig();
    default:
      throw new Error(`Unsupported action: ${action}`);
  }
}

async function handleElementClicked(message) {
  const payload = message?.data || {};
  await chrome.storage.local.set({
    lastClickedElementData: payload,
    lastClickedAt: new Date().toISOString()
  });

  await safeRuntimeSendMessage({
    action: 'updatePopup',
    data: payload
  });

  return { stored: true };
}

async function handleSetViewport(message) {
  const tabId = asTabId(message?.tabId);
  const metrics = normalizeViewportMetrics(message?.data || {});

  if (!attachedDebuggerTabs.has(tabId)) {
    await chrome.debugger.attach({ tabId }, '1.3');
    attachedDebuggerTabs.add(tabId);
    await safeSendTabMessage(tabId, { action: 'DEBUGGER_ATTACHED' });
  }

  try {
    await chrome.debugger.sendCommand({ tabId }, 'Emulation.setDeviceMetricsOverride', metrics);
    await safeSendTabMessage(tabId, {
      action: 'VIEWPORT_CHANGED',
      viewport: { width: metrics.width, height: metrics.height }
    });
    return { tabId, viewport: { width: metrics.width, height: metrics.height } };
  } catch (error) {
    await safeSendTabMessage(tabId, {
      action: 'VIEWPORT_CHANGED',
      viewport: { width: metrics.width, height: metrics.height },
      error: true
    });
    throw error;
  }
}

async function handleRestoreViewport(message) {
  const tabId = asTabId(message?.tabId);

  try {
    await chrome.debugger.sendCommand({ tabId }, 'Emulation.clearDeviceMetricsOverride');
    await safeSendTabMessage(tabId, { action: 'VIEWPORT_RESTORED' });
  } catch (error) {
    await safeSendTabMessage(tabId, { action: 'VIEWPORT_RESTORED', error: true });
    throw error;
  } finally {
    if (attachedDebuggerTabs.has(tabId)) {
      await chrome.debugger.detach({ tabId }).catch(() => {});
      attachedDebuggerTabs.delete(tabId);
      await safeSendTabMessage(tabId, { action: 'DEBUGGER_DETACHED' });
    }
  }

  return { tabId, restored: true };
}

async function handleStartCapture(sender) {
  const tabId = await resolveTargetTabId(sender);
  await safeSendTabMessage(tabId, {
    action: 'enableCaptureData',
    tabId
  });
  return { tabId, capture: 'enabled' };
}

async function handleStopCapture(sender) {
  const tabId = await resolveTargetTabId(sender);
  await safeSendTabMessage(tabId, {
    action: 'disableCaptureData',
    tabId
  });
  return { tabId, capture: 'disabled' };
}

async function handleAnalyzeBlock(message) {
  const elementData = message?.elementData || {};
  const block = identifyBlockType(elementData);

  const autoSignals = {
    hasQueries: block.reasons.includes('query_pattern'),
    hasStatefulBehavior: block.type === 'dynamic',
    hasConditionalRendering: block.reasons.includes('data_binding'),
    hasFilters: block.reasons.includes('repeater_pattern'),
    hasComplexAnimations: false
  };

  const route = decideRoute({
    ...autoSignals,
    ...(message?.complexitySignals || {})
  });

  return {
    block,
    route
  };
}

async function handleDecideRoute(message) {
  const route = decideRoute(message?.complexitySignals || {});
  return route;
}

async function handleAIOptimize(message) {
  const aiService = new AIService();
  const converter = new EnhancedConverter({
    aiService,
    configManager
  });

  const result = await converter.enhanceWithAI(message?.elementData || {}, message?.baseResult || {}, {
    ai: message?.aiConfig
  });

  await chrome.storage.local.set({
    lastAIOptimization: {
      at: new Date().toISOString(),
      used_ai: result.used_ai,
      success: result.success
    }
  });

  return result;
}

async function handleTestAIConnection(message) {
  const aiConfig = await resolveAIConfigForRequest(message);
  const aiService = new AIService();
  aiService.init({
    provider: aiConfig.provider || 'openrouter',
    model: aiConfig.model || 'gpt-4o-mini',
    apiKey: aiConfig.apiKey || ''
  });

  const ok = await aiService.testConnection();
  return { ok };
}

async function handleExportForSkill(message, sender) {
  const captureExport = exportForSkill({
    capturedData: message?.capturedData || [],
    routeSuggestion: message?.routeSuggestion || {},
    context: {
      pageUrl: message?.context?.pageUrl || sender?.tab?.url || '',
      viewport: message?.context?.viewport
    }
  });

  const skillPayload = buildSkillImportPayload({
    captureExport,
    sourceMode: message?.sourceMode || 'ai-code'
  });

  const payload = {
    captureExport,
    skillPayload
  };

  await chrome.storage.local.set({
    lastSkillExport: payload
  });

  return payload;
}

async function handleSetAIConfig(message) {
  const incomingAi = message?.ai || {};
  const nextConfig = await configManager.setConfig({
    ai: {
      enabled: Boolean(incomingAi.enabled),
      provider: incomingAi.provider || 'openrouter',
      model: incomingAi.model || 'gpt-4o-mini',
      apiKey: incomingAi.apiKey || '',
      autoOptimize: Boolean(incomingAi.autoOptimize)
    }
  });
  return nextConfig.ai;
}

async function handleGetAIConfig() {
  const config = await configManager.getConfig();
  return config.ai;
}

async function resolveAIConfigForRequest(message) {
  const override = message?.aiConfig;
  if (override && typeof override === 'object') {
    return {
      enabled: Boolean(override.enabled),
      provider: override.provider || 'openrouter',
      model: override.model || 'gpt-4o-mini',
      apiKey: override.apiKey || '',
      autoOptimize: Boolean(override.autoOptimize)
    };
  }
  return handleGetAIConfig();
}

function normalizeViewportMetrics(raw) {
  const width = normalizePositiveInt(raw.width, 1280);
  const height = normalizePositiveInt(raw.height, 720);

  return {
    width,
    height,
    deviceScaleFactor: normalizeNumber(raw.deviceScaleFactor, 1),
    mobile: Boolean(raw.mobile),
    screenWidth: normalizePositiveInt(raw.screenWidth, width),
    screenHeight: normalizePositiveInt(raw.screenHeight, height),
    positionX: normalizeNumber(raw.positionX, 0),
    positionY: normalizeNumber(raw.positionY, 0),
    dontSetVisibleSize: Boolean(raw.dontSetVisibleSize)
  };
}

function normalizePositiveInt(value, fallback) {
  const numeric = Number(value);
  if (!Number.isFinite(numeric) || numeric <= 0) {
    return fallback;
  }
  return Math.floor(numeric);
}

function normalizeNumber(value, fallback) {
  const numeric = Number(value);
  return Number.isFinite(numeric) ? numeric : fallback;
}

function asTabId(value) {
  const tabId = Number(value);
  if (!Number.isInteger(tabId) || tabId <= 0) {
    throw new Error(`Invalid tabId: ${value}`);
  }
  return tabId;
}

async function safeSendTabMessage(tabId, payload) {
  try {
    await chrome.tabs.sendMessage(tabId, payload);
  } catch {
    // Ignore missing receiver in tab.
  }
}

async function safeRuntimeSendMessage(payload) {
  try {
    await chrome.runtime.sendMessage(payload);
  } catch {
    // Ignore missing listeners.
  }
}

async function getStorageValue(key) {
  const result = await chrome.storage.local.get(key);
  return result?.[key] || null;
}

async function resolveTargetTabId(sender) {
  if (Number.isInteger(sender?.tab?.id) && sender.tab.id > 0) {
    return sender.tab.id;
  }

  const tabs = await chrome.tabs.query({ active: true, currentWindow: true });
  const tabId = tabs?.[0]?.id;
  if (!Number.isInteger(tabId) || tabId <= 0) {
    throw new Error('No active tab found');
  }
  return tabId;
}
