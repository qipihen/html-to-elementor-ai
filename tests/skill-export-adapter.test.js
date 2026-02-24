import test from 'node:test';
import assert from 'node:assert/strict';

import { buildSkillImportPayload } from '../lib/skill-export-adapter.js';

test('buildSkillImportPayload returns section contracts for captured elements', () => {
  const payload = buildSkillImportPayload({
    captureExport: {
      route: 'hybrid',
      complexity_score: 4,
      elements: [
        {
          id: 'hero_001',
          type: 'static',
          suggested_fields: []
        },
        {
          id: 'product_list_001',
          type: 'dynamic',
          suggested_fields: ['product_title', 'product_image']
        }
      ],
      metadata: {
        page_url: 'https://example.com/products',
        viewport: { width: 1280, height: 720 }
      }
    }
  });

  assert.equal(payload.route.selected, 'hybrid');
  assert.equal(payload.section_contracts.length, 2);
  assert.equal(payload.section_contracts[0].section, 'hero_001');
  assert.equal(payload.section_contracts[1].fields_new[0], 'product_title');
  assert.equal(payload.section_contracts[1].query_scope.taxonomy_only, true);
});

test('buildSkillImportPayload uses defaults when fields are missing', () => {
  const payload = buildSkillImportPayload({
    captureExport: {
      elements: [{}],
      metadata: {}
    }
  });

  assert.equal(payload.route.selected, 'elementor-json');
  assert.equal(payload.section_contracts[0].source_mode, 'ai-code');
  assert.equal(payload.section_contracts[0].fallback, 'strict-empty');
  assert.equal(payload.section_contracts[0].parity_gate.fail_action, 'route_downgrade');
});
