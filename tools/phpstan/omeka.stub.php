<?php

/**
 * Minimal signatures for the Omeka S classes this module extends and consumes.
 *
 * Omeka S is an APPLICATION, not a Packagist library — there is no
 * `composer require omeka/omeka-s` that would give PHPStan the real classes,
 * and pointing `scanDirectories` at a checkout would make the gate depend on
 * a path that doesn't exist in CI. So the ~16 classes the module actually
 * touches are declared here instead. That is the whole of this module's
 * surface against Omeka; anything not listed is something the module doesn't
 * use.
 *
 * Rules for editing:
 *   - Copy signatures from the Omeka S source, don't invent them. A stub
 *     that's WRONG is worse than no stub: it makes PHPStan confidently
 *     approve code that will fatal at runtime.
 *   - Only add what the module references. This file is a map of the
 *     coupling, and it's useful precisely because it's short.
 *   - Loose types (`mixed`, no return type) where Omeka itself is loose —
 *     tightening them here would produce false positives in our code.
 *
 * Verified against Omeka S 4.x (composer.json: omeka_version_constraint ^4.0).
 */

namespace Omeka\Module {
    abstract class AbstractModule implements \Laminas\ModuleManager\Feature\ConfigProviderInterface
    {
        public function getConfig();

        public function init(\Laminas\ModuleManager\ModuleManager $moduleManager);

        public function onBootstrap(\Laminas\Mvc\MvcEvent $event);

        public function attachListeners(\Laminas\EventManager\SharedEventManagerInterface $sharedEventManager);

        public function install(\Laminas\ServiceManager\ServiceLocatorInterface $services);

        public function uninstall(\Laminas\ServiceManager\ServiceLocatorInterface $services);

        public function upgrade($oldVersion, $newVersion, \Laminas\ServiceManager\ServiceLocatorInterface $services);

        public function setServiceLocator(\Laminas\ServiceManager\ServiceLocatorInterface $serviceLocator);

        public function getServiceLocator(): ?\Laminas\ServiceManager\ServiceLocatorInterface;
    }
}

namespace Omeka\Permissions {
    class Acl extends \Laminas\Permissions\Acl\Acl
    {
        public const ROLE_GLOBAL_ADMIN = 'global_admin';
        public const ROLE_SITE_ADMIN = 'site_admin';
        public const ROLE_EDITOR = 'editor';
        public const ROLE_REVIEWER = 'reviewer';
        public const ROLE_AUTHOR = 'author';
        public const ROLE_RESEARCHER = 'researcher';
    }
}

namespace Omeka\Settings {
    interface SettingsInterface
    {
        public function set($id, $value);

        public function get($id, $default = null);

        public function delete($id);
    }
}

namespace Omeka\Api {
    class Response
    {
        public function getContent();

        public function setContent($content);

        public function getTotalResults();
    }
}

namespace Omeka\Api\Representation {
    abstract class AbstractRepresentation
    {
    }

    abstract class AbstractResourceRepresentation extends AbstractRepresentation
    {
        public function id();
    }

    abstract class AbstractResourceEntityRepresentation extends AbstractResourceRepresentation
    {
        public function title();
    }

    class ItemRepresentation extends AbstractResourceEntityRepresentation
    {
    }

    class MediaRepresentation extends AbstractResourceEntityRepresentation
    {
        public function item(): ?ItemRepresentation;
    }

    class SiteRepresentation extends AbstractResourceRepresentation
    {
        public function slug();
    }

    class SitePageRepresentation extends AbstractResourceRepresentation
    {
    }

    class SitePageBlockRepresentation extends AbstractRepresentation
    {
        public function id();

        /** @return array<string, mixed> */
        public function data();
    }
}

namespace Omeka\Entity {
    class Item
    {
    }

    class SitePageBlock
    {
        /** @return array<string, mixed>|null */
        public function getData();

        /** @param array<string, mixed> $data */
        public function setData($data);
    }
}

namespace Omeka\Job {
    abstract class AbstractJob
    {
        /** @var \Omeka\Entity\Job */
        protected $job;

        abstract public function perform(): void;

        public function getServiceLocator(): \Laminas\ServiceManager\ServiceLocatorInterface;

        public function shouldStop(): bool;
    }
}

namespace Omeka\Entity {
    class Job
    {
        public function getId(): ?int;
    }
}

namespace Omeka\Site\BlockLayout {
    abstract class AbstractBlockLayout implements BlockLayoutInterface
    {
        public function prepareForm(\Laminas\View\Renderer\PhpRenderer $view): void;

        public function onHydrate(\Omeka\Entity\SitePageBlock $block, \Omeka\Stdlib\ErrorStore $errorStore): void;
    }

    interface BlockLayoutInterface
    {
        public function getLabel();

        public function form(
            \Laminas\View\Renderer\PhpRenderer $view,
            \Omeka\Api\Representation\SiteRepresentation $site,
            ?\Omeka\Api\Representation\SitePageRepresentation $page = null,
            ?\Omeka\Api\Representation\SitePageBlockRepresentation $block = null
        );

        public function render(
            \Laminas\View\Renderer\PhpRenderer $view,
            \Omeka\Api\Representation\SitePageBlockRepresentation $block,
            $templateViewScript = null
        );
    }
}

namespace Omeka\Stdlib {
    class ErrorStore
    {
        public function addError($key, $message, ?array $args = null);

        public function hasErrors();
    }

    class HtmlPurifier
    {
        public function purify($html);
    }

    class Message implements \Stringable
    {
        public function __construct($message, ...$args);

        public function setEscapeHtml($escapeHtml);

        public function __toString(): string;
    }
}

namespace Omeka\Api\Adapter {
    class ItemAdapter
    {
    }

    class MediaAdapter
    {
    }

    class ItemSetAdapter
    {
    }
}
