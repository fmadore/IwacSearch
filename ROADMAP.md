# IwacSearch — roadmap & deferred work

Forward-looking companion to the README's status table. Items live here
when they are deliberate deferrals (with the reasoning), need an
infrastructure decision, or block on work in another repo.

Engineering-side deferrals (tests, static analysis, refactors, request
reductions) live in [docs/engineering-roadmap.md](docs/engineering-roadmap.md).

## 3.6.0 → 3.12.0 deployment prerequisites (do these in order)

1. **Copy the module + `composer install --no-dev`** in the php container
   (unchanged from previous releases).
2. **Run a full reindex immediately** (`discovery:reindex` or the admin
   button). Both pending releases need it, and one rebuild covers both:
   - 3.6.0 bumps the ENTITY schema (`iwac_index_v2` → `iwac_index_v3`:
     `geo` geopoint + `has_coords`) and adds features the content collection
     only picks up on rebuild (the `iwac_synonyms` linkage via `synonym_sets`,
     the now-populated `item_set_ids`). Until the reindex completes, the Map
     view finds no `has_coords` field (filter error → empty map) and synonym
     expansion is inactive.
   - 3.7.0 bumps the CONTENT schema (`iwac_v3` → `iwac_v4`) for the sentiment
     field rename (vendor slot → annotating model, matching the Hugging Face
     dataset). Typesense cannot rename a field in place, so **the Sentiment
     facet group stays empty until the rebuild** — the fields exist in the
     new schema but no live collection carries them yet.
   - 3.8.0 bumps the CONTENT schema again (`iwac_v4` → `iwac_v5`) to add the
     searchable/stemmed `toc_txt` field and include publication tables of
     contents in document embeddings. The same rebuild also fills publication
     card excerpts and the new `dcterms:description` fallbacks.
   - 3.11.0 bumps it once more (`iwac_v5` → `iwac_v6`): sentiment now comes
     from the generation-2 annotators (`gpt_5_6_luna_*`, `mistral_small_2603_*`,
     `deepseek_v4_flash_0731_*`) and the generation-1 trio is dropped. Same
     consequence as 3.7.0 — **no Sentiment facets until the rebuild** — and
     since 3.7.0 was never deployed, the intermediate field names never went
     live at all. Share links and page blocks naming the old fields lose their
     sentiment filter deliberately (see docs/data-sources.md).
   - 3.12.0 bumps it again (`iwac_v6` → `iwac_v7`) for the audiovisual
     contract: `channel_ss`, `media_kind_s`, `media_platform_s`,
     `duration_seconds`, `rights_s`, plus `source_url` and transcripts on
     class 38. The trigger is upstream: IWAC-automation's YouTube ingest took
     that class from 47 deposited recordings to 1,146 records in August 2026,
     and until the rebuild those videos file their channel under "Journal".
     One rebuild covers every bump above.

   Content search itself keeps working on the old collection throughout —
   the alias only swaps on success.

3. **Optional — enable search analytics** (see below).

## Needs an IWAC-docker change: search analytics server flags

The module ships the full analytics pipeline (rules provisioning via
`AnalyticsSync`, an admin digest of top + no-hit queries, a "Provision
analytics" button), but Typesense only records analytics when started with:

```
--enable-search-analytics=true
--analytics-dir=/data/analytics      # any persistent path in the container
--analytics-flush-interval=60        # seconds; 60 is the minimum
```

Add those to the typesense service in IWAC-docker's compose file (plus a
volume for the analytics dir), restart, then click **Provision analytics**
on the maintenance page. The bulk reindex also (re)applies the rules
non-fatally, so nothing breaks while the flags are absent.

**Verify on the live container:** the rules bind to the ALIAS name
(`iwac_current`) on the assumption that Typesense matches rules against the
requested collection name (every search path here addresses the alias) —
the docs don't spell out alias resolution for analytics rules. If, after a
day of live traffic, `iwac_popular_queries` stays empty while searches
clearly flow, re-point the rules at the concrete collection name in
`AnalyticsSync` (resolve the alias at sync time) and re-provision.

## Verify after the first v3 index build: optional geopoint

The Typesense docs don't show an example of `optional: true` on a
`geopoint` field (the generic `optional` attribute documents no exclusion,
and the mapper only emits `geo` when coordinates parse). If the
`iwac_index_v3` build errors on the field definition, fall back to emitting
a `[0, 0]` sentinel plus `has_coords:=false` and filtering it out — but
expect the optional field to just work.

## Deliberately deferred

- **Natural-language search (Typesense v29)** — LLM converts free-text
  queries into `filter_by`. Excluded by decision (needs an external LLM
  key, ongoing cost, and prompt-quality curation).
- **Conversational / RAG search ("ask the archive")** — Typesense
  conversation models could answer questions over the OCR corpus with
  citations. Blocked on the same LLM-key/cost decision as above, plus a
  licensing question: answers would be synthesised from
  licensing-restricted OCR that visitors cannot read in full. Revisit
  deliberately, not as a code task.
- ~~**`ResultItem.svelte` file split (list/gallery variants)**~~ — done, but
  NOT as two components. The derivations moved to `lib/resultCard.ts`
  (pure rules: which highlight wins, how a citation punctuates, when a date
  is only a year — now unit-tested) plus `lib/resultCard.svelte.ts` (the
  reactive wiring), taking the component from ~1,000 to ~800 lines, of
  which ~500 is CSS. Splitting the LAYOUTS was examined and rejected: they
  differ by 7 CSS rules out of ~65, so two components would either
  duplicate ~450 lines of scoped CSS or push it into a global stylesheet —
  real risk to separate files that mostly agree.
- **typesense-php filter-escape helper** — v6.1.0 (RC as of mid-2026) ships
  an official `escape filter string values` helper. The module hand-builds
  `filter_by` strings in PHP and TS today (values are backtick-wrapped
  client-side); adopt the helper once 6.1.0 goes stable.
- **Header typeahead recent-searches** — the in-app dropdown shows
  localStorage history on empty focus; the site-wide header enhancer
  deliberately doesn't (it shares the storage key, so wiring it up is
  small if wanted).

## Watchlist

- **Typesense server releases** — on v30.2 as of 2026-07. Union search
  responses currently carry no per-hit source marker and no facet_counts;
  if a later release adds them, the federated "All" tab can gain facets.
- **MapLibre pin** — `5.24.0` from jsDelivr, deliberately the SAME exact
  pin as IwacVisualizations so the browser cache is shared. Bump the two
  repos together (`src/svelte/lib/maplibreLoader.ts` here).
