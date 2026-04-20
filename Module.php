<?php
declare(strict_types=1);

/**
 * IwacSearch — Omeka S module.
 *
 * Owns the public discovery surface (/search, /browse/{slug}) backed by Typesense.
 * Admin search, item detail, ingest, and IIIF stay on Omeka.
 *
 * Lifecycle:
 *  - install/upgrade: ensure module's own tables exist (iwac_browse_config in M3+)
 *  - bootstrap (attachListeners): register api.*.post listeners so edits in Omeka
 *    propagate to Typesense (M4). For M0–M3 these are no-ops; the bulk reindex
 *    CLI is the source of truth.
 *
 * @see docs/iwac-search-roadmap.md in IWAC-docker for the full plan.
 */

namespace IwacSearch;

use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Module\AbstractModule;

class Module extends AbstractModule
{
    /**
     * Loaded by Omeka's autoloader at module activation; merged into the global
     * service manager and routing config.
     */
    public function getConfig(): array
    {
        return include __DIR__ . '/config/module.config.php';
    }

    /**
     * Subscribe to Omeka API events. M0 keeps these as placeholders so the
     * module can be activated safely without a running indexer; M4 wires them
     * to the Typesense upsert/delete pipeline.
     */
    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        // Intentionally empty in M0. See M4 in the roadmap.
        // Example future wiring:
        //   $sharedEventManager->attach(
        //       'Omeka\Api\Adapter\ItemAdapter',
        //       'api.create.post',
        //       [$this, 'onItemCreated']
        //   );
    }

    public function install(ServiceLocatorInterface $services): void
    {
        // M3 will create iwac_browse_config here. Empty for M0.
    }

    public function uninstall(ServiceLocatorInterface $services): void
    {
        // Mirror of install(). Drops module-owned tables only — never touches
        // Typesense data, since that may be shared with a parallel install.
    }

    /**
     * Placeholder — M4 hook for upserting an item into Typesense.
     *
     * @internal
     */
    public function onItemCreated(Event $event): void
    {
        // To be implemented in M4.
    }
}
