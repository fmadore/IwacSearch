# Search behaviour

How queries are interpreted and results ordered — the reference for the
index-time and query-time decisions behind the public surfaces.

## Default facets

Standalone `/search` and freshly-dropped page blocks ship with this
facet set, ordered coarse → fine:

| Field                                  | What it filters                                                          |
| -------------------------------------- | ------------------------------------------------------------------------ |
| `type_s`                               | Article / Publication / Document / Audiovisual / Photograph / Reference  |
| `has_fulltext`                         | Full text (bibo:content) exists AND is publicly readable                 |
| `country_ss`                           | Country (Bénin, Burkina Faso, Côte d'Ivoire, Niger, Togo, Nigeria)       |
| `newspaper_ss`                         | Publisher (newspaper / magazine title)                                   |
| `places_ss`                            | Mentioned locations                                                      |
| `persons_ss`                           | Mentioned persons                                                        |
| `organisations_ss`                     | Mentioned organisations                                                  |
| `topics_ss`                            | Subjects (controlled vocabulary — `fabio:AuthorityFile` authority items) |
| `gemini_3_flash_preview_polarite_ss`   | Sentiment polarity                                                       |
| `gemini_3_flash_preview_centralite_ss` | Centrality of Islam/Muslims                                              |
| `gemini_3_flash_preview_subjectivite`  | Subjectivity, 1–5                                                        |

The canonical list is `SearchDefaults::CONTENT_PROMINENT_FACETS` — the
standalone route, the federated Content tab, and the page-block default
all read it, so they cannot drift.

Plus a dedicated `pub_year` two-handle range slider (1960..2025 default
bounds) — kept separate from the categorical list because numeric range
semantics don't fit the checkbox UI.

Block admins can override the visible facets per-instance via the page
block form. The full catalog of facetable fields lives in
`FacetCatalog::FACETABLE_FIELDS` (`src/Browse/FacetCatalog.php`) —
`scripts/check-schema-drift.js` fails CI if it disagrees with the schema
YAMLs or the client's facet labels, so the count there is always current.

## URL state

Standalone `/search` syncs every observable to the URL so any search
view is shareable / bookmarkable / back-button-able:

```
/search?q=ramadan
       &page=2
       &sort=date:desc
       &f.country_ss=Burkina+Faso
       &f.country_ss=Niger
       &f.newspaper_ss=Sidwaya
       &date.from=1990
       &date.to=2010
```

Defaults are omitted (clean URL on a fresh `/search`). Pagination uses
`replaceState` to avoid history spam; everything else uses `pushState`.
Page blocks intentionally skip URL sync — multiple block instances on
one page would clobber each other.

## Semantic search

Every search is hybrid: the `query_by` includes the `embedding` field
alongside `title_txt`, `ocr_text`, and `entity_aliases_txt`. Typesense
fuses keyword and vector scores automatically — typing
`"laïcité au Burkina"` matches docs about secularism in Burkina Faso
even when the exact words don't appear. No toggle needed; it just
works.

The embedding model is `ts/multilingual-e5-small` (384d, in-process
ONNX) — no external API calls at query time.

## Accent-insensitive search (v3)

The searchable text fields carry **no `locale: fr`**. With `locale: fr`
Typesense preserves diacritics (ICU tokenization) and an unaccented query
only matches through typo tolerance — measured on the live v2 index,
`Cote d'Ivoire` found 2 documents where `Côte d'Ivoire` found 397, and
there is no folding option for non-`en` locales
([typesense#2093](https://github.com/typesense/typesense/issues/2093),
[typesense#2354](https://github.com/typesense/typesense/issues/2354)).
The default pipeline folds European diacritics at both index and query
time, so `cote` = `Côte` and `evenement` = `événement` — while highlights
still display the original accented text. The `fr_default` stopword set is
uploaded with `locale: en` for the same reason (its tokens must fold the
same way). This is an index-time change, hence the `iwac_v2` → `iwac_v3`
collection bump.

## Stemming

The prose fields (`title_txt`, `alt_title_txt`, `ocr_text`, `abstract`)
carry `stem: true`, folding word-forms of the same root: a query for
`musulman` also matches `musulmans` / `musulmane`, `islamique` matches
`islamiques`, and so on. (Since v3 the stemmer is the default Snowball
English one — locale-free fields are the price of accent folding — which
still handles these plural/feminine endings; accent folding itself now
unifies the `événement`/`évènement`-style variants the French stemmer
never covered.) Stemming is applied to the keyword inverted index only —
the embedding model still receives the raw field text, so semantic recall
is unaffected. `entity_aliases_txt` is deliberately **not** stemmed (it
holds proper nouns and acronyms a stemmer would mangle).

## Result diversification (Typesense 30.2 MMR)

The standalone `/search` diversifies results on a text query using
Maximum Marginal Relevance: near-duplicate syndicated articles (the same
wire story reprinted across outlets and dates) get pushed apart so one
story can't fill the first page. It's driven by the `iwac_diversity`
global curation set — a tag-only rule whose `vector_distance` similarity
metric runs over the same `embedding` field semantic search uses —
created idempotently during the bulk reindex (`CurationSync`) and linked
to the collection via `curation_sets` in `schema.yaml`. The client
activates it per-search with `curation_tags: diversify` + a
`diversity_lambda` of 0.7 (mostly relevance, gentle dedup), and only when
a query is present — browse mode (`q=*`) stays in strict date order.
Curated `/browse/{slug}` pages and page blocks omit the tag, so they keep
raw relevance order.

## Search scopes (page-block presets)

A page block's **Scope** dropdown picks a ready-made scope from
`src/Search/PresetCatalog.php` — the whole corpus, one country, the
references subset, or the entity index — or **Custom…** for a raw
content-collection `filter_by`. Each preset drives the collection, the
locked filter (baked into the scoped key, so enforced server-side), the
facet set, and the default sort. Compose discovery pages by dropping a
block onto any Omeka page alongside your own text/HTML blocks.

| Scope        | Collection           | Locked filter                                   |
| ------------ | -------------------- | ----------------------------------------------- |
| All content  | `iwac_current`       | — (`is_public:=true` only)                      |
| Bénin … Togo | `iwac_current`       | ``country_ss:=`Bénin` && type_s:!=reference`` … |
| References   | `iwac_current`       | `type_s:=reference`                             |
| Entity index | `iwac_index_current` | — (entity cards, frequency)                     |

Country scopes exclude references since v3.2: a country page surfaces the
primary sources; the bibliography lives in its own References scope.

The earlier `iwac_browse_config` table + admin CRUD UI were retired. Old
`/browse/{slug}` links still work: `SearchController::browseAction`
302-redirects them to the equivalent `/search?f.…` (or, for the index,
`/search/everything?tab=entities`). The `upgrade()` hook drops the table.
