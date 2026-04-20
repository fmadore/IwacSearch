# Data sources for the IwacSearch indexer

## Decision

**Hybrid: HuggingFace for bulk reindex, Omeka S API for live updates and ACL.**

## Why hybrid

The indexer needs to populate ~14,500 Typesense documents with a rich set
of derived fields (OCR, AI sentiment, LDA topics, lexical metrics,
embeddings). Two viable upstreams exist:

| Source | URL |
|---|---|
| HuggingFace | https://huggingface.co/datasets/fmadore/islam-west-africa-collection |
| Omeka S API | https://islam.zmo.de/api |

### HF pros — used for the bulk reindex path

- All derived fields are precomputed and shipped as parquet:
  `OCR`, `descriptionAI`, `lda_topic_id` + `lda_topic_label`, the three-model
  AI sentiment columns (Gemini / ChatGPT / Mistral), lemmatized text,
  Type-Token Ratio, Flesch readability, and 768-dim Gemini embeddings.
- A single parquet download replaces ~14,500 Omeka API round trips on a
  cold reindex.
- No rate limiting, no auth needed for read-only access.
- Updated monthly (manual push from the curator) — fine for a corpus that
  grows by a few hundred items per refresh.

### HF cons — why Omeka still owns part of the pipeline

- `is_public` is **not** in HF. Public scoped keys gate on it
  (`filter_by: is_public:=true`); it must come from Omeka, fresh.
- Live edits in Omeka admin should appear in search within seconds,
  not next month. M4 wires `api.{create,update,delete}.post` listeners
  to upsert/delete in Typesense directly.
- HF has 26 `documents` and 45 `audiovisual` rows with no precomputed
  embeddings. Typesense's in-process `ts/multilingual-e5-small` model
  embeds those at index time.
- IIIF manifest URLs and thumbnail URLs can change between HF refreshes.

## Embedding model decision

The HF `embedding_OCR` field uses `gemini-embedding-2-preview` (768d).
Using it directly would require calling Gemini at *query* time to embed
user queries — adds an external API dependency, latency, and cost on
every search.

Cleaner: ignore HF embeddings, let Typesense's bundled
`ts/multilingual-e5-small` (384d) embed everything. Trade-off:

|  | Gemini (HF) | multilingual-e5-small (Typesense) |
|---|---|---|
| Dim | 768 | 384 |
| Quality on French | strong | good |
| Query-time cost | external API call | in-process, free |
| Self-contained | no | yes |
| Index-time cost | precomputed (free) | one-time CPU at index |

Picked the bundled model. Quality difference on a 14.5K corpus is
marginal; self-contained operation matters more.

## Authority records (the `index` HF subset)

The `index` subset (4,697 rows) is the controlled vocabulary for entities
referenced by `subject` and `spatial` strings in articles / publications /
references.

**Pattern in the indexer:** at bulk-reindex time, load `index` into a
hashmap keyed by `Titre`. For each content item, split `subject` /
`spatial` on `|`, look up each token, and emit:
- `topics_ss` for `Type == "Sujets"`
- `persons_ss` for `Type == "Personnes"`
- `places_ss` for `Type == "Lieux"`
- `organisations_ss` for `Type == "Organisations"`
- `events_ss` for `Type == "Événements"`
- `entity_ids` (int32[]) — the `o:id` of each matched entity, for
  outbound links

This pre-resolves the join at index time so faceting is a single
Typesense round trip, not N lookups per search.

## Subset coverage

| HF subset | Rows | Goes into `iwac_v1` as `type_s` | Has OCR | Has HF embedding |
|---|---:|---|:---:|:---:|
| `articles` | 12,287 | `article` | yes | yes (ignored) |
| `publications` | 1,501 | `publication` | yes | yes (ignored, on tableOfContents) |
| `documents` | 26 | `document` | yes | no |
| `audiovisual` | 45 | `audiovisual` | no | no |
| `references` | 864 | (skipped — bibliographic only) | no | no |
| `index` | 4,697 | (used as authority hashmap, not indexed as items) | no | no |

Total content items: **13,859** indexed into Typesense.
