<?php
declare(strict_types=1);

namespace IwacSearch\Site\BlockLayout;

use IwacSearch\Asset\SvelteAssets;
use IwacSearch\Browse\FacetCatalog;
use IwacSearch\Search\InitialResponseRenderer;
use IwacSearch\Search\PresetCatalog;
use IwacSearch\Search\SearchDefaults;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Entity\SitePageBlock;
use Omeka\Site\BlockLayout\AbstractBlockLayout;
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
 * locked_filters uses Typesense filter_by syntax directly. NOTE: they are
 * COSMETIC scoping only — the client applies them to every query this block
 * issues, but they are NOT baked into the scoped search key, so a tampering
 * client can drop them and search the whole public corpus. Privacy is
 * enforced solely by the scoped key's own constraints (is_public:=true +
 * ocr_text exclusion, minted in TypesenseSearchKeyProvider). Never use
 * locked_filters to hide non-public data.
 */
class IwacSearchBlock extends AbstractBlockLayout
{
    public function __construct(
        private readonly InitialResponseRenderer $initialRenderer,
        private readonly HtmlPurifier $htmlPurifier,
        private readonly string $contentAlias = 'iwac_current',
        private readonly string $indexAlias = 'iwac_index_current',
    ) {
    }

    /**
     * Sanitise the block data before it is persisted (mirrors Omeka core's
     * Html block). intro_html is written by site editors and rendered raw on
     * the public page, so it MUST be purified on save — page-edit rights are
     * much broader than global admin, and an unpurified value is stored XSS.
     * prominent_facets is normalised against the catalog so unknown field
     * names can't persist in block data.
     */
    public function onHydrate(SitePageBlock $block, ErrorStore $errorStore): void
    {
        $data = $block->getData() ?? [];

        $introHtml = (string) ($data['intro_html'] ?? '');
        $data['intro_html'] = $introHtml === '' ? '' : $this->htmlPurifier->purify($introHtml);

        $data['prominent_facets'] = FacetCatalog::normaliseFacets(
            is_iterable($data['prominent_facets'] ?? null) ? $data['prominent_facets'] : []
        );

        $perPage = (int) ($data['results_per_page'] ?? 10);
        $data['results_per_page'] = max(1, min(50, $perPage));

        $block->setData($data);
    }

    public function getLabel()
    {
        return 'IWAC Search'; // @translate
    }

