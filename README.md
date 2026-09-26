# jev-svg-recognition-demo

A page that classifies an SVG from [Jev](https://docs.typesafe.ai)'s answers to 100 yes/no questions about its
text, and the PHP server behind it. The classifier and the questions come from
[jev-svg-recognition](https://github.com/Fox-Islam/jev-svg-recognition).

Pick a sample or import an SVG, press Run, and the page shows each of the 100 questions being answered as its call
returns, then the labels the classifier gives and how much each answer pushed towards the top one.

```bash
composer install
composer serve    # http://127.0.0.1:8767
```

Choose TypeSafe or OpenRouter and put your API key in beside it. The key is kept in that tab's session storage,
gone when the tab closes, and sent to the server with each call, which uses it for that call only.

## What happens on Run

1. The page sends the SVG to `POST /api/prepare`. The server sanitises it (`src/Svg.php`): comments, `<title>`,
   `<desc>` and `<text>` are removed and ids and class names renamed, because SVGs often name their parts. It
   is not normalised: the classifier was trained on sanitised text.
2. The page sends the 100 questions as four `POST /api/ask` calls of 25, all at once. Each is one request to Jev
   with the SVG as state, through [typesafe-sdk-php](https://github.com/Fox-Islam/typesafe-sdk-php), and each
   question's tile fills in as its call returns.
3. The page runs the classifier (`resources/classify.js`): each answer's log-odds, standardised, weighted per label,
   then a softmax. The server never combines the answers.

## The classifiers

| Classifier | Labels | Questions | Trained on | Samples |
|---|---|---|---|---|
| Objects | 23 objects drawn by LLMs | the jev-svg-recognition corpus100 | SVGs from 25 models | 2 per label, from 16 models it was not trained on |
| Emoji subgroups | 14 Unicode emoji subgroups | fixed100 | emoji in four styles | 3 per subgroup, from emoji identities it was not trained on |

On the held-out sets these are the classifiers of jev-svg-recognition's results: the objects classifier is right
for about 94 SVGs in 100, the emoji one for about 56 in 100. Expect the emoji samples to be wrong often.

An imported SVG is prepared for the classifier chosen in the page: objects like the Rapidata SVGs, emoji like the
Noto ones. It only has labels to choose from that the classifier knows.

## Settings

Settings come from the environment or a `.env` at the project root.

| Setting | |
| --- | --- |
| `TYPESAFE_API_KEY`, `OPENROUTER_API_KEY` | the server's key for that provider, used where the page sends none; anyone who can reach the page can spend it |
| `JEV_SVG_DEMO_HOSTS` | hosts a request may be addressed to, comma-separated; `127.0.0.1,localhost` when unset |

## Rebuilding the models and samples

`models/*.json` and `samples/` are written by `scripts/build_assets.py` from jev-svg-recognition, which it expects
beside this repository with its SVGs fetched:

```bash
uv sync
uv run python scripts/build_assets.py
```

It checks that each exported model gives the probabilities of the fitted one, and writes
`tests/fixtures/classify.json` for the page's classifier test.

## Development

```bash
composer test    # the server and the SVG preparation, which must match jev-svg-recognition byte for byte
npm test         # the page's classifier against the trained model
composer lint
```

The sample SVGs keep their own licences: [NOTICE.md](NOTICE.md).
