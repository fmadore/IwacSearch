<?php
declare(strict_types=1);

namespace IwacSearch\Service\Controller;

use IwacSearch\Controller\LegacySearchController;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Laminas\View\Renderer\PhpRenderer;
use Psr\Container\ContainerInterface;

final class LegacySearchControllerFactory implements FactoryInterface
{
    /**
     * @param mixed $requestedName
     * @param array<string, mixed>|null $options
     */
    public function __invoke(
        ContainerInterface $container,
        $requestedName,
        ?array $options = null
    ): LegacySearchController {
        $view = $container->get('ViewRenderer');
        if (!$view instanceof PhpRenderer) {
            throw new \RuntimeException('ViewRenderer must be a PhpRenderer.');
        }

        return new LegacySearchController($view);
    }
}
