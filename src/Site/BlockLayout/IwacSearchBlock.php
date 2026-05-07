<?php
declare(strict_types=1);

namespace IwacSearch\Site\BlockLayout;

use IwacSearch\Browse\FacetCatalog;
use IwacSearch\Search\InitialResponseRenderer;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Site\BlockLayout\AbstractBlockLayout;

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
 *     "mode":              "full" | "compact" | "results-only",
 *     "title":             "Optional H2",
 *     "intro_html":        "Optional intro paragraph",
 *     "locked_filters":    "country_ss:=Burkina Faso && newspaper_ss:=Sidwaya",
 *     "prominent_facets":  ["country_ss", "newspaper_ss", "topics_ss"],
 *     "default_sort":      "_text_match:desc" | "date:desc" | "date:asc",
 *     "results_per_page":  10
 *   }
 *
 * locked_filters uses Typesense filter_by syntax directly. They're enforced
 * server-side at scoped-key mint time (not just hidden in the UI), so the
 * block instance cannot leak data outside its scope even if the client
 * tampers with the request.
 */
class IwacSearchBlock extends AbstractBlockLayout
{
    public function __construct(
        private readonly InitialResponseRenderer $initialRenderer
    ) {
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
        $mode             = $data['mode']             ?? 'full';
        $title            = $data['title']            ?? '';
        $introHtml        = $data['intro_html']       ?? '';
        $lockedFilters    = $data['locked_filters']   ?? '';
        // Default mirrors what the standalone /search route shows. Block
        // admin can override per-instance via the form below.
        $prominentFacets  = $data['prominent_facets']
            ?? [
                'type_s',
                'country_ss',
                'newspaper_ss',
                'places_ss',
                'persons_ss',
                'organisations_ss',
                'topics_ss',
                'gemini_polarite_ss',
            ];
        $defaultSort      = $data['default_sort']     ?? '_text_match:desc';
        $resultsPerPage   = (int) ($data['results_per_page'] ?? 10);

        $esc     = fn(string $s): string => $view->escapeHtml($s);
        $escAttr = fn(string $s): string => $view->escapeHtmlAttr($s);
        $t       = fn(string $s): string => (string) $view->translate($s);

        $namePrefix = 'o:block[__blockIndex__][o:data]';

        ob_start();
        ?>
        <div class="field">
            <div class="field-meta">
                <label for="iwac-search-mode"><?= $esc($t('Render mode')) ?></label>
                <div class="field-description">
                    <?= $esc($t('Full = standalone discovery surface. Compact = header search box. Results only = curated grid (e.g. "latest from Sidwaya").')) ?>
                </div>
            </div>
            <div class="inputs">
                <select id="iwac-search-mode" name="<?= $escAttr($namePrefix) ?>[mode]">
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
                <label for="iwac-search-title"><?= $esc($t('Title (optional)')) ?></label>
            </div>
            <div class="inputs">
                <input id="iwac-search-title" type="text"
                       name="<?= $escAttr($namePrefix) ?>[title]"
                       value="<?= $escAttr($title) ?>">
            </div>
        </div>

        <div class="field">
            <div class="field-meta">
                <label for="iwac-search-intro"><?= $esc($t('Intro HTML (optional)')) ?></label>
                <div class="field-description">
                    <?= $esc($t('Plain HTML rendered above the search results.')) ?>
                </div>
            </div>
            <div class="inputs">
                <textarea id="iwac-search-intro" rows="3"
                          name="<?= $escAttr($namePrefix) ?>[intro_html]"><?= $esc($introHtml) ?></textarea>
            </div>
        </div>

        <div class="field">
            <div class="field-meta">
                <label for="iwac-search-locked"><?= $esc($t('Locked filters (Typesense filter_by)')) ?></label>
                <div class="field-description">
                    <?= $esc($t('Enforced server-side. Example: country_ss:=`Burkina Faso` && date:>=946684800')) ?>
                </div>
            </div>
            <div class="inputs">
                <input id="iwac-search-locked" type="text"
                       name="<?= $escAttr($namePrefix) ?>[locked_filters]"
                       value="<?= $escAttr($lockedFilters) ?>"
                       placeholder="country_ss:=`Burkina Faso`">
            </div>
        </div>

        <div class="field">
            <div class="field-meta">
                <label><?= $esc($t('Prominent facets')) ?></label>
                <div class="field-description">
                    <?= $esc($t('Facets shown above the fold. Others move under "More filters".')) ?>
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
                <label for="iwac-search-sort"><?= $esc($t('Default sort')) ?></label>
            </div>
            <div class="inputs">
                <select id="iwac-search-sort" name="<?= $escAttr($namePrefix) ?>[default_sort]">
                    <?php foreach (FacetCatalog::SORT_OPTIONS as $key => $label): ?>
                        <option value="<?= $escAttr($key) ?>"<?= $key === $defaultSort ? ' selected' : '' ?>>
                            <?= $esc($t($label)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="field">
            <div class="field-meta">
                <label for="iwac-search-perpage"><?= $esc($t('Results per page')) ?></label>
            </div>
            <div class="inputs">
                <input id="iwac-search-perpage" type="number" min="1" max="50" step="1"
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
        // the same page still results in one <script>/<link>. Matches the
        // standalone /search route (see Module::injectSvelteAssets).
        $view->headLink()->appendStylesheet(
            $view->assetUrl('css/iwac-search.css', 'IwacSearch')
        );
        // The compiled Svelte bundle's CSS — every component-scoped style
        // lives here. Vite's IIFE lib build does NOT auto-inject this from
        // the JS at runtime; without this <link> the components mount but
        // render unstyled.
        $view->headLink()->appendStylesheet(
            $view->assetUrl('dist/iwac-search.css', 'IwacSearch')
        );
        $view->headScript()->appendFile(
            $view->assetUrl('dist/iwac-search.js', 'IwacSearch'),
            'text/javascript',
            ['defer' => true]
        );

        // The bootstrap config the Svelte client reads on mount. Same shape
        // as the /search and /browse/{slug} routes emit, so the same client
        // mount logic works for all three surfaces.
        $bootstrap = [
            'block_id'         => (int) $block->id(),
            'mode'             => $data['mode']             ?? 'full',
            'locked_filters'   => $data['locked_filters']   ?? '',
            'prominent_facets' => $data['prominent_facets'] ?? [],
            'default_sort'     => $data['default_sort']     ?? '_text_match:desc',
            'results_per_page' => (int) ($data['results_per_page'] ?? 10),
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

        return $view->partial($templateViewScript, [
            'block'      => $block,
            'data'       => $data,
            'bootstrap'  => $bootstrap,
            'title'      => $data['title']      ?? '',
            'intro_html' => $data['intro_html'] ?? '',
        ]);
    }
}
