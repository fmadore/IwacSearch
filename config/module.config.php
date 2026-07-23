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
            // Live sync for is_public toggles + deletes (M4). Hooked into
            // the Omeka item api.update.post / api.delete.post events by
            // Module::attachListeners.
            Indexer\IncrementalIndexer::class => Service\Indexer\IncrementalIndexerFactory::class,
            // Event-handler class that owns the api.*.post bodies. Module.php
            // attaches its methods directly; this keeps lifecycle code in
            // Module.php separate from the listener business logic.
            Indexer\ItemEventListener::class => Service\Indexer\ItemEventListenerFactory::class,
        ],
    ],

    'controllers' => [
        'factories' => [
            Controller\SearchController::class            => Service\SearchControllerFactory::class,
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

            // Public search page — HTML shell + (M1) compiled Svelte bundle.
            'iwac-search' => [
                'type' => \Laminas\Router\Http\Literal::class,
                'options' => [
                    'route'    => '/search',
                    'defaults' => [
                        'controller' => Controller\SearchController::class,
                        'action'     => 'index',
                    ],
                ],
            ],

            // Federated "search everything" page — Content + Entities tabs over
            // both Typesense collections. Literal route, so it never shadows
            // /search above. Global root for back-compat / external links.
            'iwac-search-everything' => [
                'type' => \Laminas\Router\Http\Literal::class,
                'options' => [
                    'route'    => '/search/everything',
                    'defaults' => [
                        'controller' => Controller\SearchController::class,
                        'action'     => 'everything',
                    ],
                ],
            ],

            // Scoped-key endpoint — PHP inspects the Omeka session and mints
            // a short-lived Typesense scoped key (1h, public OR admin variant).
            'iwac-search-token' => [
                'type' => \Laminas\Router\Http\Literal::class,
                'options' => [
                    'route'    => '/discovery/token',
                    'defaults' => [
                        'controller' => Controller\SearchController::class,
                        'action'     => 'token',
                    ],
                ],
            ],

            // Legacy browse routes — kept only to redirect old bookmarks to
            // /search (the curated browse-config system was retired). URLs like
            // https://islam.zmo.de/browse/benin 302 to /search?f.country_ss=…;
            // /browse/index → /search/everything?tab=entities. See
            // SearchController::browseAction.
            'iwac-browse' => [
                'type'    => \Laminas\Router\Http\Segment::class,
                'options' => [
                    'route'       => '/browse[/:slug]',
                    'constraints' => ['slug' => '[a-zA-Z0-9_-]+'],
                    'defaults'    => [
                        'controller' => Controller\SearchController::class,
                        'action'     => 'browse',
                    ],
                ],
            ],

            // French-language alias of /browse — same redirect action.
            'iwac-parcourir' => [
                'type'    => \Laminas\Router\Http\Segment::class,
                'options' => [
                    'route'       => '/parcourir[/:slug]',
                    'constraints' => ['slug' => '[a-zA-Z0-9_-]+'],
                    'defaults'    => [
                        'controller' => Controller\SearchController::class,
                        'action'     => 'browse',
                    ],
                ],
            ],

            // Site-scoped variants of the legacy browse redirect: a bookmarked
            // /s/{site-slug}/browse/{slug} redirects to that site's own /search
            // (preserving the language site). Also holds the site-scoped /search
            // + /search/everything routes the header search form targets.
            'site' => [
                'child_routes' => [
                    'iwac-browse' => [
                        'type'    => \Laminas\Router\Http\Segment::class,
                        'options' => [
                            'route'       => '/browse[/:slug]',
                            'constraints' => ['slug' => '[a-zA-Z0-9_-]+'],
                            'defaults'    => [
                                '__NAMESPACE__' => 'IwacSearch\Controller',
                                'controller'    => Controller\SearchController::class,
                                'action'        => 'browse',
                            ],
                        ],
                    ],
                    // French alias: /s/{site-slug}/parcourir[/:slug]. The
                    // French site's nav links resolve to this route so the
                    // public URL reads /s/afrique_ouest/parcourir/benin.
                    'iwac-parcourir' => [
                        'type'    => \Laminas\Router\Http\Segment::class,
                        'options' => [
                            'route'       => '/parcourir[/:slug]',
                            'constraints' => ['slug' => '[a-zA-Z0-9_-]+'],
                            'defaults'    => [
                                '__NAMESPACE__' => 'IwacSearch\Controller',
                                'controller'    => Controller\SearchController::class,
                                'action'        => 'browse',
                            ],
                        ],
                    ],

                    // Site-scoped search landing page (English sites):
                    // /s/{site-slug}/search. The IWAC-theme header search
                    // form posts here (via the iwacSearchUrl helper) so a
                    // visitor who submits the header box lands on the faceted
                    // Typesense surface inside their own language site. Same
                    // controller/action as the global /search route;
                    // readUrlState() hydrates ?q= + ?f.<field>= deep links
                    // on the client, so no extra controller wiring is needed.
                    'iwac-search' => [
                        'type'    => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route'    => '/search',
                            'defaults' => [
                                '__NAMESPACE__' => 'IwacSearch\Controller',
                                'controller'    => Controller\SearchController::class,
                                'action'        => 'index',
                            ],
                        ],
                    ],
                    // French alias: /s/{site-slug}/recherche. The French site
                    // (afrique_ouest) links here; iwacLocale() maps the slug
                    // to pick /recherche vs /search — mirrors /parcourir vs
                    // /browse.
                    'iwac-recherche' => [
                        'type'    => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route'    => '/recherche',
                            'defaults' => [
                                '__NAMESPACE__' => 'IwacSearch\Controller',
                                'controller'    => Controller\SearchController::class,
                                'action'        => 'index',
                            ],
                        ],
                    ],

                    // Site-scoped federated page (English sites):
                    // /s/{site-slug}/search/everything. The theme header search
                    // form posts here (via iwacSearchUrl) so a visitor lands on
                    // the Content+Entities surface inside their own language site.
                    'iwac-search-everything' => [
                        'type'    => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route'    => '/search/everything',
                            'defaults' => [
                                '__NAMESPACE__' => 'IwacSearch\Controller',
                                'controller'    => Controller\SearchController::class,
                                'action'        => 'everything',
                            ],
                        ],
                    ],
                    // French alias: /s/{site-slug}/recherche/tout. iwacSearchUrl
                    // maps the French site slug to this route — mirrors
                    // /recherche vs /search.
                    'iwac-recherche-tout' => [
                        'type'    => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route'    => '/recherche/tout',
                            'defaults' => [
                                '__NAMESPACE__' => 'IwacSearch\Controller',
                                'controller'    => Controller\SearchController::class,
                                'action'        => 'everything',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'view_manager' => [
        'template_path_stack' => [
            __DIR__ . '/../view',
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
            // Builds the locale-correct search landing URL for the current
            // site (FR → /recherche, EN → /search). Called by the IWAC-theme
            // header search form to set its `action`.
            'iwacSearchUrl'     => View\Helper\IwacSearchUrl::class,
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
                ],
            ],
        ],
    ],

    // Module-level config — read by the controller factory + block render.
    'iwac_search' => [
        'typesense' => [
            'host'             => 'typesense',
            'port'             => 8108,
            'protocol'         => 'http',
            'api_key_file'     => '/run/secrets/typesense_api_key',
            'collection_alias' => 'iwac_current',
            // Second collection: the index/authority entities, built by
            // IndexReindexer and surfaced via the /search/everything Entities tab.
            'index_collection_alias' => 'iwac_index_current',
        ],
        'public_search_key' => [
            // TTL of the public scoped key. The key's SECURITY constraints
            // (filter_by is_public:=true + exclude_fields ocr_text) are NOT
            // configurable — they are hardcoded in
            // TypesenseSearchKeyProvider::mintPublicScopedKey(), the single
            // source of truth. Loosening them there requires sign-off.
            'expires_at_seconds' => 3600,
        ],
    ],
];
