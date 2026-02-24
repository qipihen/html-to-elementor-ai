import test from 'node:test';
import assert from 'node:assert/strict';

import { EnhancedConverter } from '../lib/enhanced-converter.js';

function createConfigManager(config) {
  return {
    async getConfig() {
      return config;
    },
    async isAIEnabled() {
      return Boolean(config?.ai?.enabled && config?.ai?.apiKey);
    }
  };
}

test('enhanceWithAI returns base result when AI is disabled', async () => {
  const converter = new EnhancedConverter({
    configManager: createConfigManager({
      ai: { enabled: false, apiKey: '' }
    }),
    aiService: {
      init() {},
      async optimize() {
        throw new Error('should not be called');
      }
    },
    logger: { error() {} }
  });

  const baseResult = { settings: { title: 'Hello' } };
  const result = await converter.enhanceWithAI(
    { html: '<h1>Hello</h1>', css: 'h1{color:red}' },
    baseResult
  );

  assert.equal(result.success, true);
  assert.equal(result.used_ai, false);
  assert.deepEqual(result.data, baseResult);
});

test('enhanceWithAI merges optimized settings when AI succeeds', async () => {
  const converter = new EnhancedConverter({
    configManager: createConfigManager({
      ai: { enabled: true, apiKey: 'sk-test', model: 'gpt-4o-mini', provider: 'openrouter' }
    }),
    aiService: {
      init() {},
      async optimize() {
        return {
          optimized_settings: { title: 'Optimized', padding: '24px' },
          layout_enhancements: ['align center'],
          simplified_count: 2,
          explanation: 'ok'
        };
      }
    },
    logger: { error() {} }
  });

  const baseResult = { settings: { title: 'Original', color: '#000' } };
  const result = await converter.enhanceWithAI(
    { html: '<h1>Hello</h1>', css: 'h1{color:red}' },
    baseResult
  );

  assert.equal(result.success, true);
  assert.equal(result.used_ai, true);
  assert.equal(result.data.settings.title, 'Optimized');
  assert.equal(result.data.settings.color, '#000');
  assert.equal(result.ai.simplified_count, 2);
});

test('enhanceWithAI falls back to base result when AI throws', async () => {
  const converter = new EnhancedConverter({
    configManager: createConfigManager({
      ai: { enabled: true, apiKey: 'sk-test', model: 'gpt-4o-mini', provider: 'openrouter' }
    }),
    aiService: {
      init() {},
      async optimize() {
        throw new Error('network timeout');
      }
    },
    logger: { error() {} }
  });

  const baseResult = { settings: { title: 'Original' } };
  const result = await converter.enhanceWithAI(
    { html: '<h1>Hello</h1>', css: 'h1{color:red}' },
    baseResult
  );

  assert.equal(result.success, false);
  assert.equal(result.used_ai, false);
  assert.equal(result.error, 'network timeout');
  assert.deepEqual(result.data, baseResult);
});
