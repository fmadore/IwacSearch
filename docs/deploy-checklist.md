# Deployment and acceptance checklist — 3.19.0

## 3.26.2 (2026-10-27): DOI and Wikidata marks in colour

**No reindex.** A result card's DOI and Wikidata marks are drawn in their own colours (the
DOI's yellow disc, Wikidata's red, green and blue barcode) instead of muted single-colour
masks, the same files IWAC-theme 2.30.1 draws on the item page.

- [ ] Install alongside IWAC-theme 2.30.1.
- [ ] Search for a work with a DOI and an entity with a Wikidata id: both marks show in colour on the result card, light and dark.

## 3.26.1 (2026-10-26): map clusters open

**No reindex.** Clicking a cluster on the entity map did nothing: the handler read the
cluster's position after MapLibre had already cleared it from the click event, and threw.
A cluster now zooms in one step, as intended. Entities that share exact coordinates (Mô
and Djarkpanga in Togo, for one) never split apart at any zoom, so a cluster or dot that
stands for several of them now opens one popup listing every entity, most-mentioned first.

- [ ] Entity index → Map: click a numbered cluster; the map zooms in on it, light and dark, with no console error.
- [ ] Zoom into Togo near Kpangam and click the "2" there: a popup headed "2 entries at this location" ("2 entrées à cet endroit" on the French site) lists Mô and Djarkpanga, each linking to its record.

## 3.26.0 (2026-10-26): MapLibre 6.16.0, PHP 8.4 and a dependency refresh

**No reindex.** The schemas are unchanged. The module now requires **PHP 8.4** (the live
server runs 8.5), and its PHP dependencies move to symfony/yaml 8 and PHPUnit 13. Maps load
MapLibre GL 6.16.0 with the same files and SRI hashes as IwacVisualizations 1.85.0: deploy
the two together, or a visitor moving between a search map and a dashboard map downloads
MapLibre twice.

- [ ] Install the module update alongside IwacVisualizations 1.85.0 and IWAC-theme 2.30.0.
- [ ] Entity index in map view (the List | Map switch; the URL gains `?b<block id>.view=map`, e.g. `b1168` on the English Index page): the map renders in light and dark, the browser console shows no SRI or module errors, and the network panel loads `maplibre-gl@6.16.0`.
- [ ] Open a dashboard map, then the search map in the same tab: MapLibre is not downloaded a second time.

## 3.25.0 (2026-10-23): DOI and Wikidata links, clickable containers and editors

**Schemas `iwac_v11` and `iwac_index_v5` — a full reindex is required.** Content
and entity documents gain `wikidata_id`, the QID of the Wikidata entity the
record is reconciled with (a wikidata.org URI on `dcterms:identifier`), and
`doi` is stored as the bare DOI; `editor_ss` becomes a facet. Reindex right
after installing: until the new collection is live, clicking an editor's name
filters on a field the old collection does not index, and the search errors. Ship with IWAC-theme
2.28.0, which draws the same two marks on the item page.

- [ ] Install the module update, then run the full reindex and confirm `iwac_current` points at `iwac_v11` and `iwac_index_current` at `iwac_index_v5`. Until then the DOI link already shows (the field existed), the Wikidata link does not.
- [ ] References scope: a reconciled journal article (e.g. item 125674) ends its source line with the DOI mark + `10.48734/…` and the Wikidata mark + `Q141697087`, each opening in a new tab, light and dark.
- [ ] Entity index: a reconciled authority (Laïcité, Revue Akofena) shows its Wikidata link on the card's source line.
- [ ] Click a journal, book or publisher name in a reference card's citation line: the results narrow to it, the chip turns primary, and a second click removes the filter.
- [ ] Click an editor in a chapter's "(dir. …)" and in an edited volume's byline (Ouvrage collectif, e.g. item 15710, "Gilles Holder, Moussa Sow (dir.)"): the results narrow to that editor's books and chapters.

