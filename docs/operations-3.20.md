# IwacSearch 3.20 operations

3.20.0 implements the [27 September 2026 review](module-review-2026-09-27.md). It changes no schema (`iwac_v8` / `iwac_index_v4` stay) and needs no reindex; the [3.19 guide](operations-3.19.md) remains the reference for everything not listed here.

## Deployment

1. Install the release and run **Modules → IWAC Search → Upgrade**. The upgrade purifies and flags the intro text of every existing search page block (see _Page blocks_ below); it resolves Omeka's HTML purifier only if such a block exists.
2. Update IWAC-docker to the matching commit and deploy it with its [`docs/deploy-hardening.md`](https://github.com/fmadore/IWAC-docker/blob/main/docs/deploy-hardening.md), not by hand. `search-worker` runs the same published image as `php` (`ghcr.io/fmadore/iwac-docker/php:1.1.0`), so IWAC-docker's v1.1.0 release must be published before `docker compose up`, or the image pull fails. Its step 3 recreates `typesense` (new health check, CORS flag dropped, snapshot mount) — Typesense reloads every collection on start, so do it at a quiet time — starts `search-worker`, and recreates `web` (nginx bind mounts need a recreate, not a reload).
3. Confirm `docker compose logs search-worker` shows no `drain failed` lines and that the maintenance page lists the expected index generations.

Nothing else is required: the old `collection_alias` / `index_collection_alias` config keys are simply no longer read (see _Aliases_).

## Write path

- **The mutation gate is tied to each write's request object.** Omeka fires `api.execute.post` only when the adapter returns, so a validation error, a missing resource, a denied entity, a throwing pre-listener from another module, or a caller passing `finalize => false` used to leave the gate held for the rest of the process. In a long job that catches per-row errors, that stalled indexing and made every other editor's save wait five minutes and fail. `Indexer\WriteGate` now releases a level as soon as its request object is gone; Module also settles abandoned levels on API reads of items, media and item sets. With production's `zend.exception_ignore_args=On`, release happens at the next such API call after the failure.
- **A request whose pre event was skipped** (`initialize => false`) no longer releases a level on its post event; it could previously release an enclosing write's hold early.
- **Drain dispatch is deduplicated.** A save dispatches `DrainChanges` only when no drain job started in the last five minutes is still `starting`/`in_progress` (`Indexer\DrainJobHistory`). The running drain consumes rows appended while it works; the per-minute `search-worker` catches anything that slips between the two.
- **Incremental apply is batched.** One filtered delete per collection (`id:[…]`), one export-based read of existing entity aggregates, one import per collection — instead of one HTTP request per document. Withdrawals (deletes) are applied before metadata and content imports.
- **Known limit, unchanged:** a write with `flushEntityManager => false` journals at its post event before the caller flushes; if the drain reads in between, the next change to that item (or a rebuild) is what corrects it.

## Rebuild cutover

The rebuild now takes a second quiescent watermark after streaming content and does all corpus-sized work before taking the gate:

| Phase | Gate | Work |
| --- | --- | --- |
| Build | brief, for W1 | stream + import content (embeddings) |
| Settle | brief, for W2 | replay IDs journaled since W1; rebuild entity authority; metadata-only restream for per-type counts and occurrences; build the entity collection |
| Cutover | held | replay IDs journaled since W2 into **both** new collections; reconcile counts; promote both aliases |

Why it is exact: a write journals again on its post event, after its data is committed, so every change after W2 has a journal row above W2; any ID without one was final before the post-W2 reads. Expected per-type counts come from the settle-phase map of document types, adjusted by the cutover replay's outcome, and are checked with one faceted `type_s` search plus the total.

Job stats: `catch_up.unlocked_items` (settle replay), `catch_up.items` (gated replay), and `gate_held_seconds` — the time every catalog save may have waited. Watch the last one on the first production rebuild.

**Failed builds are dropped.** Any failure after a collection was created drops the new collections that no alias serves (a build an alias still points at after a failed rollback is never dropped). Previously they stayed resident in Typesense memory until a retention run at least seven days later.

## Retention

`cli/maintenance.php prune` and the maintenance cleanup button now also prune processed journal rows older than seven days and completed drain-job rows older than seven days (failed jobs are kept). IWAC-docker's `search-worker` runs it daily. The maintenance page shows every IWAC generation with its size, the alias it serves (or "retained"), and Typesense's memory figures.

## Aliases

`IwacInstance::CONTENT_ALIAS` / `INDEX_ALIAS` are the only alias names. The `iwac_search.typesense.collection_alias` and `index_collection_alias` config keys are gone: only the web factories read them, so changing them split search from the rebuild, the drain, analytics and the key scope. The parent-key scope (`public_search_key.collections`) is still configurable and defaults to the anchored forms of the constants.

## Public key and search traffic

- Every scoped key embeds `limit_multi_searches: 10` (Typesense's default is 50, and nginx rate-limits requests, not searches). The client's largest request is the typeahead's 7; `transport.ts` refuses larger requests and `check-schema-drift.js` keeps `TypesenseSearchKeyProvider::MAX_MULTI_SEARCHES` and `MULTI_SEARCH_LIMIT` equal. Keys minted before the upgrade keep their old restrictions until they expire (≤ 1 h).
- A mounted parent-key secret is validated against `GET /keys` once per key and scope (APCu, 10 minutes), not on every token request.
- Browsers keep the scoped key in `sessionStorage` for the tab, so navigating the site no longer mints a key per page. A rejected key is dropped from storage too.
- IWAC-docker proxies only `multi_search`, single-collection `documents/search` and `health` under `/search-api/` (403 otherwise) and rate-limits `/discovery/token`.
- `limit_hits` stays off on purpose: deep pagination is a product choice and the metadata is published openly on Hugging Face.
- Typeahead facet sampling was evaluated and **not** adopted: sampling can drop rarely mentioned entities from the suggestions entirely, which is the wrong trade for a research archive. Measure `use_cache` with the benchmark harness before adopting server-side caching.

## SSR

- The "does the URL carry search state" check reads the raw query string (`Search\SearchStateQuery`). PHP rewrites dots in parameter names, so `?f.country_ss=…` arrived as `f_country_ss` and every filter/year deep link — including the country pages the legacy `/browse/{country}` redirect produces — paid for a snapshot the client discarded. Page blocks now apply the same check to their own `b{id}.` state.
- The cache epoch is one SQL round trip and is skipped entirely when the snapshot cache is disabled.

## Page blocks

`intro_html` is purified on save and flagged (`intro_html_purified`); render purifies only unflagged legacy values, so a block is never output raw even on an install whose upgrade has not run. The no-JavaScript fallback links to Omeka's item browse for the current site (the search page needs JavaScript too).

## Verification

Local: 312 PHPUnit tests (917 assertions), 252 Vitest tests, `npm run lint` (including the new docblock and contract drift checks), `svelte-check`, both production bundles; IWAC-docker: security invariants, all three `docker compose config`s, `nginx -t`, and live nginx routing/rate-limit probes. PHPStan (level 6, the locked 2.2.13) reports no errors. The real Omeka/Typesense integration suite could not run in the implementation environment (it needs root, MySQL and a Typesense binary); the CI integration lane exercises the new cutover (settle-phase replay, gate stats, failed-build drop) and batched deletes/reads against Typesense 30.2 — check it before tagging.
