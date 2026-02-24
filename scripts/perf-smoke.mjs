import { performance } from 'node:perf_hooks';

import { identifyBlockType } from '../lib/block-identifier.js';
import { decideRoute } from '../lib/route-decider.js';
import { exportForSkill } from '../lib/export-service.js';
import { buildSkillImportPayload } from '../lib/skill-export-adapter.js';

const SAMPLE_CAPTURE = {
  id: 'perf_001',
  html: '<section><article class="card">{{ product_title }}</article><article class="card">{{ product_title }}</article></section>',
  innerHTML: '<article class="card">{{ product_title }}</article><article class="card">{{ product_title }}</article>',
  attributes: [{ name: 'v-if', value: 'products.length' }],
  children: [
    { tagName: 'article', className: 'card' },
    { tagName: 'article', className: 'card' }
  ],
  css: '.card { display: grid; gap: 16px; }'
};

const ITERATIONS = 1500;

function main() {
  const timings = {};

  timings.blockIdentifier = measure(() => {
    identifyBlockType(SAMPLE_CAPTURE);
  });

  timings.routeDecider = measure(() => {
    decideRoute({
      hasQueries: true,
      hasFilters: true,
      hasConditionalRendering: true,
      hasStatefulBehavior: true
    });
  });

  const exportPayload = exportForSkill({
    capturedData: [
      {
        id: SAMPLE_CAPTURE.id,
        blockType: 'dynamic',
        html: SAMPLE_CAPTURE.html,
        css: SAMPLE_CAPTURE.css,
        convertedResult: { elType: 'section', settings: { title: 'Perf' } },
        suggestedFields: ['product_title']
      }
    ],
    routeSuggestion: { route: 'hybrid', score: 4 },
    context: { pageUrl: 'https://example.com/perf', viewport: { width: 1280, height: 720 } }
  });

  timings.skillExportAdapter = measure(() => {
    buildSkillImportPayload({ captureExport: exportPayload });
  });

  const total = Number(
    (timings.blockIdentifier + timings.routeDecider + timings.skillExportAdapter).toFixed(3)
  );

  const result = {
    iterations: ITERATIONS,
    timings_ms: timings,
    total_ms: total
  };

  process.stdout.write(`${JSON.stringify(result, null, 2)}\n`);
}

function measure(fn) {
  const start = performance.now();
  for (let index = 0; index < ITERATIONS; index += 1) {
    fn();
  }
  const end = performance.now();
  return Number((end - start).toFixed(3));
}

main();