## 3.22.1 (2026-10-10): review fixes

The first release carrying these changes. 3.22.0 was tagged but never published: its release build wrote the zip to the wrong path (an env var named `ZIP`, which Info-ZIP reads as options). 3.22.1 is 3.22.0 plus that workflow fix.

**Schema `iwac_v10` — a full reindex is required.** Content documents gain
`abstract_ai`, true when the card body is a model's text (the
`bibo:shortDescription` summary, or a publication ToC on template 21); the
result card shows the EU "AI generated" mark for it. New field only. The
indexer now also reads `resource.resource_template_id` (no migration — the
column is Omeka core's).

- [ ] Install the module update, then run the full reindex (admin button, `cli/reindex.php` or `omeka-cli discovery:reindex`) and confirm `iwac_current` points at `iwac_v10`. Until then cards render exactly as before (no flag, no mark).
- [ ] Browse `/s/westafrica/search` with no query: article cards whose body is the AI summary open with the EU "AI generated" mark, light and dark; a query matching OCR text shows its snippet without the mark. A publication card's ToC excerpt carries it too.
- [ ] Export RIS from a browse page: AI summaries carry an `N1` note beside their `AB`.

## 3.21.1 additions

Dependencies only: no schema change, no reindex, no module upgrade step.

- [ ] **Deploy together with IwacVisualizations 1.75.2.** Both now load MapLibre GL 6.12.0 from the same jsDelivr files with the same SRI hashes. Shipping one without the other works, but then a page that has both maps downloads two copies of MapLibre.
- [ ] Open the Map view on the entity index (`/s/westafrica/page/index`, Map toggle) and confirm clusters render; in devtools, `maplibregl.getVersion()` reads `6.12.0`, and the three jsDelivr requests carry `integrity`.
- [ ] **Typesense transport is Guzzle 8** (from 7; `php-http/guzzle7-adapter` and `http-interop/http-factory-guzzle` are gone from `vendor/`). Confirm a search returns results, and that the maintenance page shows the live aliases and the generation list — both are read through the new client. CI's Omeka 4.2.1 + Typesense 30.2 job already exercises it end to end.

## 3.21.0 additions

**Schema `iwac_v9` — a full reindex is required.** The index now carries all
five AI sentiment models: Gemma 4 31B (`gemma_4_31b_it_*`) and Qwen3.8 27B
(`qwen3_8_27b_*`) join the three already there. New fields only; the reindex
builds `iwac_v9` and swaps `iwac_current` to it as usual.

- [ ] Run the full reindex (admin button, `cli/reindex.php` or `omeka-cli discovery:reindex`) and confirm `iwac_current` points at `iwac_v9`.
- [ ] Spot-check one article annotated by all five: the document carries `gemma_4_31b_it_polarite_ss` and `qwen3_8_27b_polarite_ss` beside `gpt_5_6_luna_polarite_ss`. The sidebar is unchanged — GPT-5.6 Luna remains the one surfaced trio.

- [ ] **Content-Security-Policy, if one is enforced:** the Map view now loads MapLibre 6, which is ES-module only and boots its worker from a `blob:` URL — `worker-src` must allow `blob:` (and `script-src` must allow `https://cdn.jsdelivr.net`, as before). IwacVisualizations has needed the same since its MapLibre 6 move, so a site serving both is already configured.
- [ ] Open the Map view on the entity index (e.g. `/s/westafrica/browse/…` with the Map toggle) and confirm clusters render; in devtools, `maplibregl.getVersion()` reads `6.11.2`, and the three jsDelivr requests carry `integrity`.
- [ ] Confirm the header typeahead still suggests on a non-search page (its bundle was slimmed from ~25 KB to ~16 KB).
- [ ] On `/search`, walk the result states once — a query, a zero-result misspelling (the "Did you mean" chips), a query that only matches semantically (the opt-in and its banner), and the Filters drawer on a phone. `App.svelte` was split into a fetch module and three components; a mocked-backend render of these states was pixel-identical before and after, but it has not been seen against the live index.
- [ ] On `/search/everything` in **dark** mode, the active tab (All / Content / Entities), the Filters count badge and the empty state's clear button on hover read dark ink on orange. That ink is the theme's `--ink-on-primary`, defined from the IWAC-theme release after 2.22.0; on 2.22.0 or older those three fall back to white, as before (3.23:1 in dark). Light mode is unchanged, and the active tab's count is no longer dimmed (3.87:1 → 4.78:1).

## 3.20.0 additions

Follow [operations-3.20.md](operations-3.20.md) after (or together with) the 3.19 rollout below.

- [ ] Run the Omeka module upgrade (purifies and flags existing search blocks' intro text).
- [ ] Publish IWAC-docker's v1.1.0 release (its php image also runs `search-worker`), then deploy IWAC-docker with its `docs/deploy-hardening.md`: recreate `typesense` at a quiet time, start `search-worker`, recreate `web`. This satisfies the 3.19 "schedule the drain" item.
- [ ] Confirm `/search-api/multi_search` still serves the search page and header typeahead, and that `/search-api/keys` or `/search-api/collections` answer 403.
- [ ] On the first rebuild, read `gate_held_seconds` and `catch_up` in the job log, and check the maintenance page's generation list.
- [ ] On staging, save an item with a missing required value inside a long-running job, then save another item from the admin: the second save must not wait for the job to finish.

These are live-environment checks, still pending. Follow the ordered migration in [operations-3.19.md](operations-3.19.md); publishing a GitHub release does not deploy the module.

## Required rollout

- [ ] Install the release zip (includes production dependencies) or install source with Composer from its lockfile.
- [ ] Upgrade the Omeka module to create the journal and rollback tables before resuming catalog writes.
- [ ] Configure an alias-only search parent; replace any mounted wider key.
- [ ] Run a full reindex and verify source/server counts, successful replay and both live aliases. This also applies all earlier schema migrations. Keep search in maintenance during the first privacy migration if the outgoing index contains values that must no longer be exposed.
- [ ] Revoke old parent keys and purge external caches containing old bootstrap JSON.
- [ ] Schedule the maintenance drain command every minute and verify the Omeka background worker.
- [ ] Check pending count/oldest timestamp, logs and storage capacity for retained generations. Schedule explicit pruning as appropriate.

## Acceptance on staging, then the live site

- [ ] Verify public metadata, linked authority visibility, thumbnails and bounded restricted-OCR excerpts with controlled fixtures. Inspect both SSR and browser responses; public keys must deny retained concrete collections and full OCR/vector projection.
- [ ] Edit, privatize and delete fixture items/authorities; move media and change item-set membership. Confirm queued work drains and content/entity search updates. Do not assume a fixed five-second withdrawal guarantee.
- [ ] On staging, interrupt Typesense, save a fixture change, restore the service and retry draining. Confirm work remains pending until successfully applied.
- [ ] On staging, edit/delete fixtures during a rebuild and inject a failing build. Confirm reconciliation and retention of the previous working aliases.
- [ ] Exercise content/entity/All search, exact and excluded terms, facets, year histogram/retry, map, export and pagination. Counts and scope must agree; histogram intentionally ignores its own selected year interval.
- [ ] Verify matching SSR hydration, sorted/page share links, browser Back, FR/EN labels, header suggestions and keyboard selection.
- [ ] Check list/gallery cards, entity mention/authorship counts, mobile filters, focus, Escape dismissal and scroll restoration.
- [ ] If analytics is enabled, provision rules and verify real searches are counted while auxiliary traffic is excluded. Native no-hit analytics and the browser semantic-only outcome have different meanings.

Optional infrastructure/product decisions remain in [ROADMAP.md](../ROADMAP.md); tuning remains in [engineering-roadmap.md](engineering-roadmap.md).
