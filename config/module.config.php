<?php
declare(strict_types=1);

/**
 * IwacSearch module configuration.
 *
 * Wires routes, controllers, block layouts, services, and view templates
 * for the public discovery surface.
 */

namespace IwacSearch;

use Typesense\Client as TypesenseClient;

return [
    'service_manager' => [
        'factories' => [
            // Long-lived Typesense client. Reads the admin key from the
            // /run/secrets/typesense_api_key Docker secret. Never read
            // from app config or env vars in production.
            TypesenseClient::class => Service\TypesenseClientFactory::class,
            // Server-side pre-renderer — calls Typesense during PHP dispatch
            // so the first page of results is inlined into the bootstrap JSON
            // and the Svelte client paints without any mount-time fetch.
            Search\InitialResponseRenderer::class => Service\Search\InitialResponseRendererFactory::class,
            // Applies journaled changes (upserts, deletes, visibility and
            // authority updates) to Typesense. Resolved by the DrainChanges
            // job, never inside the API write events themselves.
            Indexer\IncrementalIndexer::class => Service\Indexer\IncrementalIndexerFactory::class,
            // Event-handler class that owns the api.execute.pre/post bodies:
            // it journals affected IDs under the mutation gate and schedules
            // DrainChanges. Module.php resolves it lazily, on writes only.
            Indexer\ItemEventListener::class => Service\Indexer\ItemEventListenerFactory::class,
        ],
    ],

    'controllers' => [
        'factories' => [
            Controller\SearchController::class            => Service\SearchControllerFactory::class,
            Controller\LegacySearchController::class      => Service\Controller\LegacySearchControllerFactory::class,
            Controller\Admin\MaintenanceController::class => Service\Controller\MaintenanceControllerFactory::class,
        ],
    ],

    // Maintenance form (CSRF-only) used by the admin Maintenance page.
    // Stateless, no constructor deps — `invokables` is enough.
    'form_elements' => [
        'invokables' => [
            Form\MaintenanceForm::class => Form\MaintenanceForm::class,
        ],
    ],

    // Page block — lets editors drop the search surface onto any Site page.
    // Same Svelte bundle as the standalone /search route, different bootstrap
    // config blob per block instance. Factory (not invokable) so the block
    // can pull the server-side renderer that inlines first-page results.
    'block_layouts' => [
        'factories' => [
            'iwacSearch' => Service\BlockLayout\IwacSearchBlockFactory::class,
        ],
    ],

    'router' => [
        'routes' => [
            // Admin Maintenance page (reindex / stopwords / live index status),
            // nested under /admin/iwac-search so the AdminModule nav entry has a
            // single parent to target.
            'admin' => [
                'child_routes' => [
                    'iwac-search' => [
                        'type'    => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route'    => '/iwac-search',
                            'defaults' => [
                                '__NAMESPACE__' => 'IwacSearch\Controller\Admin',
                                'controller'    => Controller\Admin\MaintenanceController::class,
                                'action'        => 'index',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            // Maintenance HTML page: /admin/iwac-search/maintenance
                            'maintenance' => [
                                'type'    => \Laminas\Router\Http\Literal::class,
                                'options' => [
                                    'route'    => '/maintenance',
                                    'defaults' => [
                                        'controller' => Controller\Admin\MaintenanceController::class,
                                        'action'     => 'index',
                                    ],
                                ],
                            ],
                            // POST handler: /admin/iwac-search/maintenance/reindex
                            'maintenance-retry-changes' => [
                                'type' => \Laminas\Router\Http\Literal::class,
                                'options' => ['route' => '/maintenance/retry-changes', 'defaults' => [
                                    'controller' => Controller\Admin\MaintenanceController::class,
                                    'action' => 'retryChanges',
                                ]],
                            ],
                            'maintenance-prune-collections' => [
                                'type' => \Laminas\Router\Http\Literal::class,
                                'options' => ['route' => '/maintenance/prune-collections', 'defaults' => [
                                    'controller' => Controller\Admin\MaintenanceController::class,
                                    'action' => 'pruneCollections',
                                ]],
                            ],
                            'maintenance-reindex' => [
                                'type'    => \Laminas\Router\Http\Literal::class,
                                'options' => [
                                    'route'    => '/maintenance/reindex',
                                    'defaults' => [
                                        'controller' => Controller\Admin\MaintenanceController::class,
                                        'action'     => 'reindex',
                                    ],
                                ],
                            ],
                            // POST handler: /admin/iwac-search/maintenance/sync-stopwords
                            'maintenance-sync-stopwords' => [
                                'type'    => \Laminas\Router\Http\Literal::class,
                                'options' => [
                                    'route'    => '/maintenance/sync-stopwords',
                                    'defaults' => [
                                        'controller' => Controller\Admin\MaintenanceController::class,
                                        'action'     => 'syncStopwords',
                                    ],
                                ],
                            ],
                            // POST handler: /admin/iwac-search/maintenance/sync-synonyms
                            'maintenance-sync-synonyms' => [
                                'type'    => \Laminas\Router\Http\Literal::class,
                                'options' => [
                                    'route'    => '/maintenance/sync-synonyms',
                                    'defaults' => [
                                        'controller' => Controller\Admin\MaintenanceController::class,
                                        'action'     => 'syncSynonyms',
                                    ],
                                ],
                            ],
                            // POST handler: /admin/iwac-search/maintenance/provision-analytics
                            'maintenance-provision-analytics' => [
                                'type'    => \Laminas\Router\Http\Literal::class,
                                'options' => [
                                    'route'    => '/maintenance/provision-analytics',
                                    'defaults' => [
                                        'controller' => Controller\Admin\MaintenanceController::class,
                                        'action'     => 'provisionAnalytics',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],

            // The public surfaces — /search, /search/everything,
            // /discovery/token and the legacy /browse redirects — on the
            // site-less root, then under every language site with the French
            // paths the French site links to (/recherche, /recherche/tout,
            // /parcourir) and the handoffs from Omeka's own advanced search.
            // Each route is written once, in Site\PublicRoutes.
            ...Site\PublicRoutes::root(),
            'site' => [
                'child_routes' => Site\PublicRoutes::site(),
            ],
        ],
    ],

    'view_manager' => [
        'template_path_stack' => [
            __DIR__ . '/../view',
        ],
    ],

    // The PHP side's own strings (the block's landmark name, its noscript
    // fallback) go through Omeka's translator; without a catalogue they were
    // English on the French site. language/fr.mo is compiled from fr.po by
    // `npm run build:mo` and committed — the release zip is `git archive`d.
    // Same registration as IwacVisualizations.
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],

    // Module-scoped view helpers. Used by every mount-point PHTML to
    // serialise the bootstrap blob with one consistent set of JSON flags
    // (security-relevant — see View\Helper\IwacBootstrapJson docblock).
    'view_helpers' => [
        'invokables' => [
            'iwacBootstrapJson' => View\Helper\IwacBootstrapJson::class,
            // Resolves 'fr' | 'en' from the current site for UI strings +
            // the /parcourir vs /browse route choice.
            'iwacLocale'        => View\Helper\IwacLocale::class,
            // Builds the locale-correct federated search URL for the current
            // site (FR → /recherche/tout, EN → /search/everything). Called by
            // the theme and the legacy-search redirect controller.
            'iwacSearchUrl'     => View\Helper\IwacSearchUrl::class,
        ],
        'factories' => [
            // The Typesense surface for an item set's page, or '' to keep the
            // theme's ledger (IWAC-theme's item-set template calls it,
            // guarded on its presence). Takes the SSR renderer.
            'iwacItemSetSearch' => Service\ViewHelper\IwacItemSetSearchFactory::class,
        ],
    ],

    // Sidebar entry under Omeka's "Modules" admin menu.
    'navigation' => [
        'AdminModule' => [
            [
                'label'    => 'IWAC Search', // @translate
                'route'    => 'admin/iwac-search/maintenance',
                'resource' => Controller\Admin\MaintenanceController::class,
                'class'    => 'o-icon-search',
                'pages' => [
                    // Hidden child routes — let Omeka highlight the parent
                    // entry when one of these sub-pages is active.
                    ['route' => 'admin/iwac-search/maintenance-reindex',             'visible' => false],
                    ['route' => 'admin/iwac-search/maintenance-sync-stopwords',      'visible' => false],
                    ['route' => 'admin/iwac-search/maintenance-sync-synonyms',       'visible' => false],
                    ['route' => 'admin/iwac-search/maintenance-provision-analytics', 'visible' => false],
                    ['route' => 'admin/iwac-search/maintenance-retry-changes',       'visible' => false],
                    ['route' => 'admin/iwac-search/maintenance-prune-collections',   'visible' => false],
                ],
            ],
        ],
    ],

    // Module-level config — read by the service factories (Typesense
    // connection, SSR cache, scoped-key TTL and scope), never by the block.
    'iwac_search' => [
        'typesense' => [
            'host'             => 'typesense',
            'port'             => 8108,
            'protocol'         => 'http',
            'api_key_file'     => '/run/secrets/typesense_api_key',
            // The live aliases are IwacInstance::CONTENT_ALIAS / INDEX_ALIAS,
            // shared by search, rebuild, drain and the public key scope.
        ],
        // Server-rendered first page, cached briefly in APCu (no-op when the
        // extension is absent). Safe to share between visitors because every
        // snapshot is public-only by construction — see Search\SnapshotCache.
        // Recorded writes, drained batches and promotions change the cache
        // key; the TTL is the backstop for changes the journal cannot see.
        // Set to 0 to disable.
        'ssr_cache' => [
            'ttl_seconds' => 30,
        ],
        'public_search_key' => [
            // TTL of the public scoped key. The key's SECURITY constraints
            // (filter_by is_public:=true + Search\PublicSearchPolicy's
            // exclude_fields ocr_text,toc_txt,embedding) are NOT
            // configurable — they are hardcoded in PublicSearchPolicy and
            // TypesenseSearchKeyProvider::mintPublicScopedKey(). Loosening
            // them there requires sign-off.
            'expires_at_seconds' => 3600,

            // Anchored regexes authorize only live aliases, never retained snapshots.
            'collections' => Search\TypesenseSearchKeyProvider::DEFAULT_COLLECTION_SCOPE,
        ],
    ],
];
