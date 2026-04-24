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
        ],
    ],

    'controllers' => [
        'factories' => [
            Controller\SearchController::class                => Service\SearchControllerFactory::class,
            Controller\Admin\BrowseConfigController::class    => Service\Controller\BrowseConfigControllerFactory::class,
        ],
    ],

    // Page block — lets editors drop the search surface onto any Site page.
    // Same Svelte bundle as the standalone /search route, different bootstrap
    // config blob per block instance.
    'block_layouts' => [
        'invokables' => [
            'iwacSearch' => Site\BlockLayout\IwacSearchBlock::class,
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
        ],
    ],

    'view_manager' => [
        'template_path_stack' => [
            __DIR__ . '/../view',
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
                // Child routes stay off the visible sidebar but still let
                // Omeka highlight the parent when a sub-page is active.
                'pages' => [
                    ['route' => 'admin/iwac-search/browse-config-api-list', 'visible' => false],
                    ['route' => 'admin/iwac-search/browse-config-api-item', 'visible' => false],
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
