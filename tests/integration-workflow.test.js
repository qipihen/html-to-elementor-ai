import test from 'node:test';
import assert from 'node:assert/strict';

import { identifyBlockType } from '../lib/block-identifier.js';
import { decideRoute } from '../lib/route-decider.js';
import { EnhancedConverter } from '../lib/enhanced-converter.js';
import { exportForSkill } from '../lib/export-service.js';
import { buildSkillImportPayload } from '../lib/skill-export-adapter.js';

test('integration workflow: capture -> analyze -> ai optimize -> skill export', async () => {
  const capturedElement = {
    id: 'products_001',
    html: '<section>{{ product_title }}</section>',
    innerHTML: '{{ product_title }}',
    attributes: [{ name: 'v-if', value: 'products.length' }],
    css: '.products { display: grid; gap: 16px; }',
    children: [
      { tagName: 'article', className: 'card' },
      { tagName: 'article', className: 'card' }
    ]
  };

  const block = identifyBlockType(capturedElement);
  const route = decideRoute({
    hasQueries: true,
    hasFilters: true,
    hasConditionalRendering: true,
    hasStatefulBehavior: true
  });

  const converter = new EnhancedConverter({
    configManager: {
      async getConfig() {
        return {
          ai: {
            enabled: true,
            provider: 'openrouter',
            model: 'gpt-4o-mini',
            apiKey: 'sk-test'
          }
        };
      }
    },
    aiService: {
      init() {},
      async optimize() {
        return {
          optimized_settings: {
            title: 'AI Product List',
            padding: '24px'
          },
          layout_enhancements: ['grid layout preserved'],
          simplified_count: 3,
          explanation: 'optimized'
        };
      }
    },
    logger: { error() {} }
  });

  const baseResult = {
    elType: 'section',
    settings: { title: 'Original Product List' }
  };

  const aiResult = await converter.enhanceWithAI(
    { html: capturedElement.html, css: capturedElement.css },
    baseResult
  );

  const exportPayload = exportForSkill({
    capturedData: [
      {
        id: capturedElement.id,
        blockType: block.type,
        html: capturedElement.html,
        css: capturedElement.css,
        convertedResult: aiResult.data,
        suggestedFields: block.suggested_fields
      }
    ],
    routeSuggestion: route,
    context: {
      pageUrl: 'https://example.com/products',
      viewport: { width: 1280, height: 720 }
    }
  });

  const skillPayload = buildSkillImportPayload({
    captureExport: exportPayload,
    sourceMode: 'ai-code'
  });

  assert.equal(block.type, 'dynamic');
  assert.equal(route.route, 'hybrid');
  assert.equal(aiResult.used_ai, true);
  assert.equal(exportPayload.route, 'hybrid');
  assert.equal(skillPayload.route.selected, 'hybrid');
  assert.equal(skillPayload.section_contracts[0].section, 'products_001');
  assert.equal(skillPayload.section_contracts[0].fields_new[0], 'product_title');
});

test('integration workflow fallback: ai failure still exports base result', async () => {
  const converter = new EnhancedConverter({
    configManager: {
      async getConfig() {
        return {
          ai: {
            enabled: true,
            provider: 'openrouter',
            model: 'gpt-4o-mini',
            apiKey: 'sk-test'
          }
        };
      }
    },
    aiService: {
      init() {},
      async optimize() {
        throw new Error('timeout');
      }
    },
    logger: { error() {} }
  });

  const baseResult = {
    elType: 'section',
    settings: { title: 'Fallback Title' }
  };

  const aiResult = await converter.enhanceWithAI(
    { html: '<section>fallback</section>', css: '.x{color:red}' },
    baseResult
  );

  const exportPayload = exportForSkill({
    capturedData: [
      {
        id: 'fallback_001',
        blockType: 'static',
        html: '<section>fallback</section>',
        css: '.x{color:red}',
        convertedResult: aiResult.data,
        suggestedFields: []
      }
    ],
    routeSuggestion: { route: 'elementor-json', score: 1 },
    context: {
      pageUrl: 'https://example.com/fallback',
      viewport: { width: 390, height: 844 }
    }
  });

  assert.equal(aiResult.used_ai, false);
  assert.equal(aiResult.error, 'timeout');
  assert.equal(exportPayload.elements[0].converted.settings.title, 'Fallback Title');
  assert.equal(exportPayload.metadata.device_hints[0], 'mobile');
});
