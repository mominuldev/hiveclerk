# The retrieval evaluation set

The corpus, the questions, and how to turn them into the recall and MRR
figures the CHANGELOG reports.

**The scorer is not in this directory.** It is `tools/retrieval-eval.php`,
one level up, next to the synthetic benchmark it is easily confused with.
This file exists because an audit of the repository looked here, found a
corpus and a question set with no runner beside them, and concluded the
numbers in the CHANGELOG were unreproducible. They are not — but nothing
here said so, which is close enough to the same problem.

## The two measurements, which are not interchangeable

| Tool | Answers | Needs |
|---|---|---|
| `tools/retrieval-eval.php` | recall@k, MRR, latency, cost against **real questions and real embeddings** | a provider key and an indexed corpus |
| `tools/retrieval-bench.php` | quantisation recall, search latency and peak memory at 1k/10k/50k chunks | nothing — it generates its own vectors |

The benchmark measures *our* code. The evaluation measures the product a
customer experiences, which includes an embedding model's judgement about
what a sentence means. Neither substitutes for the other, and the M1 gate
is defined by figures from both.

## Reproducing the end-to-end numbers

```bash
# 1. Seed twelve pages of business prose as published content.
#    `flat` seeds the same prose with the heading markup removed, which is
#    what the chunker comparison in the CHANGELOG was measured against.
wp eval-file tools/eval/seed-corpus.php
wp eval-file tools/eval/seed-corpus.php flat

# 2. Index and embed it through the admin, or re-index the seeded source.
#    This spends money: every chunk is an embedding call.

# 3. Resolve page titles to the document ids this install gave them.
#    Ids change on every re-index, so questions.json is regenerated rather
#    than edited — a stale integer fails as a recall miss rather than as an
#    error, which is the worst failure mode a measurement tool can have.
wp eval-file tools/eval/build-questions.php

# 4. Score it.
wp eval-file tools/retrieval-eval.php eval/questions.json 5
```

The last step prints recall@5 against the 0.90 floor, MRR, p95 and median
latency with the provider's share broken out, and the cost of the run. It
exits non-zero when recall is below the floor, so it can gate a release.

## What is in here

- **`seed-corpus.php`** — twelve pages of realistic business prose
  (delivery, returns, warranty, sizing, payment, accounts, care, stock,
  wholesale, sustainability, gifting). The content the product is actually
  sold to answer from.
- **`questions.source.json`** — 54 questions written the way a visitor
  types them and deliberately *away* from the vocabulary of the page that
  answers them. The page says "three to five working days"; the question
  asks "how long until my parcel turns up". A question that reuses the
  page's words measures string matching and reports it as semantic
  retrieval.
- **`build-questions.php`** — resolves page titles to document ids at run
  time and writes `questions.json`.
- **`questions.json`** — generated. Do not hand-edit; it is only valid for
  the install that produced it.

## What these figures are worth

The corpus and the question set were written by the same hand, which is
weaker than a real customer's site and a stranger's question. 54 questions
is a quarter of the 200 the sprint plan named. The corpus is small enough
that top-5 is a few per cent of the index, so recall at ten thousand
chunks is a harder problem than this measures and would be expected to
fall.

Read the number as better than a probe run and worse than a measurement
against a real shop.
