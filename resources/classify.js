// The classifier from jev-svg-recognition, as exported by scripts/build_assets.py: each answer's log-odds,
// standardised with the training means and spreads, weighted per label, then a softmax.

export function logit(p) {
  const q = Math.min(Math.max(p ?? 0.5, 0.001), 0.999);  // an unanswered question counts as 0.5, which is 0
  return Math.log(q / (1 - q));
}

// answers: P(yes) per question, in model.questions order.
// Returns labels sorted by probability, and each question's contribution to every label's score.
export function classify(model, answers) {
  const z = answers.map((p, i) => (logit(p) - model.mean[i]) / model.scale[i]);
  const scores = model.coef.map((w, k) => w.reduce((s, wi, i) => s + wi * z[i], model.intercept[k]));
  const top = Math.max(...scores);
  const e = scores.map(s => Math.exp(s - top));
  const sum = e.reduce((a, b) => a + b, 0);
  const ranked = model.labels.map((label, k) => ({ label, p: e[k] / sum, k })).sort((a, b) => b.p - a.p);
  const contribution = k => model.coef[k].map((w, i) => w * z[i]);
  return { ranked, contribution };
}
