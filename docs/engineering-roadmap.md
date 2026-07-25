# Engineering roadmap — refactoring, hardening, and deferred work

Outcome of the July 2026 whole-repo engineering review (four parallel
audits: PHP indexer, PHP web/service layer, Svelte/TS client, and
build/CI/docs). Companion to [ROADMAP.md](../ROADMAP.md), which tracks
_product_ deferrals; this file tracks _engineering_ ones.

Two sections: what the review fixed (so future readers know why the code
looks the way it does), and what remains — ordered by phase, each item
with enough context to be picked up cold.

---

## Done (July 2026 — second review pass)

Findings and rationale in
[module-review-2026-07.md](module-review-2026-07.md); this is the landing
record.

| Fix                                                                                                                                                                                                                                                                                                                                                                                                                                                      | Where                                                               |
| -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------- |
| **Full-mode page blocks ignored their configured Default sort.** `readUrlState()` hardcoded `_text_match:desc`, so a block's `bootstrap.default_sort` never reached first render — the admin setting did nothing, the SSR snapshot was discarded on every preset block, and entity blocks rendered a sort control that disagreed with the applied order. The surface's default is now threaded through read/write/pop so the URL round trip preserves it | `urlState.ts`, `App.svelte`                                         |
| Site-wide header bundle no longer ships the whole search client: `runSuggest()` + the scoped-key cache are free functions both callers compose (class methods can't be tree-shaken). 42.8 → 33.9 KB raw, 14.7 → 12.8 KB gzipped, on every public page                                                                                                                                                                                                    | `suggestQuery.ts`, `scopedKey.ts`, `header.ts`                      |
| `/search` no longer runs SSR and client against two independent copies of `query_by` — `contentBootstrap()` emits the PHP constants, and the drift guard now compares them (plus the sort-option lists) across PHP/TS                                                                                                                                                                                                                                    | `SurfaceBootstrap`, `check-schema-drift.js`                         |
| One bootstrap builder for /search, the federated tabs and page blocks (three hand-assembled arrays that had already drifted); endpoint stems in one constant instead of five call sites. Also fixes a latent double-`basePath()` on block endpoints under a subdirectory install                                                                                                                                                                         | `Search/SurfaceBootstrap.php`                                       |
| `PropertyValues` value object: the Omeka value-extraction primitives existed in three classes (two identical implementations of `displays()`, `firstLiteral()` twice, `linkedIds`/`vrids`), each repeating the same 5-line array-shape annotation 19 times over                                                                                                                                                                                          | `Indexer/PropertyValues.php`                                        |
| `IncrementalIndexer` reuses `CollectionOps::flushBatch()` / `deleteDocument()` instead of reimplementing JSONL import + the success tally twice and the 404 rule once. `CollectionOps` takes a lazy client factory, so the incremental path keeps its "never block an Omeka save" property                                                                                                                                                               | `IncrementalIndexer`, `CollectionOps`                               |
| `AbstractTypesenseJob` — the four jobs were the same job around one line of real work (~40 lines removed, and the `dirname(__DIR__, 2)` module-root fragility got one home)                                                                                                                                                                                                                                                                              | `Job/`                                                              |
| `IwacInstance` — every instance-specific assumption (content + entity class ids, reference class labels, per-country item sets, the Notices set, site base + locale slugs) in one reviewable place instead of eight files                                                                                                                                                                                                                                | `src/IwacInstance.php`                                              |
| `CollectionOps` injected into both reindexers rather than constructed inside them, so `promote()`'s health guard — the code that protects live search — is finally stubbable                                                                                                                                                                                                                                                                             | `Reindexer`, `IndexReindexer`, `ReindexOrchestrator`                |
| `MapperRegistry` resolves class → mapper once at construction (was a linear scan per item on the hot incremental path) and now fails loudly if two mappers claim one class                                                                                                                                                                                                                                                                               | `Mapper/MapperRegistry.php`                                         |
| `OmekaSourceReader` binds `IN (…)` lists via `ArrayParameterType` instead of hand-built int lists + hand-escaped term lists — the module's last hand-rolled SQL escaping. The keyset-page query keeps its inlined (loop-invariant) lists deliberately, now explained                                                                                                                                                                                     | `Indexer/OmekaSourceReader.php`                                     |
| `App.svelte` −140 lines: filter selections, typeahead + "/" shortcut, and copy-link state are composables like the existing `viewMode` / `filterDrawer`; `AbortSlot` / `SeqGuard` replace four hand-rolled abort dances and the map loop's counter                                                                                                                                                                                                       | `lib/filterState`, `lib/typeahead`, `lib/clipboard`, `transport.ts` |
| `InitialResponseRenderer::dedupeFacets()` deleted in favour of `FacetCatalog::normaliseFacets()` (same function); `'fr_default'` replaced by `StopwordsSync::SET_NAME`; `onHydrate()` normalises all six block fields, not two; `Module::upgrade()` version-gates its legacy table drop                                                                                                                                                                  | various                                                             |

## Done (July 2026 review branch)

### Security / correctness

| Fix                                                                                                                                                                                             | Where                                        |
| ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------- |
| Stored-XSS: block `intro_html` purified via Omeka's `HtmlPurifier` on save (`onHydrate`) and re-purified at render for legacy blocks                                                            | `IwacSearchBlock`                            |
| Guarded alias swap: a reindex whose import was empty or >10 % errors aborts _before_ the swap — previously a systemic import failure put an empty collection live and dropped the last good one | `CollectionOps::promote()`                   |
| Orphan-collection sweep after every successful swap: crashed/overlapping runs used to leak timestamped collections (Typesense is RAM-resident — leaks are permanent)                            | `CollectionOps::promote()`                   |
| Items that stop being mappable get their stale (possibly public) document deleted instead of left live                                                                                          | `IncrementalIndexer`                         |
| `/discovery/token` 503 no longer ships the exception chain (internal hosts, secret paths) to anonymous clients — Omeka log only                                                                 | `SearchController::tokenAction`              |
| False "locked_filters are enforced server-side" claim corrected: they are cosmetic client scoping; the scoped key's `is_public` + `ocr_text` constraints are the only privacy boundary          | `IwacSearchBlock` docblock + admin form help |
| Dead `public_search_key.filter_by/exclude_fields` config keys removed — they were never read; the provider hardcodes the security contract (single source of truth)                             | `config/module.config.php`                   |
| Highlight sanitising unified on escape-then-restore-`<mark>` — the suggest path's tag-allowlist regex let `<mark onmouseover=…>` through                                                        | `src/svelte/lib/sanitize.ts`                 |

### Efficiency

| Fix                                                                                                                                                                                                 | Where                                     |
| --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------- |
| Indexer event listener resolved lazily at fire time — the full graph (6 mappers, EntityAuthority, CountryResolver's JSON parse) was built on **every** HTTP request                                 | `Module::attachListeners`                 |
| `reindexItems()` batch path: one DB load + one entity resolution + one JSONL import for batch update/create and item-set cascades (was ~4 SQL + 1 HTTP per item, inside synchronous admin requests) | `IncrementalIndexer`, `ItemEventListener` |
| SSR skipped on `/search` deep links (`?q=`, `?f.*`, sort/page/date) whose snapshot the client immediately discards                                                                                  | `SearchController`                        |
| Scoped-key cache module-scoped in the client — the federated page's per-query `App` remounts re-minted a token per committed keystroke                                                              | `typesense.ts`                            |
| `PresetCatalog::all()` memoized; `EntityOccurrences` accumulates a year histogram instead of storing every occurrence year                                                                          | `Search/`, `Indexer/`                     |

### Drift prevention / refactoring

| Fix                                                                                                                                                                                                     | Where                               |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------- |
| `MapperRegistry::default()` — one mapper registration point for bulk + incremental (two hand-copied lists before)                                                                                       | `Mapper/MapperRegistry.php`         |
| `CollectionOps::createVersioned()/importAll()/promote()` — the two reindexers' copy-paste lifecycle unified                                                                                             | `Indexer/CollectionOps.php`         |
| `SearchDefaults::CONTENT_PROMINENT_FACETS` — one facet stack for `/search`, the federated Content tab, and the block-form default (the block copy had silently drifted)                                 | `Search/SearchDefaults.php`         |
| `Preset::redirectQuery` — `/browse` redirects read declared data instead of regex-reverse-parsing the filter string the catalog itself built                                                            | `Search/Preset.php`, `browseAction` |
| `cli/bootstrap.php` — ~50 triplicated bootstrap lines extracted; the "exit 2 = setup error" contract now actually enforced                                                                              | `cli/`                              |
| `Asset\SvelteAssets` — one place that knows the compiled bundle's file set (Module + page block both inject through it)                                                                                 | `src/Asset/`                        |
| `TypesenseClientLazy` closure memoizes; three per-class `$cachedClient` fields deleted                                                                                                                  | `Service/`                          |
| Dead code removed: `SchemaLoader::fieldNames`, `OmekaSourceReader::propertyId`, `MapperRegistry::has`, `PresetCatalog::has`, three unused `FacetCatalog` helpers, `Button.svelte`, two unused i18n keys | —                                   |

### CI / tooling

| Fix                                                                                                                                                                                                                                   | Where    |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------- |
| `git diff --exit-code -- asset/dist` after the CI build — a PR editing `src/svelte*` without rebuilding now fails instead of shipping a stale bundle (production has no Node toolchain)                                               | `ci.yml` |
| PHP job: 8.2 + 8.4 matrix; `php -l` now covers `config/` and `view/**/*.phtml`                                                                                                                                                        | `ci.yml` |
| `scripts/` linted + formatted (the CI-gating scripts escaped their own gates); `engines: node>=22`; `tokens.json` prettier-ignored; Guzzle stack added to the dependabot composer group; `configurable=false` (no config form exists) | various  |

### A11y / UX correctness

| Fix                                                                                                                                                                   | Where                 |
| --------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------- |
| Federated tabs keyboard-navigable (arrows/Home/End; inactive tabs were unreachable — WCAG 2.1.1) and Back-button-able (history pushes on committed query/tab changes) | `FederatedApp.svelte` |
| Map fetch sequence-guarded (stale multi-page loop could overwrite newer markers)                                                                                      | `App.svelte`          |
| `?sort=` share links fetch instead of reusing the default-sorted SSR snapshot                                                                                         | `App.svelte`          |
| URL `page` clamp raised 50 → 10 000 sanity ceiling (deep links were silently re-clamped)                                                                              | `urlState.ts`         |
| `ExportMenu` downgraded from fake `role="menu"` to honest disclosure semantics; `IwacHit.highlights` typed optional (crash on browse-shaped hits in the suggest path) | components            |

---

## Phase 1 — Test harness (done) + static analysis (started)

**Tests: done.** Two suites, both wired into CI as blocking gates:

| Suite   | Command                                | Covers                                                                                                                                                                                                                                                                       |
| ------- | -------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Vitest  | `npm test`                             | `urlState` codec round-trips (incl. the surface-default-sort fallback that A1 got wrong), `queryBuilders` filter/sort construction, `sanitize` XSS neutralisation, the `filterState` composable                                                                              |
| PHPUnit | `composer test` / `vendor/bin/phpunit` | `CollectionOps::promote()` health guard + orphan sweep against an in-memory Typesense fake, the six mappers' derivations, `PropertyValues`, `CountryResolver`, `SchemaLoader`, `EntityOccurrences`, `IndexEntityMapper`, `FacetCatalog`, `PresetCatalog`, `SurfaceBootstrap` |

Both run without Typesense, MySQL or an Omeka bootstrap — that is what
made the seams worth extracting in the first place. `tests/php/Support/FakeTypesense.php`
is a hand-written in-memory server (collections, alias table, import log)
rather than nested mock expectations, so the alias-guard tests read as
scenarios rather than call assertions.

Deliberately NOT covered, and why: `OmekaSourceReader` (its whole job is
SQL, and Doctrine DBAL is an Omeka-core dependency this module doesn't
ship), the controllers and block layout (they extend Laminas/Omeka base
classes that aren't installable standalone), and the `Job` classes (thin
wrappers over an `AbstractJob` we don't have). Those are exercised by the
IWAC-docker stack.

**PHPStan: configured, NOT yet verified.** `phpstan.neon` plus the two stub
files under `tools/phpstan/` are committed, and CI runs the analysis — but
with `continue-on-error: true`, because **the analysis has never actually
been executed**. Finishing it is a short, well-defined task:

1. `composer install && vendor/bin/phpstan analyse`
2. Fix what it reports, or lower `level` (currently 6) until green, or add
   narrowly-scoped `ignoreErrors` entries with a comment each.
3. Delete `continue-on-error` from the phpstan step in `ci.yml`.

Two findings from setting it up, so the next person doesn't rediscover them:

- **Omeka and Laminas are stubbed, not dev-dependencies.** Omeka S is an
  application, not a Packagist library. And Omeka pins its own Laminas
  versions, so a `require-dev` copy would have PHPStan analysing against
  signatures that differ from production — exactly the failure a type gate
  exists to prevent. The two stub files are also a readable map of this
  module's entire framework coupling (~16 Omeka classes, ~20 Laminas).
- **`laminas/laminas-log` cannot be a dev dependency at all**: version 2.17,
  which Omeka S 4 ships, declares `php ~8.1 || ~8.2 || ~8.3` and so fails to
  install on the 8.4 leg of the CI matrix. Its one interface is stubbed.

## Phase 2 — Known behavioural gaps (each small, verify on live stack)

- **Export and map fetches don't apply exact mode, but the live search
  does.** Surfaced while extracting `resolveContext()`: `search()` and
  `yearDistribution()` switch a quoted / `-excluded` query to strict
  keyword matching (drop `embedding`, no typo tolerance), while
  `fetchForExport()`, `fetchForMap()` and `searchFacetValues()` do not. So
  exporting the results of `"radicalisation en Côte d'Ivoire"` can include
  semantically-similar documents the user never saw on screen. The
  refactor preserved the existing behaviour deliberately — changing what
  an export contains is a product decision, not a cleanup — and the call
  sites now say `applyExact: false` explicitly instead of diverging by
  omission. Decide whether export/map should mirror the live set (probably
  yes for export; the map's `frequency:desc` browse ordering makes it less
  clear-cut).

- ~~**Tighten the search-only parent key scope** from `collections: ['*']`
  to the two aliases.~~ **Unblocked, one config line away.** The blocker
  was never really the Typesense semantics (does a key scope match the
  requested alias or the resolved collection?) — it was that answering it
  required a code deploy per attempt. The scope is now
  `iwac_search.public_search_key.collections`, and the settings slot
  caching the parent key is keyed by a hash of the scope, so changing the
  config re-mints automatically and reverting it finds the old key again.
  `TypesenseSearchKeyProvider::TIGHTENED_COLLECTION_SCOPE` holds the
  intended value — both aliases *and* the `iwac_v*` / `iwac_index_v*`
  prefixes, which makes the alias-vs-resolved-name question moot while
  still excluding the analytics collections (visitor query logs).
  Remaining: try it on the live stack, then delete the wide-scope key in
  Typesense. Default stays `['*']` until someone does.
- **Edits during a bulk reindex are lost at the swap** (documented in
  `IncrementalIndexer`'s header): upserts go through the alias → the
  outgoing collection. Fix shape: record a start watermark, collect item
  ids saved during the build (or query `resource.modified`), re-run
  `reindexItems()` against the new collection after the swap.
- ~~**Union-tab deep pagination cap**~~ — done. `FederatedApp` still
  clamps the union list to 50 pages (Typesense won't page deeper on a
  merged list), but the last page now carries a "refine your query" hint
  instead of just ending.

## Phase 3 — Request-count reductions (medium effort, measurable wins)

- **Fold the year histogram into the main search** as a second
  `multi_search` sub-search (`per_page: 0`, `facet_by: pub_year`,
  year-filter stripped): every query/filter change currently costs two
  POSTs. Add `withYearDistribution` to `TypesenseClient.search()` and
  drop the separate effect in `App.svelte`.
- **`InitialResponseRenderer::renderMany()`** — `/search/everything`
  makes two sequential SSR round-trips; the renderer already uses
  `multiSearch->perform()` and just needs a multi-bootstrap body.
- **Cache the empty-query SSR snapshot** (APCu or filesystem, 30–60 s,
  keyed on a hash of the bootstrap search params) — it's identical for
  every anonymous visitor of `/search` and the federated landing.
- **Pass `query` to `App` as a reactive prop on the federated page**
  instead of `{#key}`-remounting per committed query — remounts still
  refetch everything and lose facet expand/scroll state (the token
  re-mint half is already fixed). Requires `App` to react to
  `initial_query` changes post-mount; touchy, do it with the Vitest
  harness from Phase 1 in place.

## Phase 4 — Mechanical UI dedupe (needs visual QA, zero behaviour change)

- **`FilterChip.svelte`** — the removable-chip button markup + ~40 lines
  of CSS are triplicated across `FacetPanel`, `ResultSummary`,
  `ResultsEmpty` (the chip _data_ is already shared via
  `deriveActiveChips`).
- **Reuse `SearchInput` in `FederatedApp`** — the federated page
  hand-rolls the same debounced input + clear button + webkit-cancel
  suppression.
- **`TypesenseClient` internal helpers** — the stopword-recovery retry
  loop exists three times (`search`, `unionSearch`, `fetchForExport`) and
  five methods repeat the key→collection→browse-mode→query_by preamble;
  extract `withStopwordRetry()` + `resolveContext()` next time the file
  is open for a feature. (The per-channel abort dance — four hand-rolled
  `?.abort()` + `new AbortController()` pairs, plus the map loop's counter
  — is already done: `AbortSlot` / `SeqGuard` in `transport.ts`. The
  retry/preamble halves stay open deliberately: they restructure the
  request path of the module's most critical file, which wants the Vitest
  harness from Phase 1 in place first.)
- **`ResultItem` list/gallery split** — already tracked in ROADMAP.md;
  same batch.

## Phase 5 — Schema/data hygiene (done)

- **The drift check is per-schema now, not OR-ed.** A catalog key is
  validated against every schema that DECLARES it, so losing `facet: true`
  in one collection while the other keeps it is a failure instead of
  silently green. Only `country_ss` is currently declared in both, and the
  check prints that list so the coverage stays visible. (Verified by
  dropping the flag from the entity schema alone: red, as intended.)
- **The `title_txt` stemming asymmetry is documented** in
  `schema-index.yaml` where someone comparing the two files will actually
  see it. Content titles are prose and stem; entity titles are proper nouns
  and must not — a French stemmer would conflate "Tijaniyya"/"Tijaniyyas"
  and chew the tail off "Bamako". Alias reconciliation on that collection
  is entity_aliases_txt + the synonym set's job, not the stemmer's.
- **Generating the shared field blocks from one source: decided against.**
  The two schemas share 11 fields, nearly all trivial (`id`, `title`,
  `is_public`, `omeka_url`, …), and the one interesting shared field —
  `title_txt` — is deliberately DIFFERENT between them. A generator would
  add a build step, obscure that asymmetry, and defend against a failure
  the per-schema drift check now catches directly. Revisit only if the
  shared surface grows well beyond its current size.

## Notes for future sessions

- The admin-shaped scoped key (no `exclude_fields`, gated on the Omeka
  session) sketched for M4 was never built; `tokenAction` serves the
  public key to everyone. Build it only with a concrete admin use case.
- `sourcemap: false` + committed minified bundles means production JS
  errors are undebuggable; `sourcemap: 'hidden'` interacts with the CI
  dist-diff check (maps must be committed or excluded) — decide together.
- The duplicated drift/theme checks in both `lint` and `build` npm
  scripts are deliberate (local `npm run build` should self-guard); CI
  pays a few redundant seconds.
