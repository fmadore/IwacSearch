<?php
declare(strict_types=1);

/**
 * IwacSearch module configuration.
 *
 * Wires routes, controllers, view templates, and (later) ACL + nav for the
 * public discovery surface. Intentionally minimal in M0 — only the /search
 * route and a no-op controller serving an HTML shell.
 */

namespace IwacSearch;

return [
    'controllers' => [
        'factories' => [
            Controller\SearchController::class => Service\SearchControllerFactory::class,
        ],
    ],

    'router' => [
        'routes' => [
            // Public search page — HTML shell + (later) compiled Svelte bundle.
            // Sits at the site root, NOT under /s/{site-slug}/, because IWAC
            // serves a single curated discovery surface across both language sites.
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

            // Scoped-key endpoint — PHP inspects the Omeka session and mints a
            // short-lived Typesense scoped key (1h, public OR admin variant).
            // Wired up in M1.
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
            // iwac_browse_config, the controller hydrates the same Svelte
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

    // Module-level config UI (admin → modules → IwacSearch). Wired up in M3.
    'iwac_search' => [
        'typesense' => [
            // Connection target — overridable in admin, but defaults match the
            // companion IWAC-docker stack (typesense container on omeka-backend).
            'host'     => 'typesense',
            'port'     => 8108,
            'protocol' => 'http',
            // Admin API key is read from /run/secrets/typesense_api_key by
            // SearchControllerFactory — never read from this array directly.
            'api_key_file' => '/run/secrets/typesense_api_key',
            'collection_alias' => 'iwac_current',
        ],
        'public_search_key' => [
            // Constraints baked into every public scoped key. See "Security
            // model" in the roadmap. Loosening any of these requires sign-off.
            'filter_by'      => 'is_public:=true',
            'exclude_fields' => 'ocr_text',
            'expires_at_seconds' => 3600,
        ],
    ],
];
