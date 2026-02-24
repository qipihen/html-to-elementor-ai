import test from 'node:test';
import assert from 'node:assert/strict';

import { decideRoute } from '../lib/route-decider.js';

test('decideRoute recommends elementor-json for low complexity', () => {
  const result = decideRoute({});

  assert.equal(result.score, 0);
  assert.equal(result.route, 'elementor-json');
});

test('decideRoute recommends hybrid for medium complexity', () => {
  const result = decideRoute({
    hasFilters: true,
    hasQueries: true,
    hasScrollTimeline: true,
    hasStatefulBehavior: true
  });

  assert.equal(result.score, 4);
  assert.equal(result.route, 'hybrid');
});

test('decideRoute recommends plugin for high complexity', () => {
  const result = decideRoute({
    hasFilters: true,
    hasQueries: true,
    hasTaxonomyCoupling: true,
    hasScrollTimeline: true,
    hasAbsolutePositioning: true,
    hasMicroInteractions: true,
    hasStatefulBehavior: true
  });

  assert.equal(result.score, 7);
  assert.equal(result.route, 'plugin');
});
