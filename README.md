# IwacSearch

Omeka S module that owns the public discovery experience for the
[Islam West Africa Collection](https://islam.zmo.de/), backed by
[Typesense](https://typesense.org/).

Replaces `AdvancedSearch` + `SearchSolr` + `FacetedBrowse` on the public
side. Admin search, item detail pages, ingest, and IIIF tile serving stay
on Omeka.

## Status

**M3.5 — Admin CRUD for curated browse pages.** Second Svelte bundle (`iwac-search-admin.{js,css}`, 20.5 KB gzipped) mounted under `/admin/iwac-search/browse-config`, with a JSON API under `…/api/…` (GET list · POST create · GET/PATCH/DELETE item). The initial config list is inlined server-side from MySQL via Doctrine DBAL and baked into the page's bootstrap JSON — zero fetches on mount, paints like FacetedBrowse. Mutations are optimistic (row appears / updates / disappears immediately) with rollback on server error. Editors + site-admins + global-admins all have access via ACL allow-rules in `Module::onBootstrap`.

| Milestone | Status  | Highlights                                                                        |
| --------- | :-----: | --------------------------------------------------------------------------------- |
| M0        | ✅ done | Schema, indexer pipeline (4 mappers + ACL overlay + stopwords), atomic alias swap |
| M1        | ✅ done | `/search`, `/discovery/token`, page block, Svelte 5 client, alias-spelling search |
| M2        | ✅ done | Facet panel, year range slider, URL state, sort, hybrid keyword+vector search     |
| M3        | ✅ done | `iwac_browse_config` table + 6 auto-seeded country pages + `/browse` landing      |
| M3.5      | ✅ done | Admin CRUD UI (Svelte, optimistic, SSR-inlined initial state, JSON API)           |
| M4 → M6   | planned | Incremental indexing, polish, cutover                                             |

Full roadmap: [IWAC-docker/docs/iwac-search-roadmap.md](https://github.com/fmadore/IWAC-docker/blob/main/docs/iwac-search-roadmap.md).

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
├── Module.php                                  # Lifecycle + asset injection (M4: event listeners)
├── config/
│   ├── module.ini                              # Omeka module manifest
│   └── module.config.php                       # Routes, controllers, blocks, services
├── src/
│   ├── Controller/
│   │   ├── SearchController.php                # /search, /discovery/token, /browse[/:slug]
│   │   └── Admin/BrowseConfigController.php    # /admin/iwac-search/browse-config[/api[/:id]]
│   ├── Indexer/                                # Bulk reindex pipeline
│   │   ├── SchemaLoader.php                    #   reads data/schema.yaml
│   │   ├── HfDatasetLoader.php                 #   streams HF Datasets Server API
│   │   ├── AuthorityResolver.php               #   joins `index` subset → entity buckets
│   │   ├── OmekaAclLoader.php                  #   is_public overlay (anonymous /api/items)
│   │   ├── StopwordsSync.php                   #   PUTs fr_default to Typesense
│   │   ├── Mapper/                             #   one mapper per HF subset
│   │   │   ├── MapperInterface.php
│   │   │   ├── AbstractMapper.php
│   │   │   ├── ArticleMapper.php
│   │   │   ├── PublicationMapper.php
│   │   │   ├── DocumentMapper.php
│   │   │   ├── AudiovisualMapper.php
│   │   │   └── MapperRegistry.php
│   │   └── Reindexer.php                       #   orchestrates with atomic alias swap
│   ├── Browse/                                 # Curated /browse/{slug} surfaces
│   │   ├── BrowseConfig.php                    #   read-only DTO
│   │   ├── BrowseConfigRepository.php          #   DBAL CRUD against iwac_browse_config
│   │   ├── CountrySeeder.php                   #   seeds 6 country pages on install
│   │   └── FacetCatalog.php                    #   shared facet/sort/mode constants
│   ├── Site/BlockLayout/IwacSearchBlock.php    # Page block — drop into any Site page
│   ├── Log/OmekaPsrLogger.php                  # PSR-3 ↔ Laminas\Log adapter (psr/log 3.x-safe)
│   ├── svelte/                                 # Svelte 5 + TS client source — public bundle
│   │   ├── App.svelte                          #   per-mount root, owns search state
│   │   ├── components/
│   │   │   ├── SearchInput.svelte              #   debounced text input
│   │   │   ├── FacetPanel.svelte               #   sticky left column + active-filter chips
│   │   │   ├── FacetGroup.svelte               #   one collapsible facet (checkboxes + show-more)
│   │   │   ├── DateRangeSlider.svelte          #   two-handle year range
│   │   │   ├── SortSelect.svelte               #   relevance | newest | oldest
│   │   │   ├── ResultsList.svelte              #   paginated load-more
│   │   │   └── ResultItem.svelte               #   title + date + snippet + thumbnail
│   │   ├── lib/
│   │   │   ├── typesense.ts                    #   thin REST wrapper, scoped-key cache
│   │   │   ├── types.ts                        #   IwacBootstrap, SearchState, etc.
│   │   │   ├── urlState.ts                     #   bidirectional URL ↔ memory sync
│   │   │   └── labels.ts                       #   schema field → display label
│   │   └── main.ts                             #   IIFE entry; auto-mounts on every root
│   ├── svelte-admin/                           # Svelte 5 + TS client source — admin bundle
│   │   ├── App.svelte                          #   list + drawer + error banner
│   │   ├── components/
│   │   │   ├── ConfigTable.svelte              #   rows + inline delete-confirm
│   │   │   ├── ConfigFormDrawer.svelte         #   slide-in create/edit panel
│   │   │   └── FacetPicker.svelte              #   reorderable checkbox grid
│   │   ├── lib/
│   │   │   ├── api.ts                          #   JSON CRUD client + error envelope
│   │   │   ├── store.svelte.ts                 #   optimistic state (Svelte 5 runes)
│   │   │   └── types.ts                        #   BrowseConfig, ApiError, Bootstrap
│   │   └── main.ts                             #   IIFE entry; mounts on [data-iwac-admin-root]
│   └── Service/
│       ├── SearchControllerFactory.php
│       ├── TypesenseClientFactory.php          # Admin client (reads Docker secret)
│       ├── TypesenseSearchKeyProvider.php      # Mints scoped keys for the browser
│       └── Controller/
│           └── BrowseConfigControllerFactory.php
├── cli/
│   └── reindex.php                             # `discovery:reindex` entry point
├── data/
│   ├── schema.yaml                             # Typesense collection (source of truth, 38 fields)
│   └── stopwords-fr.json                       # French stopword set (loaded as fr_default)
├── view/
│   ├── iwac-search/search/{index,browse,browse-list}.phtml
│   ├── iwac-search/admin/browse-config/browse.phtml   # Admin CRUD shell (M3.5)
│   └── common/block-layout/iwac-search-block.phtml
├── asset/
│   ├── css/iwac-search.css                     # Block container + skeleton (consumes IWAC-theme tokens)
│   └── dist/                                   # Compiled Svelte bundles (committed; CI rebuilds on PR)
│       ├── iwac-search.js                      #   public client, ~22 KB gzipped IIFE
│       ├── iwac-search.css                     #   public component styles
│       ├── iwac-search-admin.js                #   admin CRUD client, ~20 KB gzipped IIFE
│       └── iwac-search-admin.css               #   admin component styles
├── .github/
│   ├── dependabot.yml                          # weekly grouped updates: npm + composer + actions
│   └── workflows/ci.yml                        # lint + svelte-check + build + PHP syntax
├── docs/
│   └── data-sources.md                         # Why we pull bulk from HF, live from Omeka
├── package.json                                # Vite 8 + Svelte 5 + TypeScript 6 toolchain
├── vite.config.ts
├── tsconfig.json
├── eslint.config.js                            # flat config (ESLint 10)
└── .prettierrc.json
```

### Architectural note

The `src/Indexer/` triad (loader → mapper → reindexer) and the future
`src/Querier/` directory mirror Daniel-KM's
[AdvancedSearch module](https://github.com/Daniel-KM/Omeka-S-module-AdvancedSearch),
the dominant Omeka search-module convention. We're single-backend
(Typesense), so the EngineAdapter abstraction is implicit — but the
naming stays consistent so editors who know AdvancedSearch can navigate
this codebase without surprise.

### Default facets

Standalone `/search` and freshly-dropped page blocks ship with this
facet set, ordered coarse → fine:

| Field                | What it filters                                                                                       |
| -------------------- | ----------------------------------------------------------------------------------------------------- |
| `type_s`             | Article / Publication / Document / Audiovisual                                                        |
| `country_ss`         | Country (Bénin, Burkina Faso, Côte d'Ivoire, Niger, Togo, Nigeria)                                    |
| `newspaper_ss`       | Publisher (newspaper / magazine title)                                                                |
| `places_ss`          | Mentioned locations                                                                                   |
| `persons_ss`         | Mentioned persons                                                                                     |
| `organisations_ss`   | Mentioned organisations                                                                               |
| `topics_ss`          | Subjects (controlled vocabulary from the `index` HF subset)                                           |
| `gemini_polarite_ss` | Sentiment polarity (Gemini model — ChatGPT/Mistral are alternates available via the block admin form) |

Plus a dedicated `pub_year` two-handle range slider (1960..2025 default
bounds) — kept separate from the categorical list because numeric range
semantics don't fit the checkbox UI.

Block admins can override the visible facets per-instance via the page
block form (12 facetable fields are exposed in total — see
`src/Site/BlockLayout/IwacSearchBlock.php`).

### URL state

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

### Semantic search

Every search is hybrid: the `query_by` includes the `embedding` field
alongside `title_txt`, `ocr_text`, and `entity_aliases_txt`. Typesense
fuses keyword and vector scores automatically — typing
`"laïcité au Burkina"` matches docs about secularism in Burkina Faso
even when the exact words don't appear. No toggle needed; it just
works.

The embedding model is `ts/multilingual-e5-small` (384d, in-process
ONNX) — no external API calls at query time.

### Curated browse pages

Each row in the module-owned `iwac_browse_config` table renders as a
public surface at `/browse/{slug}`. The Svelte client mounts with the
row's `locked_filters` baked into the bootstrap — those filters become
part of the scoped key the server mints, so they're enforced
server-side and can't be removed by client tampering.

Six country pages are auto-seeded on install (`CountrySeeder.php`):

| Slug           | URL                    | Locked filter                     |
| -------------- | ---------------------- | --------------------------------- |
| `benin`        | `/browse/benin`        | `` country_ss:=`Bénin` ``         |
| `burkina-faso` | `/browse/burkina-faso` | `` country_ss:=`Burkina Faso` ``  |
| `cote-divoire` | `/browse/cote-divoire` | `` country_ss:=`Côte d'Ivoire` `` |
| `niger`        | `/browse/niger`        | `` country_ss:=`Niger` ``         |
| `nigeria`      | `/browse/nigeria`      | `` country_ss:=`Nigeria` ``       |
| `togo`         | `/browse/togo`         | `` country_ss:=`Togo` ``          |

The seeder is idempotent — `existsBySlug()` skips configs that already
exist, so a future re-install never clobbers admin edits. Add a new
country = either add one row to `CountrySeeder::COUNTRIES` and reinstall,
or use the M3.5 admin UI at `/admin/iwac-search/browse-config` to create
one from scratch.

### Admin CRUD (M3.5)

Authenticated editors / site admins / global admins land at
`/admin/iwac-search/browse-config` (sidebar entry: **IWAC Search**) and
see a table of every curated surface. The page is rendered with the
full config list **already inlined** in its bootstrap JSON — one PHP
round-trip to `iwac_browse_config`, zero browser fetches on mount. The
admin Svelte app paints its table on first frame.

Everything interactive — creating a new surface, editing title / intro /
locked filter / facet picks, reordering facets with ↑↓ buttons, deleting
a config — runs through the JSON API:

```
GET    /admin/iwac-search/browse-config/api         → list
POST   /admin/iwac-search/browse-config/api         → create
GET    /admin/iwac-search/browse-config/api/{id}    → one
PATCH  /admin/iwac-search/browse-config/api/{id}    → update
DELETE /admin/iwac-search/browse-config/api/{id}    → delete
```

Writes require a matching `X-CSRF-Token` header (minted server-side per
session and echoed in the bootstrap). The admin session cookie is the
primary authentication gate — the ACL rules in `Module::onBootstrap`
decide which roles pass.

Mutations are **optimistic**: the UI updates locally before the server
confirms, and rolls back with a visible error banner if the request
fails. Creates show a pulsing "provisional" row until the server
returns the canonical id. Delete uses inline confirmation (`Delete →
Confirm?`) rather than a modal — two clicks, zero popups.

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

## Building the Svelte clients

Two Vite builds emit two IIFE bundles side by side:

| Bundle                         | Source              | Mounted on                                          |
| ------------------------------ | ------------------- | --------------------------------------------------- |
| `iwac-search.{js,css}`         | `src/svelte/`       | `/search`, `/browse/{slug}`, page blocks            |
| `iwac-search-admin.{js,css}`   | `src/svelte-admin/` | `/admin/iwac-search/browse-config`                  |

Both compiled bundles (`asset/dist/*`) are committed so production
deployments work with no Node toolchain. To rebuild after changing
Svelte source:

```bash
npm install              # one-time: ~100 packages, ~6 s
npm run build            # builds both bundles sequentially
npm run build:public     # only the public discovery bundle
npm run build:admin      # only the admin CRUD bundle
npm run dev              # vite watch mode (whichever bundle IWAC_BUNDLE names)
npm run check            # svelte-check (TypeScript + Svelte 5 reactivity)
npm run lint             # eslint + prettier --check on both trees
npm run lint:fix         # autofix lint + format issues
```

CI runs `lint`, `check`, and `build` on every PR — see `.github/workflows/ci.yml`. Dependabot opens grouped weekly PRs for npm + composer + GitHub Actions updates (config in `.github/dependabot.yml`).

## Quality stack

- **PHP**: 8.2+, strict types on every file, PSR-4 (`IwacSearch\` → `src/`), CI runs `composer validate --strict` + `php -l`.
- **JS/TS**: Vite 8 + Svelte 5 + TypeScript 6 + ESLint 10 (flat config, `typescript-eslint` + `eslint-plugin-svelte`) + Prettier 3 (`prettier-plugin-svelte`).
- **Security**: snippet rendering uses `{@html}` only after client-side sanitisation that allows literal `<mark>` tags only — see `src/svelte/components/ResultItem.svelte`.

## Roadmap snapshot

See the status table at the top. Full milestone text in the IWAC-docker roadmap link above.

## License

GPL-3.0-or-later. The IWAC corpus itself is CC-BY-NC-SA-4.0.
