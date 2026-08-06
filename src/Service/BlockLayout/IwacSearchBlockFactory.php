<?php
declare(strict_types=1);

namespace IwacSearch\Service\BlockLayout;

use IwacSearch\Log\LoggerResolver;
use IwacSearch\Search\FacetValueLookup;
use IwacSearch\Search\InitialResponseRenderer;
use IwacSearch\Service\TypesenseClientLazy;
use IwacSearch\Site\BlockLayout\IwacSearchBlock;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

/**
 * Switches the block from invokable → factory so it can receive the
 * server-side renderer. The renderer inlines the first page of results
 * into the block's bootstrap JSON, so a page block with locked filters
 * ("latest from Sidwaya", "Burkina Faso articles") paints items on first
 * frame — no mount-time fetch.
 *
 * It also receives the facet-value lookup that fills the admin form's value
 * pickers. Both take the LAZY client factory rather than a client, so
 * constructing the block never touches Typesense — the public render path
 * must not pay for a connection the admin form is the only user of.
 */
final class IwacSearchBlockFactory implements FactoryInterface
{
    /**
     * @param  mixed $requestedName
     * @param  array<string, mixed>|null $options
     */
    public function __invoke(
        ContainerInterface $container,
        $requestedName,
        ?array $options = null
    ): IwacSearchBlock {
        // Collection aliases drive the preset → collection switch (content
        // vs the entity index). Read from module config so a future alias
        // rename follows the same single source the controller uses.
        $typesense = $container->get('Config')['iwac_search']['typesense'] ?? [];
        $contentAlias = $typesense['collection_alias'] ?? 'iwac_current';

        return new IwacSearchBlock(
            initialRenderer: $container->get(InitialResponseRenderer::class),
            // Same purifier Omeka core's Html block uses — onHydrate() runs
            // editor-supplied intro_html through it before persisting.
            htmlPurifier:    $container->get('Omeka\HtmlPurifier'),
            contentAlias:    $contentAlias,
            indexAlias:      $typesense['index_collection_alias'] ?? 'iwac_index_current',
            facetValues:     new FacetValueLookup(
                clientFactory: TypesenseClientLazy::fromContainer($container),
                contentAlias:  $contentAlias,
                logger:        LoggerResolver::fromContainer($container),
            ),
        );
    }
}
