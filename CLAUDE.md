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
  The public scoped key carries `exclude_fields: ocr_text,toc_txt` AND
  `filter_by: is_public:=true`, hardcoded in
  `TypesenseSearchKeyProvider::mintPublicScopedKey()` (the single source
  of truth — deliberately NOT config-driven). Both are belt-and-suspenders
  security controls. Loosening either requires sign-off. A page block's
  `locked_filters` are NOT part of this boundary — they are cosmetic
  client-side scoping only.

- **Admin API key never reaches the browser.** It's read from
  `/run/secrets/typesense_api_key` by `TypesenseClientFactory` (the only
  reader). The browser only ever sees a 1h scoped key.

## Module lifecycle

- `Module.php :: install` — empty; the module owns NO database tables
  (the legacy `iwac_browse_config` table is dropped by `upgrade()` /
  `uninstall()` if present). Never touches Typesense data.
- `Module.php :: attachListeners` — injects the Svelte assets on the
  search routes + the site-wide header enhancer, and wires the
  `api.*.post` incremental-indexing events. The indexer listener is
  resolved lazily at event fire time — do not resolve it eagerly, or
  every anonymous GET pays for the full indexer graph.
- `SearchControllerFactory` — injects the scoped-key provider, the SSR
  renderer and module config into the controller (deliberately NOT the
  Typesense client itself).

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
  the incremental pipelines construct from.
- `npm run lint` includes `scripts/check-schema-drift.js`, which fails CI
  when `FacetCatalog::FACETABLE_FIELDS`, the schema YAMLs, and the i18n
  `FACET_LABELS` disagree. If you add a facet, all four must move together.

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

## Linked repos

- [IWAC-docker](https://github.com/fmadore/IWAC-docker) — Typesense + nginx + backup (private)
- [IWAC-theme](https://github.com/fmadore/IWAC-theme) — Omeka S theme (defines the design tokens this module consumes)
- [IWAC-Hugging-Face](https://github.com/fmadore/IWAC-Hugging-Face) — Omeka → HF pipeline
- [IwacVisualizations](https://github.com/fmadore/IwacVisualizations) — Omeka analytics module
