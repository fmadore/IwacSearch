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
            Controller\SearchController::class => Service\SearchControllerFactory::class,
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
