<?php
declare(strict_types=1);

namespace IwacSearch\Site\BlockLayout;

use IwacSearch\Asset\SvelteAssets;
use IwacSearch\Browse\FacetCatalog;
use IwacSearch\IwacInstance;
use IwacSearch\Search\FacetValueLookup;
use IwacSearch\Search\FilterExpression;
use IwacSearch\Search\InitialResponseRenderer;
use IwacSearch\Search\PresetCatalog;
use IwacSearch\Search\ScopeFilters;
use IwacSearch\Search\SearchStateQuery;
use IwacSearch\Search\SurfaceBootstrap;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Entity\SitePageBlock;
use Omeka\Site\BlockLayout\AbstractBlockLayout;
use Omeka\Site\BlockLayout\TemplateableBlockLayoutInterface;
use Omeka\Stdlib\ErrorStore;
use Omeka\Stdlib\HtmlPurifier;

/**
 * Page block that drops the IwacSearch surface into any Omeka Site page.
 *
 * Same Svelte bundle as the standalone /search route — what differs is the
 * bootstrap config blob each instance emits. This is the third-class citizen
 * after /search and /browse/{slug}, but it lets editors compose discovery
 * surfaces (e.g. "show only Burkina Faso newspapers from the 1990s on the
 * Welcome page") without needing a code change or a dedicated browse config.
 *
 * Block data shape (persisted as JSON in site_block.data):
 *   {
 *     "preset":            "all" | "benin" | … | "references" | "index" | "custom",
 *     "mode":              "full" | "compact" | "results-only",
 *     "title":             "Optional H2",
 *     "intro_html":        "Optional intro paragraph",
 *     "locked_filters":    "country_ss:=Burkina Faso && newspaper_ss:=Sidwaya",
 *     "filter_values":     {"country_ss": ["Bénin", "Togo"], "type_s": ["article"]},
 *     "prominent_facets":  ["country_ss", "newspaper_ss", "topics_ss"],
 *     "default_sort":      "" | "_text_match:desc" | "date:desc" | "frequency:desc" | …,
 *     "results_per_page":  10
 *   }
 *
 * `preset` (the Scope dropdown) is the primary control: a ready-made scope
 * from {@see PresetCatalog} (whole corpus, one country, references, or the
 * entity index) drives the collection, locked filter, and facet set.
 * `locked_filters` / `prominent_facets` only apply when preset is `custom`
 * (a raw content-collection filter). `default_sort` applies to ANY scope when
 * it's a valid order for that scope's collection (content vs the entity index);
 * empty means "use the scope's own default sort". Blocks saved before the Scope
 * dropdown existed have no `preset` key and default to `custom`, so their stored
 * filters keep working unchanged.
 *
 * `filter_values` is the multi-select narrowing an editor does WITHOUT writing
 * query syntax — several types, countries, newspapers, languages or sentiment
 * labels at once ({@see ScopeFilters}). Unlike locked_filters it applies to
 * EVERY scope, ANDed onto whatever that scope already locks, so "Bénin + Togo"
 * is a whole-corpus scope with two countries ticked rather than a preset that
 * can only ever hold one. Absent key → empty clause → the block behaves exactly
 * as it did before this existed.
 *
 * locked_filters uses Typesense filter_by syntax directly. NOTE: they are
 * COSMETIC scoping only — the client applies them to every query this block
 * issues, but they are NOT baked into the scoped search key, so a tampering
 * client can drop them and search the whole public corpus. Privacy is
 * enforced solely by the scoped key's own constraints (is_public:=true +
 * ocr_text exclusion, minted in TypesenseSearchKeyProvider). Never use
 * locked_filters to hide non-public data. They DO run with the admin key in
 * the server-side first-page render, though, so onHydrate() refuses one that
 * could escape that render's public guard ({@see FilterExpression}).
 */
class IwacSearchBlock extends AbstractBlockLayout implements TemplateableBlockLayoutInterface
{
    /** Block-data key recording that intro_html was purified when stored ({@see IntroHtml}). */
    public const PURIFIED_FLAG = IntroHtml::PURIFIED_FLAG;

