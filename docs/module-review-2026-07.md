# IwacSearch module review — July 2026

A read-through of the whole module (PHP + Svelte client + build wiring)
looking for correctness bugs, duplication, modularity seams, and
practice gaps.

This is a **companion** to [engineering-roadmap.md](engineering-roadmap.md),
not a replacement: everything already tracked there (test harness,
PHPStan, `FilterChip.svelte`, `ResultItem` split, `TypesenseClient`
preamble helpers, request-count reductions, key-scope tightening) is
deliberately **not** repeated below. What follows is what that review
did not already capture.

Findings are ordered by what they cost, not by effort.

---

## A. Correctness

### A1. Full-mode page blocks silently ignore their configured “Default sort”

`readUrlState()` hardcodes the sort fallback:

```ts
// src/svelte/lib/urlState.ts:53
sort: params.get(`${prefix}sort`) ?? '_text_match:desc',
```

`App.svelte` hydrates from it whenever `syncUrl` is true, and `syncUrl`
covers **full-mode page blocks**, not just `/search`:

```ts
// src/svelte/App.svelte:86
const syncUrl = $derived(isStandalone || (showSearchBox && bootstrap.mode === 'full'));
```

So a full-mode block never sees `bootstrap.default_sort` on first render.
Three consequences, all invisible until you look for them:

1. **The admin’s Default-sort choice does nothing.** `IwacSearchBlock`
   resolves the preset’s sort (or the admin override), validates it
   against `FacetCatalog::isValidSortFor`, and stamps it into the
   bootstrap — and the client throws it away. Picking “Author (A–Z)” on
   a block has no effect on the first page the visitor sees.
2. **The SSR snapshot is discarded on every preset block.** `skipNextFetch`
   requires `initial.sort === (bootstrap.default_sort || '_text_match:desc')`
   (`App.svelte:181`). Every content preset defaults to `date:desc` and
   the index preset to `frequency:desc`, so the comparison always fails
   and the block refetches on mount — throwing away the Typesense round
   trip `InitialResponseRenderer` just paid for. Page blocks with locked
   filters are the exact surface SSR was built for.
3. **Entity blocks show a wrong sort control.** `_text_match:desc` isn’t
   in the entity option set (`i18n.ts sortOptions(card='entity')`), so the
   `<select>` falls back to displaying its first option (“Most mentioned”)
   while `resolveSortBy()` actually browse-sorts by `date:desc`.

Not reachable on `/search` (its bootstrap default *is* `_text_match:desc`)
nor on the federated tabs (`showSearchBox=false` → `syncUrl=false`), which
is why it survived.

**Fix:** give `readUrlState` an explicit fallback —
`readUrlState(href, prefix, bootstrap.default_sort || '_text_match:desc')` —
and use it for the `sort` key only. One line each in `urlState.ts` and
`App.svelte`; a codec round-trip test would have caught it.

### A2. The site-wide header bundle carries the entire search client

`Module::injectHeaderSearchAssets` injects `iwac-search-header.js` on
**every public site page**. The docblock calls it “framework-free and
small (~15 KB)”. It is **42.8 KB** (`asset/dist/iwac-search-header.js`),
because `header.ts` imports `TypesenseClient` — and class methods are never
tree-shaken. Verified against the built bundle:

| Symbol present in the header bundle | Used by the header typeahead? |
| ----------------------------------- | ----------------------------- |
| `fetchForExport`                    | no                            |
| `fetchForMap`                       | no                            |
| `unionSearch`                       | no                            |
| `countAcross`                       | no                            |
| `yearDistribution`                  | no                            |
| `searchFacetValues`                 | no                            |
| `suggest`                           | **yes — the only one needed** |

Plus the whole of `i18n.ts` (both locales’ `FACET_LABELS`) for one
`facetLabel()` call.

Reusing `TypesenseClient` was the right call for *contract* reasons (one
scoped-key mint, one suggest shape) — the problem is only that a class is
the wrong unit for tree-shaking. **Fix:** split the client into free
functions over a small context object (`{bootstrap, getKey}`), or extract
`suggest()` + its key-mint into `lib/suggestClient.ts` that both
`TypesenseClient` and `header.ts` compose. Either way the header bundle
drops to roughly what the docblock already claims. At minimum, correct the
docblock so the number isn’t load-bearing misinformation.

