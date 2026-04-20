# IwacSearch

Omeka S module that owns the public discovery experience for the
[Islam West Africa Collection](https://islam.zmo.de/), backed by
[Typesense](https://typesense.org/).

Replaces `AdvancedSearch` + `SearchSolr` + `FacetedBrowse` on the public
side. Admin search, item detail pages, ingest, and IIIF tile serving stay
on Omeka.

## Status

**M0 — Foundation.** Module skeleton + Typesense schema. No working UI yet.
See the roadmap in the companion repo:
[IWAC-docker/docs/iwac-search-roadmap.md](https://github.com/fmadore/IWAC-docker/blob/main/docs/iwac-search-roadmap.md).

## Companion stack

This module assumes a running Typesense container reachable at
`typesense:8108` on the `omeka-backend` Docker network, plus an nginx
`/search-api/` reverse proxy. Both are provided by
[IWAC-docker](https://github.com/fmadore/IWAC-docker) (private repo,
commit `796911b` or later).

Visual styling consumes design tokens from
[IWAC-theme](https://github.com/fmadore/IWAC-theme) — the module's CSS
uses `--space-*`, `--primary`, `--surface-*`, `--text-*`, `--radius-*`
custom properties from the theme's
`asset/sass/abstracts/variables/_tokens.scss`. Use the module under any
theme; the var() fallbacks cover the case where IWAC-theme isn't active.

The Typesense admin API key is mounted as `/run/secrets/typesense_api_key`.
The module never reads it from the database or the browser.

## Layout

```
IwacSearch/
├── Module.php                                  # Lifecycle + (M4) event listeners
├── config/
│   ├── module.ini                              # Omeka module manifest
│   └── module.config.php                       # Routes, controllers, blocks, services
├── src/
│   ├── Controller/SearchController.php
│   ├── Indexer/                                # Bulk reindex pipeline
│   │   ├── SchemaLoader.php                    #   reads data/schema.yaml
│   │   ├── HfDatasetLoader.php                 #   streams from HF Datasets Server API
│   │   ├── AuthorityResolver.php               #   joins `index` subset → entity buckets
│   │   ├── DocumentMapper.php                  #   HF row → Typesense doc
│   │   └── Reindexer.php                       #   orchestrates with atomic alias swap
│   ├── Site/BlockLayout/IwacSearchBlock.php    # Page block (drop into any Site page)
│   └── Service/
│       ├── SearchControllerFactory.php
│       └── TypesenseClientFactory.php          # Reads /run/secrets/typesense_api_key
├── cli/
│   └── reindex.php                             # `discovery:reindex` entry point
├── data/
│   ├── schema.yaml                             # Typesense collection (source of truth)
│   └── stopwords-fr.json                       # French stopword set
├── view/
│   ├── iwac-search/search/{index,browse}.phtml
│   └── common/block-layout/iwac-search-block.phtml
├── asset/
│   ├── css/iwac-search.css                     # Consumes IWAC-theme tokens
│   └── js/iwac-search.js                       # Svelte bundle (M0 placeholder)
└── docs/
    └── data-sources.md                         # Why we pull bulk from HF, live from Omeka
```

### Architectural note

The `src/Indexer/` triad (loader → mapper → reindexer) and the future
`src/Querier/` directory mirror Daniel-KM's
[AdvancedSearch module](https://github.com/Daniel-KM/Omeka-S-module-AdvancedSearch),
the dominant Omeka search-module convention. We're single-backend
(Typesense), so the EngineAdapter abstraction is implicit — but the
naming stays consistent so editors who know AdvancedSearch can navigate
this codebase without surprise.

## Running the bulk reindex

```bash
# Inside the Omeka php container (after the module is installed and
# composer deps are available)
docker compose exec php php /var/www/html/modules/IwacSearch/cli/reindex.php
```

Builds a versioned collection (`iwac_v1_<UTC timestamp>`), streams content
from the HuggingFace dataset, batch-imports into Typesense, then atomic-
swaps the `iwac_current` alias. Live search keeps serving the previous
collection uninterrupted until the swap completes — a failed reindex
never affects production.

## Roadmap snapshot

| Milestone | Lands |
|---|---|
| M0 | Module skeleton, schema.yaml, Typesense client wired, batch reindex CLI populating `iwac_v1` |
| M1 | `/search` serves Svelte bundle; scoped-key endpoint live |
| M2 | Facets, URL state, snippets, sort, French stopwords applied |
| M3 | Curated browse pages (`/browse/{slug}`) — replaces FacetedBrowse |
| M4 | Live `api.{create,update,delete}.post` listeners; role-aware scoped keys |
| M5 | Polish — typeahead, voice search (Web Speech API), accessibility, French analyser verification |
| M6 | Cut over: theme search box → `/search`, Solr container retired |

Full text in the IWAC-docker roadmap link above.

## License

GPL-3.0-or-later. The IWAC corpus itself is CC-BY-NC-SA-4.0.
