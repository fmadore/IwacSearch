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
    public function __invoke(
        ContainerInterface $container,
        $requestedName,
        ?array $options = null
    ): IwacSearchBlock {
        return new IwacSearchBlock(
            initialRenderer: $container->get(InitialResponseRenderer::class)
        );
    }
}