### A3. `/search` runs SSR and client against two independent copies of `query_by`

`SearchController::contentBootstrap()` does **not** emit `query_by` /
`highlight_fields` (only `everythingAction` and `IwacSearchBlock` do). So on
the standalone `/search` route:

- the **SSR** search uses `SearchDefaults::CONTENT_QUERY_BY` (PHP);
- the **client** search uses `CONTENT_QUERY_BY_FALLBACK` (`queryBuilders.ts`).

The two strings are byte-identical today and nothing guards them. Drift
means the SSR’d first page and every subsequent page are searched over
different field sets on the busiest surface in the module.

**Fix (either, ideally both):** add `query_by` + `highlight_fields` to
`contentBootstrap()` so the TS constants become a genuine fallback rather
than the source of truth for one surface; and/or extend
`scripts/check-schema-drift.js` to compare the two pairs of constants (it
already parses PHP and TS data literals — this is a ~20-line addition to a
script that exists for exactly this class of bug).

### A4. Smaller correctness / hygiene items

- **`InitialResponseRenderer` hardcodes `'fr_default'`** (line 291) while
  `StopwordsSync::SET_NAME` exists and its docblock explicitly says “keep
  in sync with typesense.ts + InitialResponseRenderer”. Use the constant.
- **`IwacSearchBlock::onHydrate` normalises only two of six fields.**
  `prominent_facets` and `results_per_page` are validated on save;
  `preset`, `mode`, and `default_sort` are persisted verbatim. Render
  defends against a bad `preset`/`default_sort`, but `mode` flows
  unvalidated into the bootstrap. Not a security hole (admin-only field,
  JSON-escaped), but the asymmetry means invalid state can persist and
  only misbehave later. Normalise all of them in one place —
  `PresetCatalog::get() !== null`, `isset(FacetCatalog::RENDER_MODES[$mode])`,
  `FacetCatalog::isValidSortFor()`.
- **`Module::upgrade()` ignores `$oldVersion`** and issues the
  `DROP TABLE IF EXISTS iwac_browse_config` on every upgrade forever.
  Harmless and idempotent, but the parameter is there to be used; a
  `version_compare($oldVersion, '3.0.0', '<')` guard documents when the
  drop stops being relevant.
- **`MapperInterface::map()`’s docblock** declares
  `array{id:int,title:string,is_public:bool,class:int}` while every
  implementation and caller passes `item_sets` too (and
  `DocumentMapper`/`PhotographMapper`/`ReferenceMapper` read it). Stale
  annotation on the one interface a new mapper author reads first.

---

## B. Duplication worth collapsing

### B1. Property-value extraction is written three times

The same “read Omeka grouped values” primitives exist in three classes:

| Primitive                    | `AbstractMapper` | `EntityAuthority` | `IncrementalIndexer` |
| ---------------------------- | ---------------- | ----------------- | -------------------- |
| display value (title ‖ literal) | `disp()`      | `displays()`      | —                    |
| first display value          | `firstDisp()`    | —                 | —                    |
| literals only                | —                | `literals()`      | —                    |
| first literal                | `firstLiteral()` | `firstLiteral()`  | —                    |
| first scalar (literal ‖ uri) | `firstScalar()`  | —                 | —                    |
| linked resource ids          | `linkedIds()`    | —                 | `vrids()`            |

`AbstractMapper::disp()` and `EntityAuthority::displays()` are
character-for-character the same function; `firstLiteral()` is duplicated
outright; `linkedIds()`/`vrids()` differ only in name. Every one of them
also repeats the same eight-line `@param array<string, list<array{vrid:?int,
value:?string,uri:?string,title:?string}>>` annotation — that shape appears
**19 times** across the indexer.

**Fix:** a `PropertyValues` value object wrapping the grouped array, with
`displays()/firstDisplay()/literals()/firstLiteral()/firstScalar()/linkedIds()`.
`OmekaSourceReader` returns it, mappers consume it. The 19 annotations
collapse to a type name, the three copies to one, and the object becomes
the natural unit for the mapper unit tests the roadmap wants.

