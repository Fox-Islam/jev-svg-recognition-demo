// resources/classify.js gives the probabilities the fitted scikit-learn pipeline gives (fixture from scripts/build_assets.py).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { classify } from '../resources/classify.js';

const read = p => JSON.parse(readFileSync(new URL(p, import.meta.url)));

for (const fx of read('./fixtures/classify.json')) {
  test(`${fx.model}: the page's classifier matches the trained pipeline`, () => {
    const model = read(`../models/${fx.model}.json`);
    fx.answers.forEach((answers, row) => {
      const { ranked } = classify(model, answers);
      const byLabel = Object.fromEntries(ranked.map(r => [r.label, r.p]));
      model.labels.forEach((label, k) => assert.ok(Math.abs(byLabel[label] - fx.probabilities[row][k]) < 1e-4, `${label}: ${byLabel[label]} vs ${fx.probabilities[row][k]}`));
    });
  });
}
