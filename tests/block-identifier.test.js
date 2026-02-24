import test from 'node:test';
import assert from 'node:assert/strict';

import { BlockType, identifyBlockType } from '../lib/block-identifier.js';

test('identifyBlockType returns static for plain markup', () => {
  const result = identifyBlockType({
    html: '<section><h1>Hello</h1><p>World</p></section>',
    attributes: []
  });

  assert.equal(result.type, BlockType.STATIC);
  assert.equal(result.suggested_fields.length, 0);
});

test('identifyBlockType returns dynamic for data-binding attributes', () => {
  const result = identifyBlockType({
    html: '<div v-if="product">{{ product_title }}</div>',
    innerHTML: '{{ product_title }}',
    attributes: [{ name: 'v-if', value: 'product' }]
  });

  assert.equal(result.type, BlockType.DYNAMIC);
  assert.ok(result.reasons.includes('data_binding'));
  assert.ok(result.suggested_fields.includes('product_title'));
});

test('identifyBlockType returns mixed for repeated sibling pattern', () => {
  const result = identifyBlockType({
    html: '<div><article class="card"></article><article class="card"></article></div>',
    attributes: [],
    children: [
      { tagName: 'article', className: 'card' },
      { tagName: 'article', className: 'card' }
    ]
  });

  assert.equal(result.type, BlockType.MIXED);
  assert.ok(result.reasons.includes('repeater_pattern'));
});