    /**
     * Admin form. Hand-rolled HTML rather than Laminas Form — Omeka's block
     * form contract uses the o:block[__blockIndex__][o:data][...] name pattern
     * which Laminas Form doesn't support cleanly without a lot of ceremony.
     */
    public function form(
        PhpRenderer $view,
        SiteRepresentation $site,
        ?SitePageRepresentation $page = null,
        ?SitePageBlockRepresentation $block = null
    ) {
        $data = $block ? $block->data() : [];
        // New blocks default to the whole-corpus scope; blocks saved before
        // the Scope dropdown existed (no `preset` key) default to Custom so
        // their stored locked_filters keep driving the surface.
        $preset           = $data['preset']
            ?? ($block !== null ? PresetCatalog::CUSTOM : PresetCatalog::DEFAULT_KEY);
        $mode             = $data['mode']             ?? 'full';
        $title            = $data['title']            ?? '';
        $introHtml        = $data['intro_html']       ?? '';
        $lockedFilters    = $data['locked_filters']   ?? '';
        // Default mirrors what the standalone /search route shows — the
        // SAME constant, so the two can't drift. Block admin can override
        // per-instance via the form below.
        $prominentFacets  = $data['prominent_facets']
            ?? SearchDefaults::CONTENT_PROMINENT_FACETS;
        // Empty = "use the scope's own default sort". An explicit choice
        // overrides it (when valid for the scope's collection — see render()).
        $defaultSort      = $data['default_sort']     ?? '';
        $resultsPerPage   = (int) ($data['results_per_page'] ?? 10);

        $esc     = fn(string $s): string => $view->escapeHtml($s);
        $escAttr = fn(string $s): string => $view->escapeHtmlAttr($s);
        $t       = fn(string $s): string => (string) $view->translate($s);

        $namePrefix = 'o:block[__blockIndex__][o:data]';
        // Unique id suffix so each field's <label for> stays associated when
        // several IWAC blocks are edited on one page (identical ids would
        // otherwise collide and break label binding / fail an a11y audit).
        $uid = ($block && $block->id())
            ? (string) $block->id()
            : substr(bin2hex(random_bytes(4)), 0, 8);

        ob_start();
        ?>
        <div class="field">
            <div class="field-meta">
                <label for="iwac-search-preset-<?= $escAttr($uid) ?>"><?= $esc($t('Scope')) ?></label>
                <div class="field-description">
                    <?= $esc($t('What this block searches. Pick a ready-made scope, or “Custom…” to set your own content filter + facets in the advanced fields below.')) ?>
                </div>
            </div>
            <div class="inputs">
                <select id="iwac-search-preset-<?= $escAttr($uid) ?>" name="<?= $escAttr($namePrefix) ?>[preset]">
                    <?php foreach (PresetCatalog::optionsList() as $opt): ?>
                        <option value="<?= $escAttr($opt['value']) ?>"<?= $opt['value'] === $preset ? ' selected' : '' ?>>
                            <?= $esc($t($opt['label'])) ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="<?= $escAttr(PresetCatalog::CUSTOM) ?>"<?= $preset === PresetCatalog::CUSTOM ? ' selected' : '' ?>>
                        <?= $esc($t('Custom…')) ?>
                    </option>
                </select>
            </div>
        </div>

        <div class="field">
            <div class="field-meta">
                <label for="iwac-search-mode-<?= $escAttr($uid) ?>"><?= $esc($t('Render mode')) ?></label>
                <div class="field-description">
                    <?= $esc($t('Full = standalone discovery surface. Compact = header search box. Results only = curated grid (e.g. "latest from Sidwaya").')) ?>
                </div>
            </div>
            <div class="inputs">
                <select id="iwac-search-mode-<?= $escAttr($uid) ?>" name="<?= $escAttr($namePrefix) ?>[mode]">
                    <?php foreach (FacetCatalog::RENDER_MODES as $key => $label): ?>
                        <option value="<?= $escAttr($key) ?>"<?= $key === $mode ? ' selected' : '' ?>>
                            <?= $esc($t($label)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="field">
            <div class="field-meta">
                <label for="iwac-search-title-<?= $escAttr($uid) ?>"><?= $esc($t('Title (optional)')) ?></label>
            </div>
            <div class="inputs">
                <input id="iwac-search-title-<?= $escAttr($uid) ?>" type="text"
                       name="<?= $escAttr($namePrefix) ?>[title]"
                       value="<?= $escAttr($title) ?>">
            </div>
        </div>

        <div class="field">
            <div class="field-meta">
                <label for="iwac-search-intro-<?= $escAttr($uid) ?>"><?= $esc($t('Intro HTML (optional)')) ?></label>
                <div class="field-description">
                    <?= $esc($t('Plain HTML rendered above the search results.')) ?>
                </div>
            </div>
            <div class="inputs">
                <textarea id="iwac-search-intro-<?= $escAttr($uid) ?>" rows="3"
                          name="<?= $escAttr($namePrefix) ?>[intro_html]"><?= $esc($introHtml) ?></textarea>
            </div>
        </div>

        <div class="field">
            <div class="field-meta">
                <label for="iwac-search-locked-<?= $escAttr($uid) ?>"><?= $esc($t('Locked filters (Typesense filter_by)')) ?></label>
                <div class="field-description">
                    <?= $esc($t('Custom scope only. Applied to every query from this block (cosmetic scoping — not a privacy boundary). Example: country_ss:=`Burkina Faso` && date:>=946684800')) ?>
                </div>
            </div>
            <div class="inputs">
                <input id="iwac-search-locked-<?= $escAttr($uid) ?>" type="text"
                       name="<?= $escAttr($namePrefix) ?>[locked_filters]"
                       value="<?= $escAttr($lockedFilters) ?>"
                       placeholder="country_ss:=`Burkina Faso`">
            </div>
        </div>

        <div class="field">
            <div class="field-meta">
                <label><?= $esc($t('Prominent facets')) ?></label>
                <div class="field-description">
                    <?= $esc($t('Custom scope only — preset scopes use their own facet set. Facets shown above the fold; others move under "More filters".')) ?>
                </div>
            </div>
            <div class="inputs">
                <?php foreach (FacetCatalog::FACETABLE_FIELDS as $field => $label): ?>
                    <label class="iwac-facet-pick" style="display:block;">
                        <input type="checkbox"
                               name="<?= $escAttr($namePrefix) ?>[prominent_facets][]"
                               value="<?= $escAttr($field) ?>"
                               <?= in_array($field, $prominentFacets, true) ? 'checked' : '' ?>>
                        <?= $esc($t($label)) ?>
                        <code style="opacity:.6"><?= $esc($field) ?></code>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="field">
            <div class="field-meta">
                <label for="iwac-search-sort-<?= $escAttr($uid) ?>"><?= $esc($t('Default sort')) ?></label>
                <div class="field-description">
                    <?= $esc($t('Initial result order. Leave on “Use scope default” to follow the scope (e.g. most-mentioned for the Entity index, newest for a country). The Entity index orders apply only when the scope is the Entity index; an order that does not fit the chosen scope is ignored.')) ?>
                </div>
            </div>
            <div class="inputs">
                <select id="iwac-search-sort-<?= $escAttr($uid) ?>" name="<?= $escAttr($namePrefix) ?>[default_sort]">
                    <option value=""<?= $defaultSort === '' ? ' selected' : '' ?>>
                        <?= $esc($t('Use scope default')) ?>
                    </option>
                    <optgroup label="<?= $escAttr($t('Content (articles, references…)')) ?>">
                        <?php foreach (FacetCatalog::sortOptionsFor('content') as $key => $label): ?>
                            <option value="<?= $escAttr($key) ?>"<?= $key === $defaultSort ? ' selected' : '' ?>>
                                <?= $esc($t($label)) ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                    <optgroup label="<?= $escAttr($t('Entity index')) ?>">
                        <?php foreach (FacetCatalog::sortOptionsFor('entity') as $key => $label): ?>
                            <option value="<?= $escAttr($key) ?>"<?= $key === $defaultSort ? ' selected' : '' ?>>
                                <?= $esc($t($label)) ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
            </div>
        </div>

        <div class="field">
            <div class="field-meta">
                <label for="iwac-search-perpage-<?= $escAttr($uid) ?>"><?= $esc($t('Results per page')) ?></label>
            </div>
            <div class="inputs">
                <input id="iwac-search-perpage-<?= $escAttr($uid) ?>" type="number" min="1" max="50" step="1"
                       name="<?= $escAttr($namePrefix) ?>[results_per_page]"
                       value="<?= $escAttr((string) $resultsPerPage) ?>">
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Render the block on a public page.
     *
     * Asset injection happens here (idempotent — Laminas headScript dedupes
     * by URL). The block emits a wrapper div + an inline JSON state script
     * that the Svelte client reads on mount. Multiple blocks per page are
     * supported by suffixing both the wrapper id and the state script id
     * with $block->id().
     */
    public function render(
        PhpRenderer $view,
        SitePageBlockRepresentation $block,
        $templateViewScript = 'common/block-layout/iwac-search-block'
    ) {
        $data = $block->data();

        // Append the compiled Svelte bundle once per request. headScript and
        // headLink dedupe identical URLs, so calling this from N blocks on
        // the same page still results in one <script>/<link>. Same helper
        // the standalone /search route uses (Module::injectSvelteAssets).
        SvelteAssets::injectSearchApp($view);

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

        // The admin's Default-sort choice (any scope) wins when it's a valid
        // order for this scope's collection — content vs the entity index;
        // otherwise fall back to the scope's own default. Empty / mismatched
        // (e.g. a stale content sort left on an Entity index block) → scope
        // default, so the bootstrap sort always matches what SortSelect offers.
        $adminSort   = (string) ($data['default_sort'] ?? '');
        $defaultSort = ($adminSort !== '' && FacetCatalog::isValidSortFor($card, $adminSort))
            ? $adminSort
            : $scopeDefaultSort;

        $isEntity        = ($card === PresetCatalog::CARD_ENTITY);
        $collectionAlias = $isEntity ? $this->indexAlias : $this->contentAlias;

        // The bootstrap config the Svelte client reads on mount. Same shape
        // as the /search and /browse/{slug} routes emit, so the same client
        // mount logic works for every surface. query_by / highlight_fields are
        // collection-specific: the entity index lacks ocr_text/abstract/
        // embedding, so it must pass its own set or Typesense 404s on them.
        $bootstrap = [
            'block_id'         => (int) $block->id(),
            'mode'             => $data['mode'] ?? 'full',
            'card'             => $card,
            'locked_filters'   => $lockedFilters,
            'prominent_facets' => $prominentFacets,
            // Single-country scopes hide the (redundant) country chip on cards.
            'hide_country'     => $hideCountry,
            'default_sort'     => $defaultSort,
            'results_per_page' => (int) ($data['results_per_page'] ?? 10),
            'collection_alias' => $collectionAlias,
            // Entity collection alias (always advertised) so the block's
            // autocomplete can federate to the index entities like the other
            // surfaces.
            'index_collection_alias' => $this->indexAlias,
            'query_by'         => $isEntity
                ? SearchDefaults::ENTITY_QUERY_BY
                : SearchDefaults::CONTENT_QUERY_BY,
            'highlight_fields' => $isEntity
                ? SearchDefaults::ENTITY_HIGHLIGHT_FIELDS
                : SearchDefaults::CONTENT_HIGHLIGHT_FIELDS,
            // Endpoint locations — kept here so the client doesn't hardcode
            // Omeka's URL shape, and so site admins can override later.
            'endpoints' => [
                'token'  => $view->basePath('/discovery/token'),
                'search' => $view->basePath('/search-api/multi_search'),
            ],
        ];

        // SSR the first page so blocks with locked filters (e.g. "latest
        // from Sidwaya" on a Welcome page) show items immediately instead
        // of flashing empty for ~500 ms while the client mints a scoped
        // key and fetches. Null return → fall through to client-side fetch.
        $initial = $this->initialRenderer->render($bootstrap);
        if ($initial !== null) {
            $bootstrap['initial_response'] = $initial;
        }

        // Re-purify at render time as well: blocks saved before onHydrate()
        // existed persist whatever the editor typed, and the template outputs
        // this raw. Cheap for the short intro strings blocks carry.
        $introHtml = (string) ($data['intro_html'] ?? '');
        if ($introHtml !== '') {
            $introHtml = $this->htmlPurifier->purify($introHtml);
        }

        return $view->partial($templateViewScript, [
            'block'      => $block,
            'data'       => $data,
            'bootstrap'  => $bootstrap,
            'title'      => $data['title']      ?? '',
            'intro_html' => $introHtml,
        ]);
    }
}
