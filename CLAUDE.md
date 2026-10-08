# CLAUDE.md

Guidance for Claude Code working in this repository.

## What this is

Omeka S module that wires the public IWAC archive search to Typesense.
Companion to [IWAC-docker](https://github.com/fmadore/IWAC-docker), which
provides the Typesense container, nginx `/search-api/` proxy, and backups.

## Architecture invariants

- **Schema is the contract.** `data/schema.yaml` defines the content
  collection (and `data/schema-index.yaml` the entity collection).
  Everything downstream (indexer, scoped-key params, Svelte facet panel,
  presets) derives from them. Edit with care; schema changes force a
  version bump (`iwac_vN` → `iwac_vN+1`) and an alias swap on the next
  reindex.

- **Single source: the Omeka S MySQL database.** The indexer reads content,
  entities, sentiment, OCR (`bibo:content`), and `is_public` directly from
  Omeka via Doctrine DBAL (`OmekaSourceReader`). The Hugging Face dataset is no
  longer a search source — it remains a separately-published research artifact.
  `country_ss` is derived (newspaper→country / item-set), and `lda_topic_label`
  was dropped (HF-only). See `docs/data-sources.md` for the full rationale and
  the field→property map.

- **OCR privacy is enforced by scoped keys, not frontend discipline.**
  The public scoped key carries `exclude_fields: ocr_text,toc_txt,embedding` AND
  `filter_by: is_public:=true`, hardcoded in
  `PublicSearchPolicy` and `TypesenseSearchKeyProvider::mintPublicScopedKey()` (the shared sources
  of truth — deliberately NOT config-driven). Both are belt-and-suspenders
  security controls. Loosening either requires sign-off. The key also embeds
  `limit_multi_searches` (`MAX_MULTI_SEARCHES`); the client mirrors it in
  `transport.ts` and the drift check keeps the two equal — raise both if a
  request needs more. A page block's
  `locked_filters` are NOT part of this boundary — they are cosmetic
  client-side scoping only.

- **Admin API key never reaches the browser.** It's read from
  `/run/secrets/typesense_api_key` by `TypesenseClientFactory` (the only
  reader). The browser only ever sees a 1h scoped key.

## Module lifecycle

- `Module.php :: install` / `upgrade` — create the durable ID journal `iwac_search_change`. Uninstall drops it and the retired browse table, never Typesense collections.
- `Module.php :: attachListeners` — injects the Svelte assets on the
  search routes + the site-wide header enhancer, and wires the
  `api.execute.pre/post` change-journaling events. Background jobs perform indexing; pre/post hold the mutation gate around writes, tracked per request object in `Indexer\WriteGate` because Omeka skips the post event when a write throws. The indexer listener is
  resolved lazily at event fire time — do not resolve it eagerly, or
  every anonymous GET pays for the full indexer graph.
- Alias names are `IwacInstance::CONTENT_ALIAS` / `INDEX_ALIAS` everywhere
  (web, rebuild, drain, analytics, key scope) — not configuration.
- `SearchControllerFactory` — injects the scoped-key provider, the SSR
  renderer and module config into the controller (deliberately NOT the
  Typesense client itself).

The cutover, retention and retry contracts are documented in `docs/operations-3.19.md` and, for what 3.20 changed (phased cutover, write gate, batched drain, retention), `docs/operations-3.20.md`. Run the real integration suite when editing these boundaries.

## Conventions

- PHP 8.2+, strict types on every file.
- PSR-4 autoloading under `IwacSearch\` namespace, `src/` root.
- Composer deps live in `composer.json`; install with
  `composer install --no-dev` inside the php container after copying the
  module to the `omeka_files` volume.
- French stopwords live in `data/stopwords-fr.json` and are PUT into
  Typesense as the `fr_default` set during the bulk reindex CLI.
- Synonyms (Arabic-transliteration variants) live in `data/synonyms-fr.json`
  and are PUT as the global `iwac_synonyms` set (linked via `synonym_sets`
  in `data/schema.yaml`). Search-time expansion — edits go live via
  `cli/synonyms-sync.php` or the admin button, no reindex.
- All bulk-reindex wiring lives in `src/Indexer/ReindexOrchestrator.php` —
  `cli/reindex.php` and `Job\BulkReindex` are thin entry points around it.
  Add new sync steps THERE, never in the entry points. New content mappers
  register in `MapperRegistry::default()` — the one list both the bulk and
  the incremental pipelines construct from. The incremental graph is built
  only by `IncrementalIndexer::create()` (job factory, CLI drain, cutover).
- `npm run lint` includes `scripts/check-schema-drift.js`, which fails CI
  when `FacetCatalog::FACETABLE_FIELDS`, the schema YAMLs, and the i18n
  `FACET_LABELS` disagree. If you add a facet, all four must move together.
  The same script also pins the server contracts the client restates:
  query_by/highlight constants, sort sets, the multi-search limit and the
  stopword set name.
- `npm run lint:docblocks` fails on two stacked PHP docblocks (only the
  last attaches to the symbol) — the drift that 3.20's review cleaned up.
- **Every locale table in `i18n.ts` must carry the same keys in `fr` and
  `en`** — `npm run lint:i18n` (`scripts/check-i18n.js`) enforces it. The
  tables are typed `Record<Locale, Record<string, string>>`, and that inner
  `string` means TypeScript never checks key parity; `translate()` then
  resolves `table[key] ?? STRINGS.fr[key] ?? key`, so a key missing from `en`
  serves the **French** string to English visitors rather than throwing.
  Tables are discovered from the type annotation and locales from the
  `Locale` union, so neither is a list to maintain. Mark a table
  `Partial<Record<Locale, …>>` to declare it deliberately locale-specific
  (`COUNTRY_LABELS`); that is the only exemption, and it is printed on every
  run so it stays visible.

## Versions, audit and releases

- **The version lives in five places** — `config/module.ini`, `package.json`,
  `package-lock.json` (twice: root and `packages[""]`) and `CITATION.cff`. The
  list is data in `scripts/lib/versions.js`; `npm run bump -- <patch|minor|major|X.Y.Z>`
  is its only writer (it hands the two npm files to `npm version`, then writes the
  rest and stamps `date-released`), and `npm run check:versions` is its guard, run
  on every push by `ci.yml` and against the tag by `release.yml`. A sixth site is
  one entry in that file. Never hand-edit the version.
- **`npm run check:audit`** fails on any high/critical advisory not written down,
  with its reason, in `scripts/lib/audit-exceptions.js` — and on an exception that
  is no longer needed. Ported from the theme; don't weaken it with `--omit=dev`
  (every dependency here is a dev dependency, so that turns it off).
- **A release only publishes what CI passed.** `release.yml` calls `ci.yml`
  (`workflow_call`) as its `gates` job and `publish` needs it, so the tagged tree
  runs the whole suite — svelte-check, PHPUnit and PHPStan included. The tag must
  also be an ancestor of `main`. Pushing to `main` deploys nothing; the live site
  installs the release zip.

## Adding a new field

1. Add it to `data/schema.yaml`.
2. Bump the collection name (`iwac_vN` → `iwac_vN+1`) in the schema.
3. Update the relevant mapper to populate it from its Omeka property
   (declare the term in the mapper's `readTerms()`).
4. Run `cli/reindex.php` (or `omeka-cli discovery:reindex`, or the admin
   reindex button) — the indexer builds the new collection, then
   atomic-swaps the `iwac_current` alias. The swap is guarded: an empty or
   mostly-failed import aborts and the previous collection stays live.
5. Update the Svelte client if the field is user-visible.

## Architectural references

- **Triad (EngineAdapter / Indexer / Querier).** Mirrors
  [Daniel-KM's AdvancedSearch module](https://github.com/Daniel-KM/Omeka-S-module-AdvancedSearch).
  We're single-backend (Typesense), so EngineAdapter is implicit, but
  `src/Indexer/` (and a future `src/Querier/`, if server-side querying
  ever grows beyond the SSR renderer) follows the same naming so editors
  who know AdvancedSearch can navigate this codebase.
- **AbstractBlockLayout pattern** for the page block — standard Omeka S
  4.x convention. Block data is persisted as JSON in `site_block.data`;
  multiple block instances per page are supported.

## Visual design

The visual stance and the token contract live in **one place, in the theme** —
do not restate either here:

- [`docs/DESIGN-PHILOSOPHY.md`](https://github.com/fmadore/IWAC-theme/blob/master/docs/DESIGN-PHILOSOPHY.md)
  — the register ("press archive"), what to avoid.
- [`docs/DESIGN-SYSTEM.md`](https://github.com/fmadore/IWAC-theme/blob/master/docs/DESIGN-SYSTEM.md)
  — the token contract, the fallback rule, the breakpoints.
- `tokens.json` (synced into this repo) — the machine-readable truth. When it
  and any prose disagree, it wins.

This file used to carry its own copy of the token vocabulary. It went stale in
the way copies do: it advertised `--text-*` as a "fluid type scale" when only
the three display steps (`--text-3xl/4xl/5xl`) are `clamp()`ed and every UI
step is fixed on purpose — so that a 15px facet label doesn't quietly become
15.6px between breakpoints.

### Module-specific gotchas

- `asset/css/iwac-search.css` is hand-edited — **not** produced by Vite. It is
  now inside `npm run lint:theme`'s walk; it was outside it until 2026-08,
  which is how every colour fallback in it stayed on the pre-v2.6 blue-grey
  palette while `src/` was spotless.
- **A `var()` fallback must be a flat literal.** No `var(--a, var(--b, …))`
  chains: the fallback only ever renders when the theme is absent, in which
  case the inner token is absent too — so the chain rescues nothing and
  asserts a substitution nobody meant (`var(--ink-strong, var(--ink, …))`
  claimed a headline ink degrades to body ink). `lint:theme` fails on them.
- All selectors are scoped under `.iwac-search-block` / the standalone shell —
  no global rules — so the module never collides with theme styles.
- **The token guard's rules are the theme's.** `scripts/check-theme-tokens.js`
  only names our sources and namespace (`--iwac-*`, minus `--iwac-vis-*`); the
  engine is `scripts/theme-token-guard.cjs`, written by the theme's
  `npm run sync:tokens` beside tokens.json. Never edit that copy. It refuses
  deprecated names (spacing is `--space-N` only), theme-internal parameters,
  re-declared theme tokens, unloaded font weights (Besley is 500/600/800), and
  a media width in a script (`matchMedia` strings) that is off the breakpoint
  contract. The weekly `theme-contract` workflow fails when either synced
  file falls behind the theme's master.
- **Stacking is stated against the theme's scale** — `var(--z-dropdown, 100)`
  for menus and typeaheads, `calc(var(--z-modal, 300) + n)` for the drawer —
  never a bare number that restates the theme's in a comment.
- **MapLibre is pinned to IwacVisualizations' exact files** (version, URLs and
  sha384 hashes in `src/svelte/lib/maplibreLoader.ts`), so the two modules
  share one cached copy and one `window.maplibregl`. Upgrade both together:
  `npm run check:maplibre` (weekly in `maplibre-pins.yml`) compares the pins with
  IwacVisualizations `main` and fails when this module falls behind it.
- **The header bundle loads on every page.** It may import only
  `translateSuggest()` from i18n, never `translate()`, and module-level code in
  anything it imports must not call into i18n — `npm run check:size` (the last
  step of `npm run build`) enforces a gzipped budget and refuses the app's
  string table in that bundle.

## Linked repos

- [IWAC-docker](https://github.com/fmadore/IWAC-docker) — Typesense + nginx + backup (private)
- [IWAC-theme](https://github.com/fmadore/IWAC-theme) — Omeka S theme (defines the design tokens this module consumes)
- [IWAC-Hugging-Face](https://github.com/fmadore/IWAC-Hugging-Face) — Omeka → HF pipeline
- [IwacVisualizations](https://github.com/fmadore/IwacVisualizations) — Omeka analytics module
