<?php
declare(strict_types=1);

namespace IwacSearch\Site\NavigationLink;

use IwacSearch\Browse\BrowseConfigRepository;
use IwacSearch\Browse\BrowseContent;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Site\Navigation\Link\LinkInterface;
use Omeka\Stdlib\ErrorStore;

/**
 * Site-navigation link type for one curated IWAC browse page.
 *
 * Lets a site admin pick "IWAC Browse" from the navigation link-type
 * dropdown and select one of the curated `iwac_browse_config` rows
 * (Bénin, Burkina Faso, …). Omeka resolves it to the proper site-
 * scoped URL — `/s/{site-slug}/browse/{slug}` — using the
 * `site/iwac-browse` route. That keeps users inside their language
 * site (French `/s/afrique_ouest`, English `/s/westafrica`) instead
 * of bouncing them out to a global URL.
 *
 * Modelled on omeka-s-modules/FacetedBrowse — same five-method
 * `LinkInterface` contract, same form template idiom.
 *
 * Storage: the form persists the BrowseConfig row's numeric `id` (not
 * the slug) so that admin-side slug renames don't break navigation
 * links. The id → slug resolution happens at URL-generation time via
 * the repository — one cheap query per link, against a tiny table.
 */
class IwacBrowse implements LinkInterface
{
    /**
     * Repository injected by the factory. Reads from the same DBAL
     * connection the rest of the module uses. Used in:
     *   - getLabel()  : resolve id → title for the menu label
     *   - toZend()    : resolve id → slug for the URL
     *
     * Mutation isn't part of this class's contract; the repository is
     * read-only here.
     */
    public function __construct(private readonly BrowseConfigRepository $repository)
    {
    }

    public function getName(): string
    {
        return 'IWAC Browse'; // @translate
    }

    public function getFormTemplate(): string
    {
        return 'common/iwac-search/navigation-link-form/iwac-browse';
    }

    /**
     * @param array<string, mixed> $data
     */
    public function isValid(array $data, ErrorStore $errorStore): bool
    {
        if (!isset($data['browse_id']) || !is_numeric($data['browse_id']) || (int) $data['browse_id'] <= 0) {
            $errorStore->addError('o:navigation', 'Invalid navigation: IWAC Browse missing a page'); // @translate
            return false;
        }
        return true;
    }

    /**
     * Label rendered in the live nav menu.
     *
     * Falls back through:
     *   1. The admin-typed label (if any).
     *   2. The browse config's title from the repository.
     *   3. A bracketed missing-page marker (so a deleted config doesn't
     *      crash menu rendering).
     *
     * @param array<string, mixed> $data
     */
    public function getLabel(array $data, SiteRepresentation $site): string
    {
        if (isset($data['label']) && '' !== trim((string) $data['label'])) {
            return (string) $data['label'];
        }
        $browseId = (int) ($data['browse_id'] ?? 0);
        $config = $browseId > 0 ? $this->repository->findById($browseId) : null;
        if ($config === null) {
            $translator = $site->getServiceLocator()->get('MvcTranslator');
            return $translator->translate('[Missing IWAC Browse page]'); // @translate
        }
        // Localize the menu label (e.g. "Tous les pays" vs "All countries").
        // Country pages return their proper-noun name unchanged.
        return BrowseContent::localize($config, $this->localeOf($site))['title'];
    }

    /**
     * Laminas-style nav config consumed by the public-side menu helper.
     *
     * Returning a `route` + `params` (instead of a hand-rolled `uri`)
     * lets the navigation helper compute the proper URL, run the
     * active-state matcher, and integrate with Omeka's i18n helpers.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function toZend(array $data, SiteRepresentation $site): array
    {
        $browseId = (int) ($data['browse_id'] ?? 0);
        $config = $browseId > 0 ? $this->repository->findById($browseId) : null;

        // French sites resolve to /parcourir, English (and any other) to
        // /browse — both routes hit the same controller + browse-config row.
        $route = $this->localeOf($site) === 'en' ? 'site/iwac-browse' : 'site/iwac-parcourir';

        // Stale link (config was deleted): degrade to the site-scoped
        // browse landing page rather than 404. The label is already
        // rendered as "[Missing IWAC Browse page]" via getLabel.
        if ($config === null) {
            return [
                'route'  => $route,
                'params' => ['site-slug' => $site->slug()],
            ];
        }

        return [
            'route'  => $route,
            'params' => [
                'site-slug' => $site->slug(),
                'slug'      => $config->slug,
            ],
        ];
    }

    /**
     * Resolve 'fr' | 'en' from the site slug (`afrique_ouest` → fr,
     * `westafrica` → en). Mirrors the IwacLocale view helper so the menu
     * link and the rendered page agree on which route to use.
     */
    private function localeOf(SiteRepresentation $site): string
    {
        $slug = strtolower((string) $site->slug());
        if (str_contains($slug, 'westafrica') || str_contains($slug, 'english')) {
            return 'en';
        }
        return 'fr';
    }

    /**
     * Shape consumed by the admin nav-tree JS widget. Only carries data
     * the tree needs to display + edit; the resolved URL/label are
     * computed by getLabel/toZend on the public side.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function toJstree(array $data, SiteRepresentation $site): array
    {
        return [
            'label'     => $data['label'] ?? null,
            'browse_id' => (int) ($data['browse_id'] ?? 0),
        ];
    }
}
