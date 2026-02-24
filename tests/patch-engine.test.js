import test from 'node:test';
import assert from 'node:assert/strict';

import { PatchEngine } from '../lib/patch-engine.js';

test('PatchEngine setValue updates nested values', () => {
  const engine = new PatchEngine({
    settings: { title: 'Hello' }
  });

  engine.setValue('settings.title', 'Updated');
  assert.equal(engine.getValue('settings.title'), 'Updated');
});

test('PatchEngine deleteNode removes nested values', () => {
  const engine = new PatchEngine({
    settings: { subtitle: 'Text' }
  });

  engine.deleteNode('settings.subtitle');
  assert.equal(engine.getValue('settings.subtitle'), undefined);
});

test('PatchEngine move transfers values across paths', () => {
  const engine = new PatchEngine({
    settings: { oldKey: 'from' },
    meta: {}
  });

  engine.move('settings.oldKey', 'meta.newKey');

  assert.equal(engine.getValue('settings.oldKey'), undefined);
  assert.equal(engine.getValue('meta.newKey'), 'from');
});

test('PatchEngine tracks operations in changelog', () => {
  const engine = new PatchEngine({ settings: { title: 'A' } });
  engine.setValue('settings.title', 'B');

  const log = engine.getChangeLog();
  assert.equal(log.length, 1);
  assert.equal(log[0].op, 'set');
});
