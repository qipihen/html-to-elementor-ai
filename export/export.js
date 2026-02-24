const inputJson = document.getElementById('inputJson');
const outputJson = document.getElementById('outputJson');
const statusNode = document.getElementById('status');
const exportBtn = document.getElementById('exportBtn');
const copyBtn = document.getElementById('copyBtn');
const downloadBtn = document.getElementById('downloadBtn');

let lastOutput = '';

exportBtn.addEventListener('click', async () => {
  setStatus('Generating export...');
  try {
    const parsedInput = parseInput(inputJson.value);
    const request = buildBackgroundRequest(parsedInput);
    const response = await sendMessage({
      action: 'exportForSkill',
      ...request
    });

    if (!response?.success) {
      throw new Error(response?.error || 'Export generation failed');
    }

    lastOutput = JSON.stringify(response.data?.skillPayload || {}, null, 2);
    outputJson.textContent = lastOutput;
    setStatus('Export generated.', 'success');
  } catch (error) {
    lastOutput = '';
    outputJson.textContent = '';
    setStatus(error instanceof Error ? error.message : 'Export generation failed', 'error');
  }
});

copyBtn.addEventListener('click', async () => {
  if (!lastOutput) {
    setStatus('No output to copy.', 'error');
    return;
  }

  try {
    await navigator.clipboard.writeText(lastOutput);
    setStatus('Output copied.', 'success');
  } catch {
    setStatus('Failed to copy output.', 'error');
  }
});

downloadBtn.addEventListener('click', () => {
  if (!lastOutput) {
    setStatus('No output to download.', 'error');
    return;
  }

  const blob = new Blob([lastOutput], { type: 'application/json' });
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = `skill-export-${Date.now()}.json`;
  anchor.click();
  URL.revokeObjectURL(url);
  setStatus('Output downloaded.', 'success');
});

function parseInput(raw) {
  const value = raw.trim();
  if (!value) {
    throw new Error('Input JSON is required');
  }

  try {
    return JSON.parse(value);
  } catch (error) {
    throw new Error(error instanceof Error ? `Invalid JSON: ${error.message}` : 'Invalid JSON');
  }
}

function buildBackgroundRequest(payload) {
  if (payload && typeof payload === 'object' && Array.isArray(payload.capturedData)) {
    return {
      capturedData: payload.capturedData,
      routeSuggestion: payload.routeSuggestion || {},
      context: payload.context || {}
    };
  }

  if (payload && typeof payload === 'object' && Array.isArray(payload.elements) && typeof payload.route === 'string') {
    return {
      capturedData: payload.elements.map(normalizeElementForCapture),
      routeSuggestion: {
        route: payload.route,
        score: payload.complexity_score || 0
      },
      context: {
        pageUrl: payload.metadata?.page_url || '',
        viewport: payload.metadata?.viewport || null
      }
    };
  }

  if (payload && typeof payload === 'object' && payload.type === 'elementor' && Array.isArray(payload.elements)) {
    return {
      capturedData: payload.elements.map((element) =>
        normalizeElementForCapture({
          id: element.id,
          convertedResult: element
        })
      ),
      routeSuggestion: { route: 'elementor-json', score: 0 },
      context: {}
    };
  }

  if (Array.isArray(payload)) {
    return {
      capturedData: payload.map(normalizeElementForCapture),
      routeSuggestion: { route: 'elementor-json', score: 0 },
      context: {}
    };
  }

  return {
    capturedData: [normalizeElementForCapture(payload)],
    routeSuggestion: { route: 'elementor-json', score: 0 },
    context: {}
  };
}

function normalizeElementForCapture(element) {
  const source = element && typeof element === 'object' ? element : {};
  return {
    id: source.id || source.section || `section_${Math.random().toString(36).slice(2, 8)}`,
    blockType: source.blockType || source.type || 'static',
    html: source.html || '',
    css: source.css || '',
    convertedResult: source.convertedResult || source.converted || source.element || {}
  };
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
