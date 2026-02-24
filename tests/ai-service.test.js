import test from 'node:test';
import assert from 'node:assert/strict';

import { AIService } from '../lib/ai-service.js';

test('buildMessages creates system and user prompts', () => {
  const service = new AIService({ fetcher: async () => ({ ok: true, json: async () => ({}) }) });
  service.init({ provider: 'openrouter', model: 'gpt-4o-mini', apiKey: 'sk-test' });

  const messages = service.buildMessages({
    html: '<div>Hello</div>',
    css: '.box { color: red; }',
    currentResult: '{"settings":{}}'
  });

  assert.equal(messages.length, 2);
  assert.equal(messages[0].role, 'system');
  assert.equal(messages[1].role, 'user');
  assert.ok(messages[1].content.includes('<div>Hello</div>'));
});

test('parseResponse handles markdown code fences', () => {
  const service = new AIService({ fetcher: async () => ({ ok: true, json: async () => ({}) }) });
  const result = service.parseResponse({
    choices: [
      {
        message: {
          content:
            '```json\n{"optimized_settings":{"padding":"20px"},"layout_enhancements":[],"simplified_count":2,"explanation":"ok"}\n```'
        }
      }
    ]
  });

  assert.equal(result.simplified_count, 2);
  assert.equal(result.optimized_settings.padding, '20px');
});

test('optimize calls API and returns parsed JSON', async () => {
  const service = new AIService({
    fetcher: async () => ({
      ok: true,
      json: async () => ({
        choices: [
          {
            message: {
              content:
                '{"optimized_settings":{"color":"#ffffff"},"layout_enhancements":[],"simplified_count":1,"explanation":"done"}'
            }
          }
        ]
      })
    })
  });

  service.init({ provider: 'openrouter', model: 'gpt-4o-mini', apiKey: 'sk-test' });

  const result = await service.optimize({
    html: '<h1>Title</h1>',
    css: 'h1 { color: #fff; }',
    currentResult: '{"elType":"widget"}'
  });

  assert.equal(result.simplified_count, 1);
  assert.equal(result.optimized_settings.color, '#ffffff');
});

test('testConnection returns true when model endpoint is reachable', async () => {
  const service = new AIService({
    fetcher: async () => ({
      ok: true,
      json: async () => ({ data: [{ id: 'gpt-4o-mini' }] })
    })
  });

  service.init({ provider: 'openrouter', model: 'gpt-4o-mini', apiKey: 'sk-test' });
  const isConnected = await service.testConnection();

  assert.equal(isConnected, true);
});
