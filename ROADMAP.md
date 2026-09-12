# IwacSearch — product and infrastructure follow-ups

The 3.19.0 engineering implementation is complete. Use the [operations guide](docs/operations-3.19.md) and [deployment checklist](docs/deploy-checklist.md) for rollout; deployment has not been performed as part of this release. Measurement-dependent engineering work lives in [docs/engineering-roadmap.md](docs/engineering-roadmap.md).

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
- **typesense-php filter-escape helper** — v6.1.0 (RC as of mid-2026) ships
  an official `escape filter string values` helper. The module hand-builds
  `filter_by` strings in PHP and TS today (values are backtick-wrapped
  client-side); adopt the helper once 6.1.0 goes stable.
- **Header typeahead recent-searches** — the in-app dropdown shows
  localStorage history on empty focus; the site-wide header enhancer
  deliberately doesn't (it shares the storage key, so wiring it up is
  small if wanted).

## Watchlist

- **Typesense server releases** — integration-tested on v30.2 for 3.19.0. Union search
  responses currently carry no per-hit source marker and no facet_counts;
  if a later release adds them, the federated "All" tab can gain facets.
- **MapLibre pin** — `5.24.0` from jsDelivr, deliberately the SAME exact
  pin as IwacVisualizations so the browser cache is shared. Bump the two
  repos together (`src/svelte/lib/maplibreLoader.ts` here).
