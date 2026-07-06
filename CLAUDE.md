# CLAUDE.md

Guidance for Claude Code working in this repository.

## What this is

Omeka S module that wires the public IWAC archive search to Typesense.
Companion to [IWAC-docker](https://github.com/fmadore/IWAC-docker), which
provides the Typesense container, nginx `/search-api/` proxy, and backups.

## Architecture invariants

- **Schema is the contract.** `data/schema.yaml` defines the Typesense
  collection. Everything downstream (indexer, scoped-key params, Svelte
  facet panel, browse configs) derives from it. Edit with care; schema
  changes force an `iwac_v1` → `iwac_v2` alias swap.

- **Single source: the Omeka S MySQL database.** The indexer reads content,
  entities, sentiment, OCR (`bibo:content`), and `is_public` directly from
  Omeka via Doctrine DBAL (`OmekaSourceReader`). The Hugging Face dataset is no
  longer a search source — it remains a separately-published research artifact.
  `country_ss` is derived (newspaper→country / item-set), and `lda_topic_label`
  was dropped (HF-only). See `docs/data-sources.md` for the full rationale and
  the field→property map.

- **OCR privacy is enforced by scoped keys, not frontend discipline.**
  The public scoped key carries `exclude_fields: ocr_text` AND
  `filter_by: is_public:=true`. Both are belt-and-suspenders security
  controls. Loosening either requires sign-off.

- **Admin API key never reaches the browser.** It's read from
  `/run/secrets/typesense_api_key` by `SearchControllerFactory`. The
  browser only ever sees a 1h scoped key.

## Module lifecycle

- `Module.php :: install/uninstall` — module-owned tables only
  (`iwac_browse_config` from M3). Never touches Typesense data.
- `Module.php :: attachListeners` — wires `api.*.post` events from M4.
  Empty in M0–M3.
- `SearchControllerFactory` — injects the Typesense client + module
  config into the controller.

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
  Add new sync steps / mappers THERE, never in the entry points.
- `npm run lint` includes `scripts/check-schema-drift.js`, which fails CI
  when `FacetCatalog::FACETABLE_FIELDS`, the schema YAMLs, and the i18n
  `FACET_LABELS` disagree. If you add a facet, all four must move together.

## Adding a new field

1. Add it to `data/schema.yaml`.
2. Bump the collection name (e.g. `iwac_v2` → `iwac_v3`) in the schema.
3. Update the relevant mapper to populate it from its Omeka property
   (declare the term in the mapper's `readTerms()`).
4. Run `omeka-s-cli discovery:reindex` — the indexer builds the new
   collection, verifies, then atomic-swaps the `iwac_current` alias.
5. Update the Svelte client if the field is user-visible.

## Architectural references

- **Triad (EngineAdapter / Indexer / Querier).** Mirrors
  [Daniel-KM's AdvancedSearch module](https://github.com/Daniel-KM/Omeka-S-module-AdvancedSearch).
  We're single-backend (Typesense), so EngineAdapter is implicit, but
  `src/Indexer/` and `src/Querier/` follow the same naming so editors who
  know AdvancedSearch can navigate this codebase.
- **AbstractBlockLayout pattern** for the page block — standard Omeka S
  4.x convention. Block data is persisted as JSON in `site_block.data`;
  multiple block instances per page are supported.

## Visual design

The CSS in `asset/css/iwac-search.css` consumes
[IWAC-theme](https://github.com/fmadore/IWAC-theme)'s CSS custom
properties. Token vocabulary used:

| Tokens                                              | Purpose              |
| --------------------------------------------------- | -------------------- |
| `--space-{xs,sm,md,lg,xl}`, `--space-{2,4,6}`       | Spacing              |
| `--primary`, `--ink`, `--muted`                     | Foreground colors    |
| `--surface`, `--surface-raised`, `--surface-sunken` | Backgrounds          |
| `--border`, `--border-strong`                       | Separators           |
| `--radius-{sm,md,lg}`                               | Rounded corners      |
| `--text-{sm,base,lg,xl,2xl}`                        | Fluid type scale     |
| `--ring-focus`                                      | Focus ring           |
| `--measure-{narrow,wide}`                           | Reading line lengths |
| `--size-control-{md,lg}`                            | Form control sizes   |

All selectors are scoped under `.iwac-search-block` / standalone shell —
no global rules — so the module never collides with theme styles. When
adding new component CSS, **prefer existing tokens** over hard-coded
values; var() fallbacks degrade gracefully if the theme is swapped out.

## Linked repos

- [IWAC-docker](https://github.com/fmadore/IWAC-docker) — Typesense + nginx + backup (private)
- [IWAC-theme](https://github.com/fmadore/IWAC-theme) — Omeka S theme (defines the design tokens this module consumes)
- [IWAC-Hugging-Face](https://github.com/fmadore/IWAC-Hugging-Face) — Omeka → HF pipeline
- [IwacVisualizations](https://github.com/fmadore/IwacVisualizations) — Omeka analytics module