### B2. `IncrementalIndexer` reimplements `CollectionOps::flushBatch`

`reindexItem()` and `reindexItems()` each build JSONL by hand, POST it with
`['action' => 'upsert']`, then `preg_split` the response and count
`success:false` lines — which is precisely what `CollectionOps::flushBatch()`
already does (and does slightly better: it caps the number of failure lines
it logs). Three copies of the same import-and-tally.

**Fix:** inject `CollectionOps` into `IncrementalIndexer` (it is already a
standalone, client-only collaborator) and call `flushBatch()`. Removes ~35
lines and makes the “what counts as a failed import” rule single-sourced.

### B3. The four jobs are the same job

`BulkReindex`, `SyncStopwords`, `SyncSynonyms`, `ProvisionAnalytics` all do:
resolve `LoggerResolver` → resolve `TypesenseClient` → compute
`dirname(__DIR__, 2)` → log “starting” with `job_id` → `try` the operation →
log the error and rethrow → log the stats. The only real variation is the
one-line operation and whether failure is fatal.

**Fix:** `abstract class AbstractTypesenseJob extends AbstractJob` with a
`protected abstract function operate(TypesenseClient $c, string $moduleRoot,
LoggerInterface $l): array;` plus a `protected function label(): string`.
Four job classes shrink to ~12 lines each, and the module-root
`dirname(__DIR__, 2)` fragility (three copies, plus two more in the
factories using `dirname(__DIR__, 3)`) gets one home.

### B4. `dedupeFacets` ≈ `FacetCatalog::normaliseFacets`

`InitialResponseRenderer::dedupeFacets()` and
`FacetCatalog::normaliseFacets()` are the same function — dedupe, drop
anything not in `FACETABLE_FIELDS`, preserve order — differing only in
input type (`list<string>` vs `iterable<mixed>`). The catalog version is
the more defensive one. Delete the private copy.

### B5. Bootstrap assembly and endpoint literals

The bootstrap blob is hand-built in three places with overlapping keys:
`SearchController::contentBootstrap()`, `SearchController::everythingAction()`
(entity tab), and `IwacSearchBlock::render()`. They already disagree —
the block emits `query_by`/`highlight_fields`/`card`, `contentBootstrap`
doesn’t (see A3), and only the block emits `hide_country`.

The two endpoint stems are written out in five places:
`SearchController::ENDPOINT_STEMS`, `IwacSearchBlock::render()`,
`Module::injectHeaderSearchAssets()`, `iwac-federated-mount.phtml`’s `??`
fallbacks, and `header.ts`’s `DEFAULT_*_ENDPOINT`. The route itself is a
sixth (`module.config.php`).

**Fix:** a small `Search\SurfaceBootstrap` builder — `forContent()`,
`forEntity()`, `forBlock(Preset|null, array $data)` — returning a
consistently-shaped array, with the endpoint stems as constants on it. The
PHP copies collapse to one; `header.ts`’s defaults stay (it must work if
the inline blob is missing) but become the only duplicate, documented as
such.

### B6. Sort options are duplicated PHP↔TS with no drift guard

`FacetCatalog::SORT_OPTIONS` / `SORT_OPTIONS_ENTITY` and
`i18n.ts sortOptions()` are parallel lists; both files carry a comment
saying “mirrors the other”. `check-schema-drift.js` guards
`FACETABLE_FIELDS` ↔ schema ↔ `FACET_LABELS` but not this pair — even
though the same drift class applies (a block can offer a sort the client
can’t render; see A1.3 for what that looks like on screen). Extend the
existing script.

---

## C. Design and modularity

### C1. Instance-specific numeric ids are scattered across eight files

The module is hard-wired to one Omeka instance, which is a reasonable
decision for a bespoke module — but the constants that encode it are
spread out with no index:

