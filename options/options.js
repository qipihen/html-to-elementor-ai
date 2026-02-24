const form = {
  enabled: document.getElementById('enabled'),
  provider: document.getElementById('provider'),
  model: document.getElementById('model'),
  apiKey: document.getElementById('apiKey'),
  autoOptimize: document.getElementById('autoOptimize')
};

const saveBtn = document.getElementById('saveBtn');
const testBtn = document.getElementById('testBtn');
const openExportAssistantBtn = document.getElementById('openExportAssistantBtn');
const statusNode = document.getElementById('status');
const htmlInput = document.getElementById('htmlInput');
const cssInput = document.getElementById('cssInput');
const baseResultInput = document.getElementById('baseResultInput');
const runOptimizeBtn = document.getElementById('runOptimizeBtn');
const basePreview = document.getElementById('basePreview');
const optimizedPreview = document.getElementById('optimizedPreview');
const optimizeSummary = document.getElementById('optimizeSummary');

void initialize();

saveBtn.addEventListener('click', async () => {
  setStatus('Saving settings...');
  try {
    const ai = readForm();
    const response = await sendMessage({
      action: 'setAIConfig',
      ai
    });

    if (!response?.success) {
      throw new Error(response?.error || 'Failed to save settings');
    }

    setStatus('Settings saved.', 'success');
  } catch (error) {
    setStatus(error instanceof Error ? error.message : 'Failed to save settings', 'error');
  }
});

testBtn.addEventListener('click', async () => {
  setStatus('Testing connection...');
  try {
    const aiConfig = readForm();
    const response = await sendMessage({
      action: 'testAIConnection',
      aiConfig
    });

    if (!response?.success) {
      throw new Error(response?.error || 'Connection test failed');
    }

    if (response?.data?.ok) {
      setStatus('Connection successful.', 'success');
      return;
    }

    setStatus('Connection failed. Check your API key or model.', 'error');
  } catch (error) {
    setStatus(error instanceof Error ? error.message : 'Connection test failed', 'error');
  }
});

openExportAssistantBtn.addEventListener('click', () => {
  const url = chrome.runtime.getURL('export/export.html');
  window.open(url, '_blank');
});

runOptimizeBtn.addEventListener('click', async () => {
  setOptimizeSummary('Running AI optimization...');
  try {
    const baseResult = parseBaseResult();
    basePreview.textContent = JSON.stringify(baseResult, null, 2);

    const response = await sendMessage({
      action: 'aiOptimize',
      elementData: {
        html: htmlInput.value || '',
        css: cssInput.value || ''
      },
      baseResult,
      aiConfig: readForm()
    });

    if (!response?.success) {
      throw new Error(response?.error || 'AI optimize failed');
    }

    optimizedPreview.textContent = JSON.stringify(response.data?.data || {}, null, 2);
    const summary = response.data?.ai?.explanation || (response.data?.used_ai ? 'AI optimization complete.' : 'AI disabled, returned base result.');
    setOptimizeSummary(summary, response.data?.used_ai ? 'success' : '');
  } catch (error) {
    optimizedPreview.textContent = '';
    setOptimizeSummary(error instanceof Error ? error.message : 'AI optimize failed', 'error');
  }
});

async function initialize() {
  setStatus('Loading settings...');
  try {
    const response = await sendMessage({
      action: 'getAIConfig'
    });

    if (!response?.success) {
      throw new Error(response?.error || 'Failed to load settings');
    }

    writeForm(response.data || {});
    setStatus('Ready');
  } catch (error) {
    setStatus(error instanceof Error ? error.message : 'Failed to load settings', 'error');
  }
}

function readForm() {
  return {
    enabled: form.enabled.checked,
    provider: form.provider.value || 'openrouter',
    model: form.model.value || 'gpt-4o-mini',
    apiKey: form.apiKey.value.trim(),
    autoOptimize: form.autoOptimize.checked
  };
}

function writeForm(ai) {
  form.enabled.checked = Boolean(ai.enabled);
  form.provider.value = ai.provider || 'openrouter';
  form.model.value = ai.model || 'gpt-4o-mini';
  form.apiKey.value = ai.apiKey || '';
  form.autoOptimize.checked = Boolean(ai.autoOptimize);
}

function sendMessage(payload) {
  return new Promise((resolve) => {
    chrome.runtime.sendMessage(payload, (response) => {
      const runtimeError = chrome.runtime?.lastError;
      if (runtimeError) {
        resolve({ success: false, error: runtimeError.message });
        return;
      }
      resolve(response);
    });
  });
}

function setStatus(message, type = '') {
  statusNode.textContent = message;
  statusNode.className = `status ${type}`.trim();
}

function parseBaseResult() {
  const raw = baseResultInput.value.trim();
  if (!raw) {
    return { settings: {} };
  }

  try {
    const parsed = JSON.parse(raw);
    if (!parsed || typeof parsed !== 'object') {
      throw new Error('Base result must be a JSON object');
    }
    return parsed;
  } catch (error) {
    throw new Error(error instanceof Error ? `Invalid base JSON: ${error.message}` : 'Invalid base JSON');
  }
}

function setOptimizeSummary(message, type = '') {
  optimizeSummary.textContent = message;
  optimizeSummary.className = `status ${type}`.trim();
}
