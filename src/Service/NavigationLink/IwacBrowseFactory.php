<?php
declare(strict_types=1);

namespace IwacSearch\Service\NavigationLink;

use IwacSearch\Browse\BrowseConfigRepository;
use IwacSearch\Site\NavigationLink\IwacBrowse;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

/**
 * Builds the IwacBrowse navigation link with its repository dependency.
 *
 * Registered as a `factories` entry under `navigation_links` in
 * config/module.config.php — the alternative `invokables` entry can't
 * inject the repository, and we need it for getLabel/toZend.
 */
class IwacBrowseFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): IwacBrowse
    {
        return new IwacBrowse($container->get(BrowseConfigRepository::class));
    }
}