| Constant                       | Where                                        |
| ------------------------------ | -------------------------------------------- |
| Content class ids 36/60/49/38/58 | one `classIds()` per mapper (5 files)      |
| 9 reference class ids          | `ReferenceMapper::CLASS_LABELS`              |
| Entity class ids 94/96/9/54/244 | `EntityAuthority::CLASS_IDS` + `classDefault()` |
| Item set 267 (“Notices”)       | `EntityAuthority::SET_NOTICES`               |
| 15 per-country item-set ids    | `CountryResolver::COUNTRY_ITEM_SETS`         |
| Class 43 (chapter) special case | `ReferenceMapper::map()`                    |
| Site base URL + slug           | `Mapper\SiteUrls`                            |
| Site slugs `afrique_ouest` / `westafrica` | `IwacLocale`, plus both shell PHTMLs |

Nothing here is *wrong*, but there is no single place to answer “what does
this module assume about the Omeka install?”, which is the first question
on any migration, any template renumbering, and any attempt to reuse the
module. `data/newspaper-countries.json` already sets the precedent for
externalising instance data.

**Fix:** consolidate into one `data/instance.php` (or a
`Indexer\IwacTaxonomy` constants class) holding class ids, item-set ids,
the site base/slug, and the locale slug map — read by the mappers,
`EntityAuthority`, `CountryResolver`, `SiteUrls`, and `IwacLocale`. Keeps
every mapper’s `classIds()` a one-line lookup and makes the instance
contract reviewable in one diff.

### C2. Use DBAL array parameters instead of hand-built ID lists

`OmekaSourceReader` builds `IN (…)` clauses by hand in five places:

```php
$idList = implode(',', array_map('intval', $ids));
```

plus a manually quote-escaped term list in `loadValues()`. It is safe (the
`intval` and the code-defined terms make it so), but DBAL has supported
list parameters natively for years:

```php
$this->connection->executeQuery(
    'SELECT … WHERE resource_id IN (:ids) AND term IN (:terms)',
    ['ids' => $ids, 'terms' => $terms],
    ['ids' => ArrayParameterType::INTEGER, 'terms' => ArrayParameterType::STRING],
);
```

That removes the module’s only hand-rolled SQL escaping — worth doing on
principle in the one class that touches SQL, regardless of current safety.
(`streamDocs()`’s inlined `$classList`/`$setList` are the exception worth
keeping, since the keyset-paging statement should stay stable across pages;
a comment already explains that, and it should stay.)

### C3. Two small ownership nits in the indexer

- `Reindexer` and `IndexReindexer` each `new CollectionOps(...)` in their
  constructor rather than receiving one. Injecting it (from
  `ReindexOrchestrator`, which already owns the wiring) makes the guarded-swap
  logic stubbable — and `CollectionOps::promote()` is the highest-value
  unit-test target in the module per the roadmap. Right now it can only be
  tested through a full `Reindexer`.
- `MapperRegistry::forClass()` linear-scans every mapper’s `classIds()` for
  every item — 6 mappers × up to 9 ids, on the hot incremental path and on
  every row of a batch. Build the `classId → mapper` map once in the
  constructor (where the duplicate-subset check already runs) and make it an
  array lookup. Micro, but it’s three lines.
- `Reindexer::indexSubset()` and `mapSubset()` each call `$this->mappers->get($subset)`
  for the same subset; pass the mapper down.

### C4. `App.svelte` has four more extractable concerns

The roadmap’s Phase 4 covers `FilterChip`, `SearchInput` reuse, the
`TypesenseClient` preamble, and the `ResultItem` split. `App.svelte` (1,205
lines: ~660 script, ~320 CSS) has its own cohesive clusters that follow the
`viewMode.svelte.ts` / `filterDrawer.svelte.ts` composable pattern already
established in the file:

1. **Typeahead** — `suggestOpen`, `suggestRef`, `suggestActiveId`,
   `suggestExpanded`, `suggestListboxId` and seven `handleSuggest*` /
   `handleSearch*` handlers (~70 lines) → `createTypeahead(bootstrap)`.
2. **Copy link** — `linkCopied`, the timer, `handleCopyLink`, and the
   32-line `execCommand` fallback (~45 lines) → `lib/clipboard.ts`; the
   fallback is generic and belongs nowhere near search state.
3. **“/” shortcut** — the `$effect` with its `isContentEditable`/tag
   checks (~25 lines) → `createSlashShortcut(getInput)`.
