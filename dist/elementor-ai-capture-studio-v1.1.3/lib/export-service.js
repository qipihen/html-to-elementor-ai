export function detectDevices(width) {
  const viewportWidth = Number(width);

  if (!Number.isFinite(viewportWidth) || viewportWidth <= 0) {
    return ['mobile', 'tablet', 'desktop'];
  }

  if (viewportWidth < 768) {
    return ['mobile'];
  }

  if (viewportWidth < 1024) {
    return ['mobile', 'tablet'];
  }

  return ['mobile', 'tablet', 'desktop'];
}

export function exportForSkill({ capturedData = [], routeSuggestion = {}, context = {} } = {}) {
  const viewport = normalizeViewport(context.viewport);
  const pageUrl = normalizePageUrl(context.pageUrl);

  return {
    version: '1.0',
    source: 'elementor-ai-capture-studio',
    exportTime: new Date().toISOString(),
    route: routeSuggestion.route || 'elementor-json',
    complexity_score: Number.isFinite(routeSuggestion.score) ? routeSuggestion.score : 0,
    elements: capturedData.map(normalizeElement),
    metadata: {
      page_url: pageUrl,
      viewport,
      device_hints: detectDevices(viewport.width)
    }
  };
}

function normalizeElement(element = {}) {
  return {
    id: element.id || '',
    type: element.blockType || 'static',
    html: element.html || '',
    css: element.css || '',
    converted: element.convertedResult || {},
    screenshot: element.screenshot || null,
    suggested_fields: Array.isArray(element.suggestedFields) ? element.suggestedFields : []
  };
}

function normalizeViewport(viewport) {
  const fallbackWidth = getWindowValue('innerWidth', 1366);
  const fallbackHeight = getWindowValue('innerHeight', 768);

  const width = Number(viewport?.width);
  const height = Number(viewport?.height);

  return {
    width: Number.isFinite(width) && width > 0 ? width : fallbackWidth,
    height: Number.isFinite(height) && height > 0 ? height : fallbackHeight
  };
}

function normalizePageUrl(pageUrl) {
  if (typeof pageUrl === 'string' && pageUrl.trim().length > 0) {
    return pageUrl;
  }
  return getWindowValue('location.href', '');
}

function getWindowValue(path, fallback) {
  if (typeof window === 'undefined') {
    return fallback;
  }

  if (path === 'location.href') {
    return window.location?.href || fallback;
  }

  return Number(window[path]) || fallback;
}
