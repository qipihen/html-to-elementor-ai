import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const ROOT = process.cwd();

test('manifest should not keep upstream webstore binding fields', () => {
  const manifestPath = path.join(ROOT, 'manifest.json');
  const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));

  assert.equal(Object.prototype.hasOwnProperty.call(manifest, 'key'), false);
  assert.equal(Object.prototype.hasOwnProperty.call(manifest, 'update_url'), false);
});

test('content bundle should not expose license/paywall strings', () => {
  const bundlePath = path.join(ROOT, 'content-ui', 'index.iife.js');
  const content = fs.readFileSync(bundlePath, 'utf8');

  const forbiddenTokens = [
    'payhip.com',
    'example.invalid',
    'Get the license key',
    'You have reached the usage limit',
    '["Capture","Convert Code","License"]',
    'DO00V9Q3YVP7KFUCFN2A',
    'kCsKjBr/MZLpQ6h8d6HcYFstXF/0ne80awNsXPAhT8A',
    'figma2elementor.sgp1.digitaloceanspaces.com'
  ];

  for (const token of forbiddenTokens) {
    assert.equal(content.includes(token), false, `found forbidden token: ${token}`);
  }
});

test('content bundle should persist capture payload for Capture Hub', () => {
  const bundlePath = path.join(ROOT, 'content-ui', 'index.iife.js');
  const content = fs.readFileSync(bundlePath, 'utf8');

  assert.equal(content.includes('lastClickedElementData'), true);
  assert.equal(content.includes('lastClickedAt'), true);
});

test('legacy in-page root should be hidden to avoid duplicate hub UI', () => {
  const bundlePath = path.join(ROOT, 'content-ui', 'index.iife.js');
  const content = fs.readFileSync(bundlePath, 'utf8');

  assert.equal(content.includes('QE.style.display = "none"'), true);
});
