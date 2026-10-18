<?php
declare(strict_types=1);

namespace IwacSearch\Service\ViewHelper;

use IwacSearch\IwacInstance;
use IwacSearch\Log\LoggerResolver;
use IwacSearch\Search\InitialResponseRenderer;
use IwacSearch\View\Helper\IwacItemSetSearch;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

/**
 * The item-set surface needs the SSR renderer, so it is a factory, not an
 * invokable like the module's other helpers. The renderer is itself lazy
 * (TypesenseClientLazy), so building the helper never touches Typesense —
 * only an item set's page calling it does.
 */
final class IwacItemSetSearchFactory implements FactoryInterface
{
    /**
     * @param  mixed $requestedName
     * @param  array<string, mixed>|null $options
     */
    public function __invoke(
        ContainerInterface $container,
        $requestedName,
        ?array $options = null
    ): IwacItemSetSearch {
        return new IwacItemSetSearch(
            renderer:     $container->get(InitialResponseRenderer::class),
            logger:       LoggerResolver::fromContainer($container),
            contentAlias: IwacInstance::CONTENT_ALIAS,
            indexAlias:   IwacInstance::INDEX_ALIAS,
        );
    }
}