    /** The module's own public template; a theme's block template replaces it. */
    public const TEMPLATE = 'common/block-layout/iwac-search-block';

    public function __construct(
        private readonly InitialResponseRenderer $initialRenderer,
        private readonly HtmlPurifier $htmlPurifier,
        private readonly string $contentAlias = IwacInstance::CONTENT_ALIAS,
        private readonly string $indexAlias = IwacInstance::INDEX_ALIAS,
        // Live facet values for the form's value pickers. Null (or an
        // unreachable index) degrades to the static option lists — see
        // BlockForm::pickers(). Never touched by render(), only by form().
        private readonly ?FacetValueLookup $facetValues = null,
    ) {
    }

    /**
     * Sanitise the block data before it is persisted (mirrors Omeka core's
     * Html block). intro_html is written by site editors and rendered raw on
     * the public page, so it MUST be purified on save — page-edit rights are
     * much broader than global admin, and an unpurified value is stored XSS.
     *
     * Every OTHER field is normalised against its catalog here too, so
     * invalid state can never reach the database: render() would defend
     * against a bad `preset` / `default_sort` anyway, but `mode` used to flow
     * unvalidated into the bootstrap, and half-validating on save meant a
     * stale value could sit in block data misbehaving only at render time.
     *
     * locked_filters is free text, so it is checked rather than normalised: a
     * fragment that could escape the server-side render's public guard is an
     * error, and the page is not saved.
     */
    public function onHydrate(SitePageBlock $block, ErrorStore $errorStore): void
    {
        $data = $block->getData() ?? [];

        $introHtml = (string) ($data['intro_html'] ?? '');
        $data['intro_html'] = $introHtml === '' ? '' : $this->htmlPurifier->purify($introHtml);
        $data[self::PURIFIED_FLAG] = true;

        // Checked whatever the scope: a preset block ignores the field, but
        // switching it back to Custom would make the stored value live.
        $lockedFilters = trim((string) ($data['locked_filters'] ?? ''));
        $problem = FilterExpression::problem($lockedFilters);
        if ($problem !== null) {
            $errorStore->addError('o:block[o:data][locked_filters]', $problem);
        }
        $data['locked_filters'] = $lockedFilters;

        $data['prominent_facets'] = FacetCatalog::normaliseFacets(
            is_iterable($data['prominent_facets'] ?? null) ? $data['prominent_facets'] : []
        );

        // Value pickers: structural normalisation only (known fields, non-empty
        // string values, numeric fields numeric). Deliberately NOT checked
        // against the live index — a save made while Typesense is down, or a
        // newspaper that has left the corpus, must not silently strip a
        // curated block's scope.
        $data['filter_values'] = ScopeFilters::normalise($data['filter_values'] ?? null);

        $perPage = (int) ($data['results_per_page'] ?? 10);
        $data['results_per_page'] = max(1, min(50, $perPage));

        // Scope: a catalog key or the `custom` sentinel; anything else
        // (a renamed preset, a hand-posted value) degrades to custom, which
        // is exactly how render() treats an unresolvable preset.
        $preset = (string) ($data['preset'] ?? PresetCatalog::CUSTOM);
        $data['preset'] = PresetCatalog::get($preset) !== null ? $preset : PresetCatalog::CUSTOM;

        // Render mode drives which chrome the client mounts; an unknown value
        // silently rendered as a degraded "not full" surface.
        $mode = (string) ($data['mode'] ?? 'full');
        $data['mode'] = isset(FacetCatalog::RENDER_MODES[$mode]) ? $mode : 'full';

        // Default sort: '' means "use the scope's own default". A non-empty
        // value must be valid for the scope's collection — the same check
        // render() applies, done once on save so stored data stays truthful.
        $sort = (string) ($data['default_sort'] ?? '');
        $card = PresetCatalog::get($data['preset'])?->card ?? PresetCatalog::CARD_CONTENT;
        $data['default_sort'] = ($sort !== '' && FacetCatalog::isValidSortFor($card, $sort)) ? $sort : '';

        $block->setData($data);
    }

