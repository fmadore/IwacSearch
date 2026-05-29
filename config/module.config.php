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
            // Browse-config repository, talks to Omeka's shared DBAL connection.
            Browse\BrowseConfigRepository::class => Service\BrowseConfigRepositoryFactory::class,
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
            Controller\SearchController::class                => Service\SearchControllerFactory::class,
            Controller\Admin\BrowseConfigController::class    => Service\Controller\BrowseConfigControllerFactory::class,
            Controller\Admin\MaintenanceController::class     => Service\Controller\MaintenanceControllerFactory::class,
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
            // Admin CRUD for curated browse configs (M3.5). One HTML shell +
            // two JSON endpoints. All three nest under /admin/iwac-search
            // so the AdminModule nav entry has a single parent to target.
            'admin' => [
                'child_routes' => [
                    'iwac-search' => [
                        'type'    => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route'    => '/iwac-search',
                            'defaults' => [
                                '__NAMESPACE__' => 'IwacSearch\Controller\Admin',
                                'controller'    => Controller\Admin\BrowseConfigController::class,
                                'action'        => 'browse',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            // HTML shell: /admin/iwac-search/browse-config
                            'browse-config' => [
                                'type'    => \Laminas\Router\Http\Literal::class,
                                'options' => [
                                    'route'    => '/browse-config',
                                    'defaults' => ['action' => 'browse'],
                                ],
                            ],
                            // JSON collection endpoint — GET (list) + POST (create)
                            'browse-config-api-list' => [
                                'type'    => \Laminas\Router\Http\Literal::class,
                                'options' => [
                                    'route'    => '/browse-config/api',
                                    'defaults' => ['action' => 'apiList'],
                                ],
                            ],
                            // JSON item endpoint — GET / PATCH / DELETE
                            'browse-config-api-item' => [
                                'type'    => \Laminas\Router\Http\Segment::class,
                                'options' => [
                                    'route'       => '/browse-config/api/:id',
                                    'constraints' => ['id' => '\d+'],
                                    'defaults'    => ['action' => 'apiItem'],
                                ],
                            ],
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

            // Curated browse pages (M3). Slug resolves to a row in
            // iwac_browse_config; the controller hydrates the same Svelte
            // shell with locked filters + prominent facets.
            //
            // Kept at the global root for back-compat: URLs like
            // https://islam.zmo.de/browse/benin remain valid for any
            // bookmark / external link minted before site-scoped routes
            // existed.
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

            // French-language alias of /browse. Same controller + action;
            // the slug resolves against the same iwac_browse_config rows.
            // The French site links here (Parcourir) while the English site
            // uses /browse — see Site\NavigationLink\IwacBrowse::toZend.
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

            // Site-scoped variant of the same route. Nesting under the
            // `site` parent automatically prefixes /s/:site-slug, so the
            // public URL becomes /s/{site-slug}/browse/{slug}. Required
            // for the Site\NavigationLink\IwacBrowse link type — Omeka's
            // navigation helper generates URLs from the route name, and
            // the menu must keep the visitor inside their language site
            // (French /s/afrique_ouest, English /s/westafrica).
            //
            // Same controller, same action, no controller changes — the
            // basePath() calls in the templates pick up the application
            // base path; the site mount has no effect on the global
            // /discovery/token + /search-api/multi_search endpoints.
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
        ],
    ],

    // Site-navigation link types. Adds "IWAC Browse" to the dropdown a
    // site admin sees when editing the site's nav menu, lets them pick
    // one of the curated /browse/{slug} pages, and renders it as a
    // proper site-scoped link. See Site\NavigationLink\IwacBrowse.
    //
    // Registered as `factories` (not `invokables`) because the link
    // type needs the BrowseConfigRepository injected to resolve id →
    // slug at URL-generation time and id → title at label-render time.
    'navigation_links' => [
        'factories' => [
            'iwacBrowse' => Service\NavigationLink\IwacBrowseFactory::class,
        ],
    ],

    // Sidebar entry under Omeka's "Modules" admin menu.
    'navigation' => [
        'AdminModule' => [
            [
                'label'    => 'IWAC Search', // @translate
                'route'    => 'admin/iwac-search/browse-config',
                'resource' => Controller\Admin\BrowseConfigController::class,
                'class'    => 'o-icon-search',
                'pages' => [
                    // Maintenance — visible sub-entry, lets editors trigger
                    // reindex / stopwords-sync without docker exec access.
                    [
                        'label'    => 'Maintenance', // @translate
                        'route'    => 'admin/iwac-search/maintenance',
                        'resource' => Controller\Admin\MaintenanceController::class,
                    ],
                    // Hidden child routes — let Omeka highlight the parent
                    // entry when one of these sub-pages is active.
                    ['route' => 'admin/iwac-search/browse-config-api-list',     'visible' => false],
                    ['route' => 'admin/iwac-search/browse-config-api-item',     'visible' => false],
                    ['route' => 'admin/iwac-search/maintenance-reindex',        'visible' => false],
                    ['route' => 'admin/iwac-search/maintenance-sync-stopwords', 'visible' => false],
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
        ],
        'public_search_key' => [
            // Constraints baked into every public scoped key. See "Security
            // model" in the roadmap. Loosening any of these requires sign-off.
            'filter_by'          => 'is_public:=true',
            'exclude_fields'     => 'ocr_text',
            'expires_at_seconds' => 3600,
        ],
    ],
];
