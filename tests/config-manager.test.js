import test from 'node:test';
import assert from 'node:assert/strict';

import { ConfigManager } from '../lib/config-manager.js';

function createMemoryStorage(seed = {}) {
  const data = structuredClone(seed);

  return {
    async get(key) {
      if (typeof key === 'string') {
        return { [key]: data[key] };
      }

      if (Array.isArray(key)) {
        return key.reduce((acc, k) => {
          acc[k] = data[k];
          return acc;
        }, {});
      }

      return structuredClone(data);
    },

    async set(value) {
      Object.assign(data, structuredClone(value));
    }
  };
}

test('getConfig returns defaults when no stored config exists', async () => {
  const manager = new ConfigManager({ storage: createMemoryStorage() });
  const config = await manager.getConfig();

  assert.equal(config.ai.enabled, false);
  assert.equal(config.ai.provider, 'openrouter');
  assert.equal(config.general.highlightElements, true);
});

test('setConfig merges partial config into defaults', async () => {
  const manager = new ConfigManager({ storage: createMemoryStorage() });

  await manager.setConfig({
    ai: { enabled: true, apiKey: 'sk-test' },
    general: { showCaptureHint: false }
  });

  const config = await manager.getConfig();
  assert.equal(config.ai.enabled, true);
  assert.equal(config.ai.provider, 'openrouter');
  assert.equal(config.general.highlightElements, true);
  assert.equal(config.general.showCaptureHint, false);
});

test('isAIEnabled and isAutoOptimize reflect stored settings', async () => {
  const manager = new ConfigManager({ storage: createMemoryStorage() });

  await manager.setConfig({
    ai: {
      enabled: true,
      apiKey: 'sk-test',
      autoOptimize: true
    }
  });

  assert.equal(await manager.isAIEnabled(), true);
  assert.equal(await manager.isAutoOptimize(), true);
});
