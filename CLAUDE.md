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

- **Two data sources, by design.**
  - Bulk reindex pulls from the [HuggingFace dataset](https://huggingface.co/datasets/fmadore/islam-west-africa-collection)
    (parquet, monthly cadence, has OCR + sentiment + topics).
  - Incremental updates (M4) come from the Omeka S API event hooks.
  - `is_public` always comes from Omeka — HF doesn't expose ACL state.
  - See `docs/data-sources.md` for the rationale.

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

## Adding a new field

1. Add it to `data/schema.yaml`.
2. Bump the collection name (`iwac_v1` → `iwac_v2`) in the schema.
3. Update the indexer to populate it from HF (and/or Omeka).
4. Run `omeka-s-cli discovery:reindex` — the indexer builds the new
   collection, verifies, then atomic-swaps the `iwac_current` alias.
5. Update the Svelte client if the field is user-visible.

## Linked repos

- [IWAC-docker](https://github.com/fmadore/IWAC-docker) — Typesense + nginx + backup
- [IWAC-Hugging-Face](https://github.com/fmadore/IWAC-Hugging-Face) — Omeka → HF pipeline
- [IwacVisualizations](https://github.com/fmadore/IwacVisualizations) — Omeka analytics module
