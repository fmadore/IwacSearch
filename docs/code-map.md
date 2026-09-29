# Code map

Annotated tree of the module. The README keeps a short orientation table;
this is the full picture.

```
IwacSearch/
├── Module.php                                  # Lifecycle + asset injection (M4: event listeners)
├── config/
│   ├── module.ini                              # Omeka module manifest
│   └── module.config.php                       # Routes, controllers, blocks, services
├── src/
│   ├── Controller/
│   │   ├── SearchController.php                # /search, /search/everything, /discovery/token, /browse redirect
│   │   └── Admin/MaintenanceController.php     # /admin/iwac-search/maintenance (reindex, syncs, status)
│   ├── Asset/SvelteAssets.php                  # ONE place that knows the compiled bundle's file set
│   ├── Indexer/                                # Bulk + incremental indexing (reads Omeka MySQL)
│   │   ├── SchemaLoader.php                    #   reads data/schema*.yaml, versions collection names
│   │   ├── OmekaSourceReader.php               #   DBAL: keyset item stream + value loading
│   │   ├── EntityAuthority.php                 #   entity lookup from classes 94/9/96/54/244
│   │   ├── EntityOccurrences.php               #   per-entity metric accumulator (histogram)
│   │   ├── CountryResolver.php                 #   derives country_ss (newspaper / item-set)
│   │   ├── StopwordsSync.php                   #   PUTs fr_default to Typesense
│   │   ├── CurationSync.php                    #   PUTs iwac_diversity curation set
│   │   ├── SynonymsSync.php                    #   PUTs iwac_synonyms global synonym set
│   │   ├── AnalyticsSync.php                   #   provisions search-analytics rules (non-fatal)
│   │   ├── Mapper/                             #   one mapper per content subset (by class)
│   │   │   ├── MapperInterface.php · AbstractMapper.php
│   │   │   ├── ArticleMapper.php · PublicationMapper.php · DocumentMapper.php
│   │   │   ├── AudiovisualMapper.php · PhotographMapper.php · ReferenceMapper.php
│   │   │   ├── IndexEntityMapper.php           #   entity (index) collection docs
│   │   │   └── MapperRegistry.php              #   MapperRegistry::default() = ONE registration point
│   │   ├── CollectionOps.php                   #   shared create/import/guarded-promote lifecycle
│   │   ├── Reindexer.php                       #   content collection bulk pass
│   │   ├── IndexReindexer.php                  #   entity (iwac_index) collection bulk pass
│   │   ├── ReindexOrchestrator.php             #   wires the full bulk run (CLI + job share it); phased cutover
│   │   ├── IncrementalIndexer.php              #   applies journaled changes; create() = the one incremental wiring
│   │   ├── ItemEventListener.php               #   api.execute.pre/post bodies: journal IDs, schedule a drain
│   │   ├── WriteGate.php · AdvisoryLock.php    #   mutation gate held per write request (survives failed writes)
│   │   ├── DatabaseLock.php                    #   MySQL GET_LOCK implementation of AdvisoryLock
│   │   ├── ChangeJournal.php · ChangeDrainer.php #  durable ID journal + bounded consumer
│   │   ├── DrainJobHistory.php                 #   is a drain queued/running? prune old drain-job rows
│   │   └── CollectionRetention.php             #   old generations, journal rows, drain-job history
│   ├── Browse/FacetCatalog.php                 # facetable fields + content/entity sort sets
│   ├── Site/BlockLayout/IwacSearchBlock.php    # Page block — drop into any Site page
│   ├── Job/                                    # Omeka background jobs (admin maintenance buttons)
│   │   └── BulkReindex · SyncStopwords · SyncSynonyms · ProvisionAnalytics
│   ├── Form/MaintenanceForm.php                # CSRF-bearing POST forms for the maintenance page
│   ├── Log/
│   │   ├── OmekaPsrLogger.php                  # PSR-3 ↔ Laminas\Log adapter (psr/log 3.x-safe)
│   │   └── LoggerResolver.php                  # static helper: container → wrapped PSR-3 logger
│   ├── Util/ExceptionMessage.php               # flattens exception chains for logging
│   ├── View/Helper/                            # IwacBootstrapJson · IwacLocale · IwacSearchUrl
│   ├── Search/
│   │   ├── PresetCatalog.php · Preset.php      # page-block scopes (all / country / references / entity index)
│   │   ├── ScopeFilters.php                    # page-block value pickers → filter_by (multi type/country/…)
│   │   ├── FacetValueLookup.php                #   live facet values for those pickers (degrades to null)
│   │   ├── SearchDefaults.php                  # per-collection query_by / highlights / default facet stack
│   │   ├── InitialResponseRenderer.php         # SSR: PHP→Typesense, inlines first page into bootstrap
│   │   ├── SearchStateQuery.php                # does the raw URL carry client state? (skip SSR)
│   │   ├── TypesenseSearchKeyProvider.php      # mints scoped keys for the browser
│   │   └── ValidatedKeyMemo.php                # APCu memo of mounted-secret scope validation
│   ├── svelte/                                 # Svelte 5 + TS client source — public bundle
│   │   ├── App.svelte                          #   per-mount root: owns search state, wires the rest
│   │   ├── main.ts                             #   IIFE entry; auto-mounts on every root
│   │   ├── header.ts · header.css              #   site-wide header typeahead bundle (framework-free)
│   │   ├── components/                         #   SearchInput · SuggestDropdown · FacetPanel ·
│   │   │                                       #   FacetGroup · DateRangeSlider · SortSelect ·
│   │   │                                       #   ResultsToolbar · ResultsList · ResultItem ·
│   │   │                                       #   ResultSummary · ResultsEmpty · ResultSkeleton ·
│   │   │                                       #   SemanticFallback · DidYouMean · Pagination ·
│   │   │                                       #   ExportMenu · ViewToggle · MapView · Sparkline ·
│   │   │                                       #   FederatedApp · Icon
│   │   └── lib/                                #   typesense.ts (REST wrapper, scoped-key cache) ·
│   │                                           #   searchResults (what the state fetches: results,
│   │                                           #   histogram, did-you-mean, map set) · initialState ·
│   │                                           #   types.ts · urlState.ts · i18n.ts · announce ·
│   │                                           #   queryBuilders · queryPolicy · transport · sanitize ·
│   │                                           #   suggestions · searchHistory · filterChips ·
│   │                                           #   filterState · filterDrawer · typeahead · viewMode ·
│   │                                           #   clipboard · export · sparkline · thumbnail ·
│   │                                           #   maplibreLoader
│   ├── svelte-shared/components/Drawer.svelte  # slide-in overlay (animation, ESC, scroll lock)
│   └── Service/                                # Service-locator factories only (services live elsewhere)
│       ├── SearchControllerFactory.php
│       ├── TypesenseClientFactory.php          # Admin client (reads Docker secret)
│       ├── TypesenseClientLazy.php             # static helper: container → memoizing Closure
│       ├── BlockLayout/IwacSearchBlockFactory.php
│       ├── Controller/MaintenanceControllerFactory.php
│       ├── Indexer/{IncrementalIndexerFactory,ItemEventListenerFactory}.php
│       └── Search/InitialResponseRendererFactory.php
├── cli/
│   ├── bootstrap.php                           # shared CLI bootstrap (autoload, logger, client)
│   ├── reindex.php                             # `discovery:reindex` entry point
│   ├── stopwords-sync.php                      # fr_default set only, no reindex
│   └── synonyms-sync.php                       # iwac_synonyms set only, no reindex
├── data/
│   ├── schema.yaml                             # Content collection (source of truth)
│   ├── schema-index.yaml                       # Entity (iwac_index) collection
│   ├── stopwords-fr.json                       # French stopword set (loaded as fr_default)
│   ├── synonyms-fr.json                        # Arabic-transliteration synonym groups
│   └── newspaper-countries.json                # Newspaper → country map (derives country_ss)
├── scripts/
│   ├── check-schema-drift.js                   # CI gate: catalog ↔ schemas ↔ i18n labels, PHP ↔ TS contracts
│   ├── check-docblocks.js                      # CI gate: no stacked PHP docblocks
│   ├── check-i18n.js                           # CI gate: every locale table has the same keys in fr/en
│   ├── check-bundle-size.js                    # build gate: gzipped budgets; no app strings in the header bundle
│   ├── check-theme-tokens.js                   # CI gate: runs the theme's token guard over src/ + asset/css/
│   └── theme-token-guard.cjs                   # SYNCED from IWAC-theme (npm run sync:tokens) — never edit here
├── view/
│   ├── iwac-search/search/{index,everything}.phtml
│   ├── iwac-search/admin/maintenance/index.phtml      # Admin maintenance page
│   ├── common/iwac-search-mount.phtml                 # Shared Svelte mount partial (one source of truth)
│   ├── common/iwac-federated-mount.phtml
│   └── common/block-layout/iwac-search-block.phtml
├── asset/
│   ├── css/iwac-search.css                     # Block container + skeleton (consumes IWAC-theme tokens)
│   └── dist/                                   # Compiled bundles (committed; CI diffs them vs source)
│       ├── iwac-search.{js,css}                #   public client
│       └── iwac-search-header.{js,css}         #   site-wide header typeahead
├── .github/
│   ├── dependabot.yml                          # weekly grouped updates: npm + composer + actions
│   └── workflows/ci.yml                        # lint + svelte-check + build + dist diff + PHP 8.2/8.4 lint
├── docs/
│   ├── data-sources.md                         # Why the indexer reads Omeka MySQL directly
│   └── engineering-roadmap.md                  # Refactoring / hardening roadmap + deferred items
├── tokens.json                                 # Generated snapshot of IWAC-theme's design tokens
├── package.json                                # Vite 8 + Svelte 5 + TypeScript 6 toolchain
├── vite.config.ts
├── tsconfig.json
├── eslint.config.js                            # flat config (ESLint 10)
└── .prettierrc.json
```

## Architectural note

The `src/Indexer/` triad (loader → mapper → reindexer) and the future
`src/Querier/` directory mirror Daniel-KM's
[AdvancedSearch module](https://github.com/Daniel-KM/Omeka-S-module-AdvancedSearch),
the dominant Omeka search-module convention. We're single-backend
(Typesense), so the EngineAdapter abstraction is implicit — but the
naming stays consistent so editors who know AdvancedSearch can navigate
this codebase without surprise.
