const DEFAULT_PARITY_GATE = {
  pass_threshold: 'defined',
  fail_action: 'route_downgrade'
};

export function buildSkillImportPayload({ captureExport = {}, sourceMode = 'ai-code' } = {}) {
  const selectedRoute = captureExport.route || 'elementor-json';
  const complexityScore = Number.isFinite(captureExport.complexity_score) ? captureExport.complexity_score : 0;
  const metadata = captureExport.metadata || {};
  const elements = Array.isArray(captureExport.elements) ? captureExport.elements : [];

  return {
    schema_version: '1.0',
    producer: 'elementor-ai-capture-studio-extension',
    created_at: new Date().toISOString(),
    route: {
      selected: selectedRoute,
      complexity_score: complexityScore,
      downgrade_rule: 'elementor-json -> hybrid/plugin on parity fail'
    },
    source: {
      source_mode: sourceMode,
      page_url: metadata.page_url || '',
      viewport: metadata.viewport || null
    },
    section_contracts: elements.map((element, index) =>
      buildSectionContract({
        element,
        index,
        route: selectedRoute,
        sourceMode
      })
    ),
    raw_export: captureExport
  };
}

function buildSectionContract({ element = {}, index, route, sourceMode }) {
  const sectionId = element.id || `section_${String(index + 1).padStart(3, '0')}`;
  const blockType = normalizeBlockType(element.type);
  const suggestedFields = Array.isArray(element.suggested_fields)
    ? element.suggested_fields.filter((item) => typeof item === 'string' && item.trim().length > 0)
    : [];

  return {
    route,
    source_mode: sourceMode,
    section: sectionId,
    block_type: blockType,
    data_owner: inferDataOwner(blockType),
    fields_reused: [],
    fields_new: suggestedFields,
    fallback: 'strict-empty',
    derived_rules: [],
    empty_behavior: {
      hide_section: true,
      hide_title: true
    },
    query_scope: {
      taxonomy_only: blockType !== 'static',
      limit: 'all'
    },
    parity_gate: {
      ...DEFAULT_PARITY_GATE
    }
  };
}

function normalizeBlockType(type) {
  if (type === 'dynamic' || type === 'mixed' || type === 'static') {
    return type;
  }
  return 'static';
}

function inferDataOwner(blockType) {
  if (blockType === 'dynamic' || blockType === 'mixed') {
    return 'taxonomy:product_cat';
  }
  return 'static:page';
}
