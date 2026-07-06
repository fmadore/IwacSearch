# IwacSearch

Omeka S module that owns the public discovery experience for the
[Islam West Africa Collection](https://islam.zmo.de/), backed by
[Typesense](https://typesense.org/).

Replaces `AdvancedSearch` + `SearchSolr` + `FacetedBrowse` on the public
side. Admin search, item detail pages, ingest, and IIIF tile serving stay
on Omeka.

## Status

**Public SSR.** Every discovery surface (`/search`, the federated `/search/everything`, and page blocks) paints results on first frame. The server calls Typesense during PHP dispatch, inlines the first-page response + facet counts into the bootstrap JSON, and the Svelte client seeds its `response` state at mount without any fetch roundtrip. If Typesense is unreachable the SSR quietly returns null and the client falls back to its normal scoped-key flow — same end state, one extra flash.

Admin CRUD mutations are optimistic (row appears / updates / disappears immediately) with rollback on server error. Editors + site-admins + global-admins have access via ACL rules in `Module::onBootstrap`.

| Milestone  |   Status   | Highlights                                                                         |
| ---------- | :--------: | ---------------------------------------------------------------------------------- |
| M0         |  ✅ done   | Schema, indexer pipeline (4 mappers + ACL overlay + stopwords), atomic alias swap  |
| M1         |  ✅ done   | `/search`, `/discovery/token`, page block, Svelte 5 client, alias-spelling search  |
| M2         |  ✅ done   | Facet panel, year range slider, URL state, sort, hybrid keyword+vector search      |
| M3         |  ✅ done   | `iwac_browse_config` table + 6 auto-seeded country pages + `/browse` landing       |
| M3.5       |  ✅ done   | Admin CRUD UI (Svelte, optimistic, SSR-inlined initial state, JSON API)            |
| Public SSR |  ✅ done   | PHP-side Typesense call inlines first page + facets into every public surface      |
| M4         |  ✅ done   | Full incremental sync: item create/update/delete + batch ops + media + item sets   |
| M5         |  ✅ done   | Typeahead dropdown — prefix search, keyboard nav, click-to-navigate                |
| M6         | 🟡 partial | Mobile filter drawer + result count + empty-state polish; cutover still planned    |
| 3.6.0      |  ✅ done   | Synonym set, search analytics, federated union "All" tab, entity Map view, UX wins |

**M4 coverage (complete as of 3.6.0):** every Omeka write path that affects the index now re-maps in the same request. Item create / update / delete were already wired; 3.6.0 adds the paths that silently bypassed them: **batch operations** (Omeka's batchCreate/batchUpdate/batchDelete hydrate entities directly and never fire the per-item events — privacy-relevant, since a batch visibility flip must reach the `is_public` filter promptly), **direct media edits** (thumbnail / IIIF presence derive from the item's primary media), and **item-set deletion** (membership drives `country_ss` on references / documents / photographs; members are captured at `api.delete.pre` and re-mapped after, capped + logged for very large sets). Entity-collection occurrence aggregates still refresh on the bulk reindex by design.

**3.6.0 highlights:** `iwac_synonyms` global synonym set (Arabic-transliteration variants — cheikh/sheikh/shaykh, Tidjaniyya/Tijaniyya, charia/sharia… — curated in `data/synonyms-fr.json`, search-time expansion, admin sync button); Typesense search-analytics provisioning + admin digest of top / zero-result queries (server flags pending — see [ROADMAP.md](ROADMAP.md)); a merged **All** tab on `/search/everything` (Typesense v30 union search, one relevance ranking across content + entities); a **Map view** on the entity index (geopoints from curated coordinates, MapLibre + CARTO basemap, clustered markers); plus recent-searches in the typeahead, a `/` focus shortcut, a copy-link button, request cancellation on fast typing, and a "did you mean" recovery on zero-result queries.

## Testing checklist (after each deploy)

Changes below this line are **not yet verified on the live site**. Tick
as you confirm; remove rows once they've been through a full cycle.

- [ ] **3.6.0 REINDEX FIRST**: after deploying, run the bulk reindex before anything else — the entity schema bumped to `iwac_index_v3` (geopoints) and the content collection only links `iwac_synonyms` on rebuild. See [ROADMAP.md](ROADMAP.md) for the ordered checklist.
- [ ] **3.6.0 synonyms**: after the reindex, search `sheikh` — results containing only `cheikh` must match (and vice versa). Then edit `data/synonyms-fr.json`, hit the admin **Sync synonyms** button, and confirm the change is live WITHOUT a reindex.
- [ ] **3.6.0 union "All" tab**: open `/search/everything?q=ramadan`. The **Tout/All** tab is active by default and shows ONE merged list mixing articles and entities, relevance-ranked, with pagination. Clicking an entity card's type chip hands off to the Entities tab pre-filtered.
- [ ] **3.6.0 map view**: on the entity index surface, the view toggle shows **Carte/Map**. Activating it lazy-loads MapLibre from jsDelivr (check the network tab — nothing MapLibre-ish loads before that), plots clustered markers over West Africa, popup links open the entity page. Requires the v3 index rebuild.
- [ ] **3.6.0 batch-edit sync**: select 3 items in the Omeka admin, batch-edit visibility to private. All 3 disappear from public search within seconds (previously: stale until bulk reindex).
- [ ] **3.6.0 media sync**: replace a media file on an item (media edit page, not the item form). The result card's thumbnail refreshes after the save.
- [ ] **3.6.0 analytics (after IWAC-docker flags)**: enable the server flags, click **Provision analytics**, run a few public searches, wait ≥1 flush interval, and confirm the maintenance page shows them under "Top queries". Verify the alias-binding caveat in ROADMAP.md.
- [ ] **3.6.0 typeahead history**: run two searches, clear the box, focus it — both appear under "Recent searches"; the clear action empties the list.
- [ ] **3.6.0 "/" shortcut + copy link**: press `/` anywhere on `/search` → the box focuses. Copy-link button puts the exact current URL (filters included) on the clipboard and confirms.
- [ ] **3.6.0 did-you-mean**: search a near-miss spelling of an entity (e.g. `Tidjaniya`). On zero results, entity chips appear above the empty state; clicking one applies the filter.
- [ ] **Install / upgrade path**: visit `/admin/module/browse`, confirm IwacSearch is at `0.1.0`; click Configure → empty page renders (expected — no settings form yet). Green "installed" banner gone on refresh.
- [ ] **Admin sidebar entry**: left sidebar shows **IWAC Search** under the Modules group. Click it, land on the browse-config table.
- [ ] **Admin: instant paint**: 6 seeded country rows visible on first frame (no spinner). Network tab shows zero XHR on mount.
- [ ] **Admin: create**: click _+ New browse page_, fill in slug / title / locked filter / pick 3 facets, hit Create. Row appears with pulsing optimistic style → settles once server responds. CSRF header is sent (check request headers).
- [ ] **Admin: edit**: click Edit on any row, change title + reorder facets, Save. Drawer closes, row updates in place.
- [ ] **Admin: delete + confirm**: click Delete → "Confirm?" button appears → click Confirm → row disappears. No modal. Confirm on server log that the DELETE request returned `{deleted: true}`.
- [ ] **Admin: slug validation**: try to create with uppercase slug, with spaces, with starting digit — each should show the red error banner _and_ leave the drawer open.
- [ ] **Admin: duplicate slug**: try to create `benin` (already exists) — server returns 409 with `slug_taken`; banner shows the detail; row rolls back.
- [ ] **Admin: ACL**: log in as an editor (non-admin). Can they reach `/admin/iwac-search/browse-config`? Expected: yes. Log out → expected: Omeka redirects to login.
- [ ] **Public: `/search` SSR**: arrive with an empty URL. First 10 items visible in view-source (HTML, not just JS). No flash of empty state.
- [ ] **Public: `/browse/benin` SSR**: same. Country-filtered results in view-source.
- [ ] **Public: page block with locked filter**: any site page with the IWAC Search block. Results in view-source, not flashed after mount.
- [ ] **Public: `/search?q=ramadan` still fetches**: URL-hydrated query differs from SSR snapshot; client fetches and replaces within ~200ms.
- [ ] **Public: year slider**: drag either handle, see results update. Inline range display updates. Reset restores bounds.
- [ ] **Public: token endpoint resilience**: temporarily rename `/run/secrets/typesense_api_key`, refresh `/search`. Page still loads (SSR gracefully returns null), error banner shows actual reason — the `detail` field now walks the full `getPrevious()` chain so ops sees the root cause, not just Laminas's `ServiceNotCreatedException` wrapper.
- [ ] **Public: anonymous access (0.2.1 ACL fix)**: log out completely, then visit `/search`, `/browse/benin`, and any site page that hosts an IWAC Search block (e.g. `/s/westafrica/page/benin-typesense`). The Svelte client must mount and the `/discovery/token` request must return a real scoped key — not `PermissionDeniedException` or `Token HTTP 500`. Direct curl: `curl -i https://islam.zmo.de/discovery/token` should yield 200 + JSON body, not 500 HTML.
- [ ] **0.2.4 PSR-18 deps installed**: after the deploy script copies the module into the Omeka volume, `composer install --no-dev` MUST run inside the php container so guzzlehttp/guzzle, php-http/guzzle7-adapter, and http-interop/http-factory-guzzle land in the module's `vendor/`. Verify with: `docker compose exec php ls /var/www/html/modules/IwacSearch/vendor/php-http/guzzle7-adapter` — directory must exist. If it doesn't, the token endpoint will 503 with "No PSR-18 clients found".
- [ ] **0.2.5 per-search error handling**: temporarily point the bootstrap at a non-existent collection (e.g. rename the alias in Typesense to `iwac_missing`) and reload `/search` or any page block. The Svelte client must show the red **Search unavailable** banner with a message like `Search HTTP 404: Could not find a collection named iwac_missing` — NOT a console crash with `Cannot read properties of undefined (reading 'length')` and a half-rendered empty UI. SSR must also fall through (no `initial_response` in the inlined JSON; check view-source). Flip the alias back; the banner should clear on the next reload.
- [ ] **Slider, single-track final form (0.2.3)**: `/search` page on a desktop browser, scroll to the Year filter. Visible elements: ONE horizontal grey track with ONE primary-coloured filled segment between TWO circular thumbs. No second parallel line. Drag a thumb — it tracks the cursor, the filled segment resizes live, the inline `Year: NNNN – NNNN` display updates. Click anywhere on the empty track — the nearer thumb snaps to that position. Keyboard: tab to focus a thumb, arrow keys move ±1, Page ±10, Home/End jump to bounds. The thumbs cannot cross.
- [ ] **Public: search input**: typing is debounced, not wiped mid-keystroke (regression from the earlier `$effect` self-retrigger bug).
- [ ] **M4: is_public toggle**: take any item currently showing on `/browse/benin`. In the Omeka item admin, flip visibility to private. Save. Refresh `/browse/benin` within 5 s — the item must be gone. Flip back to public, refresh — it reappears. Check `/var/log/...` for an `IwacSearch: is_public updated in Typesense` info line per save.
- [ ] **M4: item delete**: create a throwaway item in Omeka admin, confirm it appears in Typesense (via `GET /search-api/collections/iwac_current/documents/<id>` or by showing up in a search). Delete the item. The Typesense doc should also be gone (`404` from the same GET). Log shows `IwacSearch: item deleted from Typesense`.
- [ ] **M4: failure mode**: temporarily block Typesense (e.g. `docker compose stop typesense`). Toggle an item's visibility — the save should complete normally, the admin shouldn't see an error, and the log should show `IwacSearch: failed to update is_public in Typesense`. Restart Typesense; subsequent saves should succeed again without any intervention.
- [ ] **M5: typeahead dropdown**: focus the search input on `/search`, type `ram` (or any 2+ char prefix). A dropdown with up to 6 hits should appear within ~150 ms. Each row shows the highlighted title plus a type chip.
- [ ] **M5: keyboard nav**: with the dropdown open, ↓ highlights the first row, ↓↓ the second; ↑ wraps. Esc closes. Enter on a highlighted row navigates to its `omeka_url` (or seeds the query if no URL).
- [ ] **M5: click-to-navigate**: clicking a suggestion opens its Omeka detail page. Cmd/Ctrl-click opens in a new tab. Plain click closes the dropdown.
- [ ] **M5: blur close**: click anywhere outside the search box → dropdown disappears. Click inside the dropdown → does NOT close (no premature blur).
- [ ] **M5: scoped suggestions**: on `/browse/benin`, type a prefix that matches docs from another country (e.g. an entity unique to Niger). The dropdown should show only Bénin docs — `locked_filters` is honored on the suggest call too.
- [ ] **M5: graceful failure**: stop Typesense, type into the box. The dropdown stays empty (no error popup); the main search shows its existing error banner. Restart Typesense, keep typing — suggestions resume without a page reload.
- [ ] **M6: mobile drawer trigger**: load `/search` on a phone (or DevTools mobile emulation < 768 px). The facet column should be **hidden**; a "Filters" button appears in the results toolbar instead.
- [ ] **M6: drawer open + close**: tap the Filters button → panel slides in from the right with a backdrop fade. Apply a filter → results update behind the drawer. Tap × / backdrop / press Esc → drawer slides out.
- [ ] **M6: active-count badge**: with a couple of filters selected, the Filters button shows a red pill with the count (e.g. `Filters [3]`). Clearing all filters removes the badge.
- [ ] **M6: body scroll lock**: drawer open on a long page → scrolling the backdrop doesn't move the underlying results. Closing the drawer restores normal scroll.
- [ ] **M6: result count display**: above the results, on every surface, a "142 results" line is visible (or "1 result" / "No results"). Updates in real-time as filters change.
- [ ] **M6: empty state**: search for `xyzzyx` (or apply mutually-exclusive filters). The results pane shows a dashed empty state with "Clear all filters" if filters are active, or a "try a broader query" message if just the query missed. No "Type a search term…" hint.
- [ ] **M6: desktop unchanged**: viewport ≥ 768 px, the facet column is back to its sticky two-pane layout — Filters button is hidden, drawer chrome is suppressed. No regressions on desktop.
- [ ] **Drawer extraction: admin form regression**: open `/admin/iwac-search/browse-config`, click Edit on any row. Drawer slides in from right, header shows `Edit: …`, × button closes, ESC closes, backdrop click closes. Body scroll locks while open, restores on close. (Same behaviour as before; just verifying the move to shared `<Drawer>` didn't break it.)
- [ ] **Drawer extraction: mobile filter regression**: same set of behaviours on the public `/search` page in a narrow viewport. Open Filters → close via × / Esc / backdrop. No layout flicker, no double-rendered FacetPanel.
- [ ] **Drawer extraction: viewport resize**: open the mobile filter drawer, then drag DevTools to widen past 48rem. Drawer should auto-close (no orphan overlay floating over the desktop layout). Narrow back down — Filters trigger reappears, drawer state defaults to closed.
- [ ] **Drawer extraction: scroll lock cleanup**: open drawer on a long page → `document.body.style.overflow` is `hidden`. Close → it's back to `''`. Refresh mid-open → no leaked overflow lock on next load.

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
│   │   ├── SearchController.php                # /search, /search/everything, /discovery/token, /browse redirect
│   │   └── Admin/MaintenanceController.php     # /admin/iwac-search/maintenance (reindex, stopwords, status)
│   ├── Indexer/                                # Bulk reindex pipeline (reads Omeka MySQL)
│   │   ├── SchemaLoader.php                    #   reads data/schema.yaml
│   │   ├── OmekaSourceReader.php               #   DBAL: keyset item stream + value loading
│   │   ├── EntityAuthority.php                 #   entity lookup from classes 94/9/96/54/244
│   │   ├── EntityOccurrences.php               #   per-entity metric accumulator
│   │   ├── CountryResolver.php                 #   derives country_ss (newspaper / item-set)
│   │   ├── StopwordsSync.php                   #   PUTs fr_default to Typesense
│   │   ├── CurationSync.php                    #   PUTs iwac_diversity curation set
│   │   ├── Mapper/                             #   one mapper per content subset (by class)
│   │   │   ├── MapperInterface.php
│   │   │   ├── AbstractMapper.php
│   │   │   ├── ArticleMapper.php · PublicationMapper.php · DocumentMapper.php
│   │   │   ├── AudiovisualMapper.php · ReferenceMapper.php · IndexEntityMapper.php
│   │   │   └── MapperRegistry.php
│   │   ├── Reindexer.php                       #   content collection, atomic alias swap
│   │   └── IndexReindexer.php                  #   entity (iwac_index) collection
│   ├── Browse/
│   │   └── FacetCatalog.php                    #   facetable fields + content/entity sort sets
│   ├── Site/BlockLayout/IwacSearchBlock.php    # Page block — drop into any Site page
│   ├── Log/
│   │   ├── OmekaPsrLogger.php                  # PSR-3 ↔ Laminas\Log adapter (psr/log 3.x-safe)
│   │   └── LoggerResolver.php                  # static helper: container → wrapped PSR-3 logger
│   ├── View/Helper/IwacBootstrapJson.php       # encodes bootstrap blob with the canonical JSON flag set
│   ├── Search/
│   │   ├── PresetCatalog.php · Preset.php      # page-block scopes (all / country / references / entity index)
│   │   ├── SearchDefaults.php                  # per-collection query_by / highlight fields
│   │   ├── InitialResponseRenderer.php         # SSR: PHP→Typesense, inlines first page into bootstrap
│   │   └── TypesenseSearchKeyProvider.php      # mints scoped keys for the browser
│   ├── svelte/                                 # Svelte 5 + TS client source — public bundle
│   │   ├── App.svelte                          #   per-mount root, owns search state
│   │   ├── components/
│   │   │   ├── SearchInput.svelte              #   debounced text input
│   │   │   ├── SuggestDropdown.svelte          #   typeahead — prefix-search dropdown (M5)
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
│   ├── svelte-shared/                          # Reusable widgets used by the public bundle
│   │   └── components/
│   │       └── Drawer.svelte                   #   slide-in overlay (animation, ESC, scroll lock)
│   └── Service/                                # Service-locator factories only (services live elsewhere)
│       ├── SearchControllerFactory.php
│       ├── TypesenseClientFactory.php          # Admin client (reads Docker secret)
│       ├── TypesenseClientLazy.php             # static helper: container → Closure(): TypesenseClient
│       ├── BlockLayout/IwacSearchBlockFactory.php
│       ├── Controller/MaintenanceControllerFactory.php
│       ├── Indexer/{IncrementalIndexerFactory,ItemEventListenerFactory}.php
│       └── Search/InitialResponseRendererFactory.php
├── cli/
│   └── reindex.php                             # `discovery:reindex` entry point
├── data/
│   ├── schema.yaml                             # Content collection (source of truth)
│   ├── schema-index.yaml                       # Entity (iwac_index) collection
│   ├── stopwords-fr.json                       # French stopword set (loaded as fr_default)
│   └── newspaper-countries.json                # Newspaper → country map (derives country_ss)
├── view/
│   ├── iwac-search/search/{index,everything}.phtml
│   ├── iwac-search/admin/maintenance/index.phtml      # Admin maintenance page
│   ├── common/iwac-search-mount.phtml                 # Shared Svelte mount partial (one source of truth)
│   └── common/block-layout/iwac-search-block.phtml
├── asset/
│   ├── css/iwac-search.css                     # Block container + skeleton (consumes IWAC-theme tokens)
│   └── dist/                                   # Compiled Svelte bundles (committed; CI rebuilds on PR)
│       ├── iwac-search.js                      #   public client (~35 KB gzipped IIFE)
│       ├── iwac-search.css                     #   public component styles
│       ├── iwac-search-header.js               #   header typeahead (~12 KB gzipped IIFE)
│       └── iwac-search-header.css              #   header dropdown styles
├── .github/
│   ├── dependabot.yml                          # weekly grouped updates: npm + composer + actions
│   └── workflows/ci.yml                        # lint + svelte-check + build + PHP syntax
├── docs/
│   └── data-sources.md                         # Why the indexer reads Omeka MySQL directly
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
| `topics_ss`          | Subjects (controlled vocabulary — `fabio:AuthorityFile` authority items)                              |
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

### Accent-insensitive search (v3)

The searchable text fields carry **no `locale: fr`**. With `locale: fr`
Typesense preserves diacritics (ICU tokenization) and an unaccented query
only matches through typo tolerance — measured on the live v2 index,
`Cote d'Ivoire` found 2 documents where `Côte d'Ivoire` found 397, and
there is no folding option for non-`en` locales
([typesense#2093](https://github.com/typesense/typesense/issues/2093),
[typesense#2354](https://github.com/typesense/typesense/issues/2354)).
The default pipeline folds European diacritics at both index and query
time, so `cote` = `Côte` and `evenement` = `événement` — while highlights
still display the original accented text. The `fr_default` stopword set is
uploaded with `locale: en` for the same reason (its tokens must fold the
same way). This is an index-time change, hence the `iwac_v2` → `iwac_v3`
collection bump.

### Stemming

The prose fields (`title_txt`, `alt_title_txt`, `ocr_text`, `abstract`)
carry `stem: true`, folding word-forms of the same root: a query for
`musulman` also matches `musulmans` / `musulmane`, `islamique` matches
`islamiques`, and so on. (Since v3 the stemmer is the default Snowball
English one — locale-free fields are the price of accent folding — which
still handles these plural/feminine endings; accent folding itself now
unifies the `événement`/`évènement`-style variants the French stemmer
never covered.) Stemming is applied to the keyword inverted index only —
the embedding model still receives the raw field text, so semantic recall
is unaffected. `entity_aliases_txt` is deliberately **not** stemmed (it
holds proper nouns and acronyms a stemmer would mangle).

### Result diversification (Typesense 30.2 MMR)

The standalone `/search` diversifies results on a text query using
Maximum Marginal Relevance: near-duplicate syndicated articles (the same
wire story reprinted across outlets and dates) get pushed apart so one
story can't fill the first page. It's driven by the `iwac_diversity`
global curation set — a tag-only rule whose `vector_distance` similarity
metric runs over the same `embedding` field semantic search uses —
created idempotently during the bulk reindex (`CurationSync`) and linked
to the collection via `curation_sets` in `schema.yaml`. The client
activates it per-search with `curation_tags: diversify` + a
`diversity_lambda` of 0.7 (mostly relevance, gentle dedup), and only when
a query is present — browse mode (`q=*`) stays in strict date order.
Curated `/browse/{slug}` pages and page blocks omit the tag, so they keep
raw relevance order.

### Search scopes (page-block presets)

A page block's **Scope** dropdown picks a ready-made scope from
`src/Search/PresetCatalog.php` — the whole corpus, one country, the
references subset, or the entity index — or **Custom…** for a raw
content-collection `filter_by`. Each preset drives the collection, the
locked filter (baked into the scoped key, so enforced server-side), the
facet set, and the default sort. Compose discovery pages by dropping a
block onto any Omeka page alongside your own text/HTML blocks.

| Scope        | Collection           | Locked filter                                   |
| ------------ | -------------------- | ----------------------------------------------- |
| All content  | `iwac_current`       | — (`is_public:=true` only)                      |
| Bénin … Togo | `iwac_current`       | ``country_ss:=`Bénin` && type_s:!=reference`` … |
| References   | `iwac_current`       | `type_s:=reference`                             |
| Entity index | `iwac_index_current` | — (entity cards, frequency)                     |

Country scopes exclude references since v3.2: a country page surfaces the
primary sources; the bibliography lives in its own References scope.

The earlier `iwac_browse_config` table + admin CRUD UI were retired. Old
`/browse/{slug}` links still work: `SearchController::browseAction`
302-redirects them to the equivalent `/search?f.…` (or, for the index,
`/search/everything?tab=entities`). The `upgrade()` hook drops the table.

## Running the bulk reindex

```bash
# Inside the Omeka php container (after the module is installed and
# composer deps are available)
docker compose exec php php /var/www/html/modules/IwacSearch/cli/reindex.php
```

Builds a versioned collection (`iwac_v3_<UTC timestamp>`), reads content
directly from the Omeka MySQL database, batch-imports into Typesense, then
atomic-swaps the `iwac_current` alias. Live search keeps serving the previous
collection uninterrupted until the swap completes — a failed reindex
never affects production.

## Building the Svelte clients

Two Vite builds emit two IIFE bundles side by side:

| Bundle                        | Source                 | Mounted on                                   |
| ----------------------------- | ---------------------- | -------------------------------------------- |
| `iwac-search.{js,css}`        | `src/svelte/`          | `/search`, `/search/everything`, page blocks |
| `iwac-search-header.{js,css}` | `src/svelte/header.ts` | theme header search box (every site page)    |

Both compiled bundles (`asset/dist/*`) are committed so production
deployments work with no Node toolchain. To rebuild after changing
Svelte source:

```bash
npm install              # one-time: ~100 packages, ~6 s
npm run build            # builds both bundles sequentially
npm run build:public     # only the public discovery bundle
npm run build:header     # only the header typeahead bundle
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

See the status table at the top for what shipped. Deferred work, deployment
prerequisites, and infrastructure decisions live in [ROADMAP.md](ROADMAP.md);
full milestone history in the IWAC-docker roadmap link above.

## License

GPL-3.0-or-later. The IWAC corpus itself is CC-BY-NC-SA-4.0.