    /** @return string */
    public function getLabel()
    {
        return 'IWAC Search'; // @translate
    }

    /**
     * Admin form. The markup is the {@see BlockForm::TEMPLATE} partial; what
     * it shows (default scope, stale saved values, truncated pickers) is
     * worked out by {@see BlockForm::variables()}.
     *
     * @return string Rendered form markup.
     */
    public function form(
        PhpRenderer $view,
        SiteRepresentation $site,
        ?SitePageRepresentation $page = null,
        ?SitePageBlockRepresentation $block = null
    ) {
        // Unique id suffix so each field's <label for> stays associated when
        // several IWAC blocks are edited on one page (identical ids would
        // otherwise collide and break label binding / fail an a11y audit).
        $uid = ($block && $block->id())
            ? (string) $block->id()
            : substr(bin2hex(random_bytes(4)), 0, 8);

        // One Typesense round trip per request, memoized inside the lookup —
        // Omeka calls form() once per block on the page plus once for the
        // "add block" template. Null (no lookup, or an unreachable index)
        // degrades the pickers to their static options.
        $counts = $this->facetValues?->counts(ScopeFilters::lookupFields());

        return $view->partial(
            BlockForm::TEMPLATE,
            BlockForm::variables($block ? (array) $block->data() : [], $block !== null, $uid, $counts)
        );
    }

    /**
     * Append the compiled Svelte bundle. Omeka calls this once per block
     * layout on a page, before rendering its blocks — the same helper the
     * standalone /search route uses (Module::injectSvelteAssets), and
     * headScript / headLink dedupe identical URLs, so a page that also has
     * the controller's listener still emits each tag once.
     */
    public function prepareRender(PhpRenderer $view): void
    {
        SvelteAssets::injectSearchApp($view);
    }

    /**
     * The block's title and intro feed Omeka's own site-page search, so a
     * visitor searching the site for words in them finds the page. Never
     * renders the block: that would cost a Typesense round trip per save.
     *
     * @return string
     */
    public function getFulltextText(PhpRenderer $view, SitePageBlockRepresentation $block)
    {
        $data = (array) $block->data();
        return trim(trim((string) ($data['title'] ?? '')) . "\n" . IntroHtml::plainText((string) ($data['intro_html'] ?? '')));
    }

