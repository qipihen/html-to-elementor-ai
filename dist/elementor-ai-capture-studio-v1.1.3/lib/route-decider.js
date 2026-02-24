const SCORE_FACTORS = {
  data: ['hasFilters', 'hasQueries', 'hasTaxonomyCoupling'],
  motion: ['hasScrollTimeline', 'hasAbsolutePositioning', 'hasMicroInteractions'],
  runtime: ['hasStatefulBehavior', 'hasConditionalRendering', 'hasComplexAnimations']
};

const ROUTE_MAP = [
  {
    maxScore: 2,
    route: 'elementor-json',
    description: '适合纯 Elementor JSON 导入',
    steps: ['直接导出 Elementor JSON']
  },
  {
    maxScore: 5,
    route: 'hybrid',
    description: '建议混合模式',
    steps: ['Elementor 布局', '插件渲染复杂区块']
  },
  {
    maxScore: Number.POSITIVE_INFINITY,
    route: 'plugin',
    description: '建议完整插件',
    steps: ['ACF 字段生成', '插件代码生成']
  }
];

export function calculateComplexityScore(input = {}) {
  const breakdown = {
    data: scoreGroup(input, SCORE_FACTORS.data),
    motion: scoreGroup(input, SCORE_FACTORS.motion),
    runtime: scoreGroup(input, SCORE_FACTORS.runtime)
  };

  const score = breakdown.data + breakdown.motion + breakdown.runtime;

  return {
    score,
    breakdown,
    active_factors: listActiveFactors(input)
  };
}

export function suggestRoute(score) {
  const selected = ROUTE_MAP.find((item) => score <= item.maxScore);
  return {
    route: selected.route,
    description: selected.description,
    steps: selected.steps
  };
}

export function decideRoute(input = {}) {
  const analysis = calculateComplexityScore(input);
  const routeSuggestion = suggestRoute(analysis.score);

  return {
    ...routeSuggestion,
    score: analysis.score,
    breakdown: analysis.breakdown,
    active_factors: analysis.active_factors
  };
}

function scoreGroup(input, keys) {
  return keys.reduce((count, key) => count + (input[key] ? 1 : 0), 0);
}

function listActiveFactors(input) {
  return Object.values(SCORE_FACTORS)
    .flat()
    .filter((key) => Boolean(input[key]));
}

