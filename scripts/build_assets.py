"""Write models/*.json and samples/ from the jev-svg-recognition repository.

    uv run python scripts/build_assets.py

Needs that repository beside this one, with its SVGs fetched (`scripts/fetch.py emoji` and `rapidata` there). Each
model is trained only on items outside its sample list, so every sample is one the model has not seen.
"""

import collections
import json
import random
import shutil
import sys
import warnings
from pathlib import Path

import numpy as np
from jevsvg import data as D
from jevsvg import experiments as E

warnings.filterwarnings('ignore')
ROOT = Path(__file__).resolve().parent.parent


def export(pipeline, labels, questions, question_set, recipe, name, title, description):
    """The fitted scaler and logistic regression as plain numbers, for static/classify.js."""
    scaler, lr = pipeline[0], pipeline[-1]
    return {
        'name': name,
        'title': title,
        'description': description,
        'recipe': recipe,
        'question_set': question_set,
        'questions': questions,
        'labels': [labels[int(c)] for c in lr.classes_],
        'mean': scaler.mean_.round(6).tolist(),
        'scale': scaler.scale_.round(6).tolist(),
        'coef': lr.coef_.round(6).tolist(),
        'intercept': lr.intercept_.round(6).tolist(),
    }


def check(model, pipeline, X):
    """The exported numbers give the probabilities the fitted pipeline does."""
    z = (X - np.array(model['mean'])) / np.array(model['scale'])
    s = z @ np.array(model['coef']).T + np.array(model['intercept'])
    p = np.exp(s - s.max(1, keepdims=True))
    p /= p.sum(1, keepdims=True)
    assert np.abs(p - pipeline.predict_proba(X)).max() < 1e-4, 'exported model disagrees with the fitted one'


FIXTURES = []


def objects():
    ans, qids = D.answers('rapidata'), D.ids('corpus100')
    text = {q['id']: q['question'] for q in D.questions('corpus')}
    items = D.manifest('rapidata')
    labels = sorted({i['label'] for i in items})
    tr = [i for i in items if i['split'] == 'train']
    X = D.features(ans, tr, 'colour', qids, 'corpus')
    pipe = E.model().fit(X, [labels.index(i['label']) for i in tr])
    model = export(
        pipe,
        labels,
        [{'id': q, 'text': text[q]} for q in qids],
        'corpus100',
        'rapidata',
        'objects',
        'Objects',
        '23 objects drawn by LLMs (Rapidata/svg-benchmark), 100 corpus questions',
    )
    check(model, pipe, X)
    FIXTURES.append(fixture(model, pipe, X))
    rng = random.Random(0)
    by = collections.defaultdict(list)
    for i in items:
        if i['split'] == 'test':
            by[i['label']].append(i)
    samples = [
        dict(i, model='objects', source=i['model']) for label in labels for i in rng.sample(by[label], min(2, len(by[label])))
    ]
    return model, samples


def emoji():
    ans, qids = D.answers('emoji'), D.ids('fixed100')
    text = {q['id']: q['question'] for q in D.questions('fixed100')}
    items = E.emoji_items()
    tr = [i for i in items if i['noto_heldout'] != '1']
    X = D.features(ans, tr, 'colour', qids)
    pipe = E.model().fit(X, [D.SUBGROUPS.index(i['subgroup']) for i in tr])
    # a new SVG is prepared like Noto's: sanitised first, since it may name its parts
    model = export(
        pipe,
        D.SUBGROUPS,
        [{'id': q, 'text': text[q]} for q in qids],
        'fixed100',
        'noto',
        'emoji',
        'Emoji subgroups',
        '14 Unicode emoji subgroups in four emoji styles, fixed 100 questions',
    )
    check(model, pipe, X)
    FIXTURES.append(fixture(model, pipe, X))
    rng = random.Random(0)
    held = [i for i in items if i['noto_heldout'] == '1']
    samples = []
    for sg in D.SUBGROUPS:
        pool = [i for i in held if i['subgroup'] == sg]
        rng.shuffle(pool)
        styles_seen = set()
        for i in sorted(pool, key=lambda i: i['set'] in styles_seen):  # prefer a style not yet picked for this subgroup
            if len([s for s in samples if s['label'] == sg]) == 3:
                break
            if i['set'] not in styles_seen:
                styles_seen.add(i['set'])
                samples.append(dict(i, model='emoji', label=sg, source=i['set']))
    return model, samples


def fixture(model, pipeline, X, n=5):
    """Answer vectors and the probabilities the fitted pipeline gives, for tests/classify.test.mjs."""
    rows = X[:n]
    P = np.exp(rows) / (1 + np.exp(rows))  # back to P(yes) from log-odds
    return {
        'model': model['name'],
        'answers': P.round(6).tolist(),
        'probabilities': pipeline.predict_proba(D.logit(P.round(6))).round(6).tolist(),
    }


def main():
    (ROOT / 'models').mkdir(exist_ok=True)
    shutil.rmtree(ROOT / 'samples', ignore_errors=True)
    (ROOT / 'samples').mkdir()
    index = []
    for build in (objects, emoji):
        model, samples = build()
        (ROOT / 'models' / f'{model["name"]}.json').write_text(json.dumps(model, separators=(',', ':')))
        for n, s in enumerate(samples):
            src = E.svg_path(s)
            if not src.exists():
                sys.exit(f'{src} is missing: run scripts/fetch.py in jev-svg-recognition first')
            file = f'{model["name"]}-{n:02d}.svg'
            shutil.copy(src, ROOT / 'samples' / file)
            index.append(
                {
                    'file': file,
                    'model': s['model'],
                    'label': s['label'],
                    'source': s['source'],
                    'recipe': s.get('recipe', 'rapidata'),
                    'item_id': s['item_id'],
                    'sha1_colour': s['sha1_colour'],
                }
            )
        print(f'{model["name"]}: {len(model["labels"])} labels, {len(model["questions"])} questions, {len(samples)} samples')
    (ROOT / 'samples' / 'index.json').write_text(json.dumps(index, indent=1) + '\n')
    (ROOT / 'tests' / 'fixtures').mkdir(parents=True, exist_ok=True)
    (ROOT / 'tests' / 'fixtures' / 'classify.json').write_text(json.dumps(FIXTURES) + '\n')


if __name__ == '__main__':
    main()
