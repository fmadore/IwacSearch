<?php
declare(strict_types=1);

namespace IwacSearch\Service\BlockLayout;

use IwacSearch\Search\InitialResponseRenderer;
use IwacSearch\Site\BlockLayout\IwacSearchBlock;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

/**
 * Switches the block from invokable → factory so it can receive the
 * server-side renderer. The renderer inlines the first page of results
 * into the block's bootstrap JSON, so a page block with locked filters
 * ("latest from Sidwaya", "Burkina Faso articles") paints items on first
 * frame — no mount-time fetch.
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

        return new IwacSearchBlock(
            initialRenderer: $container->get(InitialResponseRenderer::class),
            // Same purifier Omeka core's Html block uses — onHydrate() runs
            // editor-supplied intro_html through it before persisting.
            htmlPurifier:    $container->get('Omeka\HtmlPurifier'),
            contentAlias:    $typesense['collection_alias'] ?? 'iwac_current',
            indexAlias:      $typesense['index_collection_alias'] ?? 'iwac_index_current',
        );
    }
}
