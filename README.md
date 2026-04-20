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
[IWAC-docker](https://github.com/fmadore/IWAC-docker) (commit `796911b` or
later).

The Typesense admin API key is mounted as `/run/secrets/typesense_api_key`.
The module never reads it from the database or the browser.

## Layout

```
IwacSearch/
├── Module.php                       # Lifecycle + (M4) event listeners
├── config/
│   ├── module.ini                   # Omeka module manifest
│   └── module.config.php            # Routes, controllers, view templates
├── src/
│   ├── Controller/SearchController.php
│   ├── Indexer/                     # Bulk reindex from HF + incremental from Omeka (M0+/M4)
│   └── Service/SearchControllerFactory.php
├── data/
│   ├── schema.yaml                  # Typesense collection definition (source of truth)
│   └── stopwords-fr.json            # French stopword set, loaded into Typesense at activation
├── view/iwac-search/search/
│   ├── index.phtml                  # /search HTML shell
│   └── browse.phtml                 # /browse[/:slug] HTML shell (M3)
├── asset/                           # Compiled Svelte bundle lands here (M1+)
└── docs/
    └── data-sources.md              # Why we pull bulk from HF, live from Omeka
```

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