    /**
     * Render the block on a public page.
     *
     * The assets come from prepareRender(). The block emits a wrapper div +
     * an inline JSON state script that the Svelte client reads on mount.
     * Multiple blocks per page are supported by suffixing both the wrapper id
     * and the state script id with $block->id().
     *
     * Templateable: a theme can declare its own markup for the block under
     * `block_templates` in its config (`common/block-template/<name>`), and
     * Omeka passes it here; it receives the same variables as the module's
     * template, so the bootstrap and mount contract stay the same.
     *
     * @param  string|null $templateViewScript A theme's block template, or null
     *                                         for the module's own.
     * @return string Rendered block markup.
     */
    public function render(
        PhpRenderer $view,
        SitePageBlockRepresentation $block,
        $templateViewScript = null
    ) {
        $templateViewScript = is_string($templateViewScript) && $templateViewScript !== ''
            ? $templateViewScript
            : self::TEMPLATE;
        $data = $block->data();

        // Resolve the block's Scope. A preset (whole corpus, one country,
        // references, or the entity index) drives the collection, locked
        // filter, facet set, and default sort. Blocks saved before the Scope
        // dropdown existed have no `preset` key → Custom, preserving their
        // stored locked_filters / facets / sort against the content collection.
        $presetKey = $data['preset'] ?? PresetCatalog::CUSTOM;
        $preset = $presetKey === PresetCatalog::CUSTOM
            ? null
            : PresetCatalog::get($presetKey);

        if ($preset !== null) {
            $card             = $preset->card;
            $lockedFilters    = $preset->lockedFilters;
            $prominentFacets  = $preset->facets;
            $scopeDefaultSort = $preset->defaultSort;
            $hideCountry      = $preset->hideCountry;
        } else {
            $card             = PresetCatalog::CARD_CONTENT;
            $lockedFilters    = (string) ($data['locked_filters'] ?? '');
            $prominentFacets  = (array) ($data['prominent_facets'] ?? []);
            $scopeDefaultSort = '_text_match:desc';
            // Custom blocks expose country however the editor configured it.
            $hideCountry      = false;
        }

        // The value pickers apply to EVERY scope, ANDed onto whatever that
        // scope already locks — which is what makes "Bénin + Togo" possible at
        // all (the country presets can only ever hold one). A block with no
        // filter_values compiles to '' and combine() drops it, so the string
        // handed to the client is byte-identical to what it was before.
        $lockedFilters = ScopeFilters::combine(
            $lockedFilters,
            ScopeFilters::compile(ScopeFilters::normalise($data['filter_values'] ?? null))
        );

        // The admin's Default-sort choice (any scope) wins when it's a valid
        // order for this scope's collection — content vs the entity index;
        // otherwise fall back to the scope's own default. Empty / mismatched
        // (e.g. a stale content sort left on an Entity index block) → scope
        // default, so the bootstrap sort always matches what SortSelect offers.
        $adminSort   = (string) ($data['default_sort'] ?? '');
        $defaultSort = ($adminSort !== '' && FacetCatalog::isValidSortFor($card, $adminSort))
            ? $adminSort
            : $scopeDefaultSort;

        // The bootstrap config the Svelte client reads on mount. Same builder
        // — and therefore the same shape — as the /search and
        // /search/everything routes, so the same client mount logic works for
        // every surface. Endpoint stems are resolved through basePath() by the
        // shared mount partial, exactly as they are for the controller routes.
        //
        // Curated blocks deliberately pass no diversify tag: a locked-scope
        // grid ("latest from Sidwaya") wants raw relevance order, not MMR.
        $bootstrap = SurfaceBootstrap::build(
            blockId:         (int) $block->id(),
            card:            $card,
            contentAlias:    $this->contentAlias,
            indexAlias:      $this->indexAlias,
            prominentFacets: array_values(array_filter($prominentFacets, 'is_string')),
            defaultSort:     $defaultSort,
            mode:            (string) ($data['mode'] ?? 'full'),
            lockedFilters:   $lockedFilters,
            resultsPerPage:  (int) ($data['results_per_page'] ?? 10),
            // Single-country scopes hide the (redundant) country chip on cards.
            hideCountry:     $hideCountry,
        );

        // SSR the first page so blocks with locked filters (e.g. "latest
        // from Sidwaya" on a Welcome page) show items immediately instead
        // of flashing empty for ~500 ms while the client mints a scoped
        // key and fetches. Null return → fall through to client-side fetch.
        // Skipped when the URL already carries this block's own state (a
        // full-mode block syncs it under `b{id}.`): the client would discard
        // the default-page snapshot and refetch, as on /search.
        // The raw query string (see SearchStateQuery); absent outside a web
        // request, e.g. when a job renders a page.
        $blockState = $bootstrap['mode'] === 'full'
            && SearchStateQuery::carriesState(
                (string) ($_SERVER['QUERY_STRING'] ?? ''),
                'b' . $block->id() . '.'
            );
        if (!$blockState) {
            $initial = $this->initialRenderer->render($bootstrap);
            if ($initial !== null) {
                $bootstrap['initial_response'] = $initial;
            }
        }

        // The template outputs intro_html raw. Anything saved through
        // onHydrate() (or migrated by IntroMigration) is flagged as
        // already purified; only an unflagged legacy value is purified here,
        // so a block saved before onHydrate() existed is never output raw —
        // even on an install whose upgrade migration has not run.
        $introHtml = IntroHtml::forRender($data, fn(string $html): string => (string) $this->htmlPurifier->purify($html));

        return $view->partial($templateViewScript, [
            'block'      => $block,
            'data'       => $data,
            'bootstrap'  => $bootstrap,
            'title'      => $data['title']      ?? '',
            'intro_html' => $introHtml,
        ]);
    }
}