4. **Filter mutations** — `handleFacetToggle`, `handleClearField`,
   `handleClearAll`, `handleRemoveChip`, `activeFilterCount` (~50 lines) →
   `createFilterState()`, which also gives the reducer-ish “toggle drops
   empty keys” rule a home to test.

That is ~190 lines of script out of ~660, leaving `App.svelte` as what its
own docblock says it is (“the orchestrator… owns state and wires events”).

### C5. `TypesenseClient`’s abort controllers

Beyond the preamble/retry duplication the roadmap already notes: four
separate `?AbortController` fields (`searchAbort`, `yearAbort`,
`unionAbort`, …) each repeat `this.xAbort?.abort(); this.xAbort = new
AbortController();`, and `fetchForMap` uses a hand-rolled sequence counter
instead because its multi-page loop can’t use one. A four-line `AbortSlot`
class (`next(): AbortSignal`) plus a `SeqGuard` for the loop case unifies
both idioms. Bundle with the `withStopwordRetry()` / `resolveContext()`
extraction when that file is next open.

---

## D. Practice

### D1. Tests: the two bugs above are exactly what the missing tests cover

The roadmap’s Phase 1 lists `urlState` round-trips and
`CollectionOps::promote()` among the first targets. A1 is a `readUrlState`
defaulting bug — the very first assertion anyone would write. Worth
promoting Phase 1 from “next up” to “blocking the next behavioural change”,
and starting with:

1. `readUrlState` / `syncToUrl` round-trip incl. the default-sort fallback (A1);
2. `CollectionOps::promote()` health guard (needs C3’s injection);
3. `AbstractMapper` derivations over fixture rows (needs B1’s value object —
   the two refactors reinforce each other).

### D2. Static analysis

Still none, and the codebase is unusually well-annotated (`@param
array{...}` shapes everywhere) — PHPStan would get real value from
day one, and would have flagged A4’s stale `MapperInterface` shape and the
`@phpstan-ignore-next-line` markers currently papering over the Typesense
client’s dynamic accessors. The roadmap’s stated blocker (needs Omeka in
the dev deps or a stub layer) is real; a `phpstan-stubs/` directory with
the ~10 Omeka classes actually referenced is a smaller lift than pulling
Omeka into `require-dev`.

### D3. What is genuinely good here (don’t regress it)

Worth stating explicitly, because most of it is unusual:

- **`CollectionOps::promote()`’s health guard + orphan sweep** is the right
  design for a RAM-resident search engine and is better than most
  reindexers ship.
- **Lazy client resolution end-to-end** (`TypesenseClientLazy`, the deferred
  event listener) — a down Typesense degrades every surface instead of
  500-ing any of them, and anonymous GETs don’t pay for the indexer graph.
- **The scoped-key model** with the security constraints deliberately
  hardcoded and *not* config-driven, plus the explicit “locked_filters are
  cosmetic” warning in the block docblock. That comment prevents a whole
  class of future mistake.
- **`check-schema-drift.js`** — a project-specific CI gate for a
  project-specific failure mode is exactly the right instinct; the
  suggestions in A3/B6 are asking for *more* of it, not less.
- **The docblocks explain “why”, not “what”**, and record decisions
  (why `api.create.post` is now attached, why the batch events exist, why
  the header CSS loads via the media-swap trick). This is the reason a
  cold review can find real bugs in an afternoon.

---

## Suggested order

| # | Item | Effort | Why first |
| - | ---- | ------ | --------- |
| 1 | A1 default-sort fallback | trivial | live bug, breaks an admin-facing feature + wastes SSR |
| 2 | A4 `fr_default` constant, `onHydrate` normalisation, `MapperInterface` shape | trivial | one-liners |
| 3 | A3 + B6 drift-guard extensions | small | prevents the next silent divergence |
| 4 | B4, C3 | small | pure deletions / three-line changes |
| 5 | A2 header bundle split | medium | ships on every page of the site |
| 6 | B1 `PropertyValues` | medium | unblocks the mapper tests |
| 7 | B2, B3, B5 | medium | mechanical, zero behaviour change |
| 8 | C1 instance constants | medium | do it with B1, same files |
| 9 | C4, C5 client extractions | medium | do it when a feature next opens those files |
