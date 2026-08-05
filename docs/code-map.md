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
│   │   ├── ReindexOrchestrator.php             #   wires the full bulk run (CLI + job share it)
│   │   ├── IncrementalIndexer.php              #   live upserts/deletes on api.*.post events
│   │   └── ItemEventListener.php               #   event handler bodies (items, media, item sets)
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
│   │   ├── SearchDefaults.php                  # per-collection query_by / highlights / default facet stack
│   │   ├── InitialResponseRenderer.php         # SSR: PHP→Typesense, inlines first page into bootstrap
│   │   └── TypesenseSearchKeyProvider.php      # mints scoped keys for the browser
│   ├── svelte/                                 # Svelte 5 + TS client source — public bundle
│   │   ├── App.svelte                          #   per-mount root, owns search state
│   │   ├── main.ts                             #   IIFE entry; auto-mounts on every root
│   │   ├── header.ts · header.css              #   site-wide header typeahead bundle (framework-free)
│   │   ├── components/                         #   SearchInput · SuggestDropdown · FacetPanel ·
│   │   │                                       #   FacetGroup · DateRangeSlider · SortSelect ·
│   │   │                                       #   ResultsList · ResultItem · ResultSummary ·
│   │   │                                       #   ResultsEmpty · ResultSkeleton · Pagination ·
│   │   │                                       #   ExportMenu · ViewToggle · MapView · Sparkline ·
│   │   │                                       #   FederatedApp · Icon
│   │   └── lib/                                #   typesense.ts (REST wrapper, scoped-key cache) ·
│   │                                           #   types.ts · urlState.ts · i18n.ts · queryBuilders ·
│   │                                           #   transport · sanitize · suggestions · searchHistory ·
│   │                                           #   filterChips · filterDrawer · viewMode · export ·
│   │                                           #   sparkline · thumbnail · maplibreLoader
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
│   ├── check-schema-drift.js                   # CI gate: catalog ↔ schemas ↔ i18n labels
│   └── check-theme-tokens.js                   # CI gate: CSS custom-property fallbacks ↔ tokens.json
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
