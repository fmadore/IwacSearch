# IwacSearch

Omeka S module that owns the public discovery experience for the
[Islam West Africa Collection](https://islam.zmo.de/), backed by
[Typesense](https://typesense.org/).

Replaces `AdvancedSearch` + `SearchSolr` + `FacetedBrowse` on the public
side. Admin search, item detail pages, ingest, and IIIF tile serving stay
on Omeka.

## Status

Every public discovery surface — `/search`, the federated
`/search/everything`, and page blocks — is **server-rendered**. PHP calls
Typesense during dispatch and inlines the first page plus facet counts into
the bootstrap JSON, so results paint on first frame with no fetch
roundtrip; if Typesense is unreachable the SSR returns null and the client
falls back to its scoped-key flow.

The admin surface is the **maintenance page**
(`/admin/iwac-search/maintenance`): Typesense status, bulk-reindex and
stopwords / synonyms / analytics sync buttons (each an Omeka background
job), and the search-analytics digest. Editors and admins reach it via ACL
rules in `Module::onBootstrap`.

| Milestone  |   Status   | Highlights                                                                         |
| ---------- | :--------: | ---------------------------------------------------------------------------------- |
| M0–M2      |  ✅ done   | Schema, MySQL indexer, atomic alias swap, facets, URL state, hybrid search         |
| M3 / M3.5  |  ✅ done   | Browse-config table + admin CRUD — both retired, superseded by presets and blocks  |
| Public SSR |  ✅ done   | PHP-side Typesense call inlines first page + facets into every public surface      |
| M4         |  ✅ done   | Full incremental sync: items, batch ops, media, item sets                          |
| M5         |  ✅ done   | Typeahead dropdown — prefix search, keyboard nav, click-to-navigate                |
| M6         | 🟡 partial | Mobile filter drawer, result count, empty-state polish; cutover still planned      |
| 3.6–3.9    |  ✅ done   | Synonyms, analytics, union "All" tab, Map view, ToC search, legacy-route redirects |

Per-version notes: [docs/release-history.md](docs/release-history.md) and the
[releases](https://github.com/fmadore/IwacSearch/releases). Things to verify
after a deploy: [docs/deploy-checklist.md](docs/deploy-checklist.md).

## Install

Download `IwacSearch-vX.Y.Z.zip` from the
[latest release](https://github.com/fmadore/IwacSearch/releases/latest) and
unzip it into Omeka's `modules/` directory, then activate **IwacSearch** in
_Admin → Modules_. The zip already contains `vendor/` and the compiled
client bundles, so no Composer or Node step is needed on the server.

Then run a bulk reindex — the module ships no data and search stays empty
until the first collection is built.

Installing from a git checkout instead means running `composer install
--no-dev` inside the php container yourself; the source tree deliberately
does not carry `vendor/`.

## Companion stack

This module assumes a running Typesense container reachable at
`typesense:8108` on the `omeka-backend` Docker network, plus an nginx
`/search-api/` reverse proxy. Both are provided by
[IWAC-docker](https://github.com/fmadore/IWAC-docker) (private repo,
commit `796911b` or later).

Visual styling consumes design tokens from
[IWAC-theme](https://github.com/fmadore/IWAC-theme) — `--space-*`,
`--primary`, `--surface-*`, `--text-*`, `--radius-*`. The module works
under any theme; `var()` fallbacks cover the case where IWAC-theme isn't
active.

The Typesense admin API key is mounted as `/run/secrets/typesense_api_key`.
The module never reads it from the database, and never sends it to the
browser — see [SECURITY.md](SECURITY.md).

## Layout

| Path                  | What lives there                                                      |
| --------------------- | --------------------------------------------------------------------- |
| `Module.php`          | Lifecycle, asset injection, incremental-indexing event wiring         |
| `config/`             | Omeka manifest; routes, controllers, blocks, services                 |
| `src/Indexer/`        | Bulk + incremental indexing, one mapper per content subset            |
| `src/Search/`         | SSR renderer, scoped-key provider, page-block presets, query defaults |
| `src/Controller/`     | Public search routes, token endpoint, admin maintenance page          |
| `src/svelte/`         | Svelte 5 + TS client source (public bundle and header typeahead)      |
| `data/`               | Collection schemas, stopwords, synonyms, newspaper→country map        |
| `cli/`                | Reindex and sync entry points, runnable inside the php container      |
| `asset/dist/`         | Compiled bundles — committed, so production needs no Node             |
| `view/`, `asset/css/` | Templates and block CSS                                               |

Full annotated tree, including why `src/Indexer/` mirrors
[AdvancedSearch](https://github.com/Daniel-KM/Omeka-S-module-AdvancedSearch)'s
naming: [docs/code-map.md](docs/code-map.md).

## Search behaviour

Every search is hybrid — Typesense fuses keyword scoring with vectors from
an in-process `ts/multilingual-e5-small` embedding, so
`"laïcité au Burkina"` matches documents about secularism in Burkina Faso
that never use those words. Text fields carry no `locale: fr`, which buys
accent folding (`cote` finds `Côte`) at the cost of the French stemmer;
prose fields are stemmed with Snowball English, and results on a text query
are diversified with MMR so one syndicated wire story can't fill a page.

The default facet stack, URL-state contract, page-block scopes, and the
reasoning behind each of those choices: [docs/search-behaviour.md](docs/search-behaviour.md).

## Running the bulk reindex

```bash
docker compose exec php php /var/www/html/modules/IwacSearch/cli/reindex.php
```

Builds a versioned collection (`iwac_v6_<UTC timestamp>`), reads content
directly from the Omeka MySQL database, batch-imports into Typesense, then
atomic-swaps the `iwac_current` alias. Live search keeps serving the
previous collection until the swap completes, so a failed reindex never
affects production.

Right after the swap it replays every item created or edited since the build
began (`catch_up` in the stats output) — those saves went through the alias
into the outgoing collection and would otherwise be reverted. The one case
still not covered is an item DELETED mid-build after it was already
streamed; its stale document survives until the next reindex.

Same work is available from the maintenance page's reindex button and from
`omeka-cli discovery:reindex`. Why the indexer reads MySQL rather than the
Hugging Face dataset: [docs/data-sources.md](docs/data-sources.md).

## Building the Svelte clients

Two Vite builds emit two IIFE bundles: `iwac-search.{js,css}` from
`src/svelte/` for `/search`, `/search/everything` and page blocks, and
`iwac-search-header.{js,css}` from `src/svelte/header.ts` for the theme
header search box on every site page.

Both are committed to `asset/dist/` so production deployments need no Node
toolchain — CI diffs a fresh build against the committed output and fails on
drift, so rebuild and commit whenever you touch `src/svelte*`.

```bash
npm install
npm run build            # both bundles; build:public / build:header for one
npm run dev              # vite watch (bundle chosen by IWAC_BUNDLE)
npm run check            # svelte-check
npm run lint             # eslint + prettier + schema/theme drift gates
```

## Quality stack

- **PHP** 8.2+, strict types throughout, PSR-4 (`IwacSearch\` → `src/`). CI
  runs `composer validate --strict`, `php -l`, PHPUnit and PHPStan on 8.2 /
  8.4 / 8.5.
- **JS/TS** Vite 8, Svelte 5, TypeScript 6, ESLint 10 (flat config),
  Prettier 3, Vitest.
- **Drift gates** `npm run lint` fails if `FacetCatalog::FACETABLE_FIELDS`,
  the schema YAMLs and the client's facet labels disagree, or if a CSS
  custom-property fallback diverges from the theme's token snapshot.
- **Security** snippet rendering uses `{@html}` only after sanitisation that
  permits literal `<mark>` and nothing else.

## Roadmap

Deferred work and deployment prerequisites live in [ROADMAP.md](ROADMAP.md);
refactoring and hardening in [docs/engineering-roadmap.md](docs/engineering-roadmap.md).

## License

GPL-3.0-or-later — see [LICENSE](LICENSE). The IWAC corpus itself is
CC-BY-NC-SA-4.0.

To cite the software, see [CITATION.cff](CITATION.cff) or use GitHub's
_Cite this repository_ button.
