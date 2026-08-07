# Data source for the IwacSearch indexer

## Decision

**Read directly from the Omeka S MySQL database (Doctrine DBAL). One source
of truth.**

The indexer was originally hybrid — bulk content from the Hugging Face dataset,
`is_public` + live updates from Omeka. It now reads everything from Omeka's
database, including article/publication/document full text: Typesense
`ocr_text` is populated from Omeka `bibo:content`, not from the Hugging Face
`OCR` column. The Hugging Face dataset
([fmadore/islam-west-africa-collection](https://huggingface.co/datasets/fmadore/islam-west-africa-collection))
still exists as a published research artifact; it is simply no longer the
search index's source.

## Why the switch

The hybrid design rested on the premise that the enriched fields the search
index needs (OCR, AI sentiment, AI summary, resolved entities) only existed in
HF. That turned out to be ~90% false — verified field-by-field against the
live Omeka API (the Phase 0 parity spike):

| Search field                                           | In Omeka?           | Source                                                    |
| ------------------------------------------------------ | ------------------- | --------------------------------------------------------- |
| title / dates / language / type / publisher            | ✅                  | `dcterms:*`                                               |
| OCR full text (`ocr_text`)                             | ✅                  | `bibo:content`                                            |
| Display body (`abstract`)                              | ✅                  | AI summary → description → publication ToC excerpt        |
| Publication ToC (`toc_txt`)                            | ✅                  | `dcterms:tableOfContents`                                 |
| AI sentiment ×3 (centralité / polarité / subjectivité) | ✅                  | `iwac:{model}*` (see below)                               |
| entities (persons / places / orgs / events / subjects) | ✅                  | `dcterms:subject` + `dcterms:spatial` linked resources    |
| `is_public`                                            | ✅                  | `resource.is_public`                                      |
| **semantic embedding**                                 | ✅ (Typesense-side) | generated in-process from title + OCR + ToC               |
| `nb_words`                                             | ⚙️ recomputed       | word count of OCR                                         |
| **`lda_topic_label`**                                  | ❌ HF-only          | **dropped** — gensim LDA, not worth a parallel pipeline   |

Added in v3 (schema `iwac_v3` / `iwac_index_v2`):

| Search field                                             | Source                                                                        |
| -------------------------------------------------------- | ----------------------------------------------------------------------------- |
| `alt_title_txt` (content FTS + autocomplete)             | `dcterms:alternative` on the item                                             |
| `subjects_ss` (merged subject facet, references scope)   | `dcterms:subject` display values, all entity classes in one list              |
| `has_fulltext` (bool facet: full text publicly readable) | `bibo:content` present **and** `value.is_public = 1` (value-level visibility) |
| `is_part_of_ss` (entity index facet — org category)      | `dcterms:isPartOf` on the authority item (linked title or literal)            |

Added in v5 (schema `iwac_v5`):

| Search field                             | Source / behavior                                                                 |
| ---------------------------------------- | --------------------------------------------------------------------------------- |
| `toc_txt` (publication full-text field)  | all public `dcterms:tableOfContents` literals joined as one blob; stemmed/searchable |
| publication `abstract`                   | first 600 Unicode characters of `toc_txt` for the public result-card body         |
| non-press `abstract` fallback            | `dcterms:description` when `bibo:shortDescription` is absent                       |

Changed in v6 (schema `iwac_v6`):

| Search field                                     | Source / behavior                                                                |
| ------------------------------------------------ | -------------------------------------------------------------------------------- |
| `gpt_5_6_luna_*` (surfaced sentiment trio)       | `iwac:gpt56Luna*` — replaces the generation-1 `gemini_3_flash_preview_*` trio     |
| `mistral_small_2603_*`, `deepseek_v4_flash_0731_*` | `iwac:mistralSmall2603*` / `iwac:deepseekV4Flash0731*` — indexed, not surfaced  |

So HF bought exactly one facet (`lda_topic_label`) at the cost of a monthly
refresh lag, a two-source reconciliation (HF content + Omeka ACL overlay), and
an external dependency at index time. Reading MySQL directly:

- **Freshness.** A reindex reflects live Omeka, and incremental single-item
  updates become possible (M4).
- **One source of truth.** `is_public`, metadata, sentiment all come from one
  place. No HF↔Omeka reconciliation, no ACL overlay.
- **Self-contained.** No HF Datasets Server API at index time.
- **Same architecture as the sibling DRE-Search module** — the DBAL keyset
  paging, value-loading, and reverse-count primitives are lifted from it.

Semantic/hybrid search stays Typesense-native: the `embedding` field is
generated from the Omeka-derived `title_txt` + `ocr_text` + `toc_txt` fields at
index time.

## Embedding model

Unchanged, and never depended on HF. Typesense's bundled
`ts/multilingual-e5-small` (384d, in-process ONNX) embeds documents at index
time and queries at search time — no external API call, no latency, no cost.
(The HF dataset ships a 768d `gemini-embedding-2-preview` vector, but the
search index never used it: using it would mean calling Gemini at query time
to embed the user's query.)

## How fields are read from MySQL

`OmekaSourceReader` (the only class that touches SQL) streams items by resource
class with keyset pagination, batch-loading each page's property values, item
sets, and media thumbnail in one query each.

### Entities — resolved by class, not string

Content links entities through `dcterms:subject` and `dcterms:spatial` as
_value_resource_ links. `EntityAuthority` (built once from the entity classes
94/9/96/54/244) buckets each linked target by the TARGET's resource class:

| Class                                  | Type               | Content facet      |
| -------------------------------------- | ------------------ | ------------------ |
| 94 `foaf:Person`                       | Personnes          | `persons_ss`       |
| 96 `foaf:Organization`                 | Organisations      | `organisations_ss` |
| 9 `dcterms:Location`                   | Lieux              | `places_ss`        |
| 54 `bibo:Event`                        | Événements         | `events_ss`        |
| 244 `fabio:AuthorityFile` (item set 1) | Sujets             | `topics_ss`        |
| 244 (item set 267)                     | Notices d'autorité | — (browsable only) |

This is strictly more accurate than the old HF path, which matched entity
_titles_ as strings and silently dropped anything it couldn't resolve.
`entity_aliases_txt` (FTS-only recall: `RCI` → _Radio Côte d'Ivoire_) is the
target's `dcterms:alternative`; `entity_ids` are the linked `o:id`s.

### Country — derived, not stored

`country_ss` is not an Omeka property. `CountryResolver` derives it:

- **articles / publications** — from the newspaper/publisher name
  (`dcterms:publisher`, a literal) via `data/newspaper-countries.json` (ported
  from the HF `country_mapper`).
- **references / documents** — from membership in a per-country item set
  (Références / Documents divers).
- **audiovisual** — from a place heading naming a country (`dcterms:spatial`
  against `IwacInstance::COUNTRY_PLACE_NAMES`). Recordings have neither
  signal above: 44 of the 47 carry the producer "Daarul Hadeethis Salafiyyah"
  rather than a newspaper, and they sit in topical sets ("Enregistrements
  audio", "Collection de sermons islamiques sur vidéo"), not per-country ones.

The place path is **audiovisual-only** on purpose. Press items routinely
mention neighbouring countries in `dcterms:spatial`, and reading country from
there would file a Burkinabè article under Nigeria; the newspaper remains
their single country signal.

Watch the spelling: the place authority is `Nigéria` but the facet value —
and so the preset locked filter — is `Nigeria`. `COUNTRY_PLACE_NAMES` does
that translation on the way in. Emitting the accented form would index the
recordings under a country nothing filters on, which is precisely how
`/browse/nigeria` came to be empty: audiovisual resolved to no country at
all, and country presets exclude references, which were the only other
Nigerian material.

### Sentiment — categorical labels resolved to scores

Centralité and polarité are categorical labels (linked or literal). Subjectivité
is a linked-resource category for **all three** models, resolved to a 1–5 score:
`Très objectif`→1 … `Très subjectif`→5.

#### Which three models, and why the field names say so

Schema v6 indexes the **generation-2** annotators. Their Omeka properties name
the model outright, so the term and the index field are the same name in two
spellings:

| Omeka term                              | Index field (v6)                     |
| --------------------------------------- | ------------------------------------ |
| `iwac:gpt56LunaPolarite`                | `gpt_5_6_luna_polarite_ss`           |
| `iwac:gpt56LunaCentralite`              | `gpt_5_6_luna_centralite_ss`         |
| `iwac:gpt56LunaSubjectiviteScore`       | `gpt_5_6_luna_subjectivite`          |
| `iwac:mistralSmall2603*`                | `mistral_small_2603_*`               |
| `iwac:deepseekV4Flash0731*`             | `deepseek_v4_flash_0731_*`           |

Watch the DeepSeek prefix: `iwac:deepseekV4Flash*` (no date) is a **retired
preview run** that still holds ~11.5k annotations in Omeka. We read the `0731`
properties only; the two are different readings of the same corpus.

Generation 1 — `iwac:gemini*` / `iwac:chatgpt*` / `iwac:mistral*`, indexed
through v5 as `gemini_3_flash_preview_*` / `gpt_5_mini_*` /
`ministral_14b_2512_*` — is no longer indexed. Those properties named a **vendor
slot** and carried no `iwac:*Model` annotation, so nothing in the source recorded
which model ran; the model names came from the pipeline's git history
(January–February 2026: `gemini-3-flash-preview`, `gpt-5-mini`,
`ministral-14b-2512`). The values remain in Omeka and in the HF dataset.

`AbstractMapper::SENTIMENT_MODELS` is the one place the term→field map lives.
**A re-annotation with different models means new field names and a schema bump
— never repoint an existing prefix at a new model**, which would silently change
what an already-published facet URL means.

That rule is also why v6 **emptied** both rename maps
(`FacetCatalog::LEGACY_FIELD_ALIASES` for saved page-block configs,
`LEGACY_FILTER_FIELDS` in `src/svelte/lib/urlState.ts` for share links) instead
of pointing the generation-1 names at a generation-2 model: a bookmarked link
would have come back filtered on a different model's judgement under the name it
was shared with. Retired sentiment facets are dropped; an admin re-picks them on
the block, and a stale link runs unfiltered. The maps stay in place, empty, for
the next rename — `scripts/check-schema-drift.js` keeps the two in sync.

Only the `gpt_5_6_luna_*` trio is offered in the facet UI. GPT-5.6 Luna holds
that slot because it is the only one complete on all three properties (12,305
articles; DeepSeek 0731 is ~489 subjectivity values short). The other two models
are indexed and facetable, but comparing models is a dataset job, not a
search-sidebar one — and on centralité the Mistral family is a documented
systematic outlier (`mistral-small-2603` runs κ 0.244–0.270 pairwise against
0.511–0.725 for non-Mistral pairs), so a "2 of 3 models agree" reading of the
sidebar would be misleading. `*_subjectivite` is the weakest of the three
measures generally — inter-model κ as low as 0.093 in the v2 pilot — so treat it
as weak evidence.

## Subset → resource class

| Subset            | Classes                              | `type_s`                |   Body   | Mapper                     |
| ----------------- | ------------------------------------ | ----------------------- | :------: | -------------------------- |
| articles          | 36                                   | `article`               |   OCR    | `Mapper\ArticleMapper`     |
| publications      | 60                                   | `publication`           | OCR + ToC | `Mapper\PublicationMapper` |
| documents         | 49                                   | `document`              |   OCR    | `Mapper\DocumentMapper`    |
| audiovisual       | 38                                   | `audiovisual`           | description | `Mapper\AudiovisualMapper` |
| photographs       | 58 (≙ resource template 15)          | `photograph`            | description | `Mapper\PhotographMapper`  |
| references        | 35, 43, 88, 40, 82, 178, 77, 52, 305 | `reference`             | abstract | `Mapper\ReferenceMapper`   |
| entity collection | 94, 9, 96, 54, 244                   | (separate `iwac_index`) |    —     | `Mapper\IndexEntityMapper` |

The entity collection (`iwac_index`) carries occurrence metrics — `frequency`,
`first_year` / `last_year`, `country_ss` — accumulated by `EntityOccurrences`
during the content pass (a reverse scan of the public content that references
each entity), so it needs no second database pass.

Adding a content subset = drop a `MyMapper extends AbstractMapper` declaring
its `classIds()` + `readTerms()`, and register it in
`MapperRegistry::default()` (`src/Indexer/Mapper/MapperRegistry.php`) — the
ONE registration point both the bulk pipeline (`ReindexOrchestrator`) and
the incremental pipeline (`IncrementalIndexerFactory`) construct from.
Never register mappers in the entry points (`cli/reindex.php`,
`Job\BulkReindex`) — they are thin wrappers. The reindexer iterates
`MapperRegistry::subsets()`, so the orchestrator needs no edit either.

## Connection

- **CLI** (`cli/reindex.php`) — builds the DBAL connection from Omeka's
  `config/database.ini` (it runs outside the HTTP bootstrap).
- **Job** (`Job\BulkReindex`, the admin Reindex button) — pulls
  `Omeka\Connection` from the service container.
