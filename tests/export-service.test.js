import test from 'node:test';
import assert from 'node:assert/strict';

import { detectDevices, exportForSkill } from '../lib/export-service.js';

test('detectDevices returns mobile/tablet/desktop hints by width', () => {
  assert.deepEqual(detectDevices(375), ['mobile']);
  assert.deepEqual(detectDevices(900), ['mobile', 'tablet']);
  assert.deepEqual(detectDevices(1280), ['mobile', 'tablet', 'desktop']);
});

test('exportForSkill returns normalized payload', () => {
  const payload = exportForSkill({
    capturedData: [
      {
        id: 'hero_001',
        blockType: 'static',
        html: '<section class="hero"></section>',
        css: '.hero { min-height: 60vh; }',
        convertedResult: { elType: 'section' }
      }
    ],
    routeSuggestion: { route: 'elementor-json', score: 2 },
    context: {
      pageUrl: 'https://example.com',
      viewport: { width: 1280, height: 720 }
    }
  });

  assert.equal(payload.version, '1.0');
  assert.equal(payload.route, 'elementor-json');
  assert.equal(payload.elements.length, 1);
  assert.equal(payload.elements[0].id, 'hero_001');
  assert.deepEqual(payload.metadata.device_hints, ['mobile', 'tablet', 'desktop']);
});
