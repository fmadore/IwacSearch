<?php
declare(strict_types=1);

namespace IwacSearch\Site\BlockLayout;

use IwacSearch\Asset\SvelteAssets;
use IwacSearch\Browse\FacetCatalog;
use IwacSearch\Search\FacetValueLookup;
use IwacSearch\Search\InitialResponseRenderer;
use IwacSearch\Search\PresetCatalog;
use IwacSearch\Search\ScopeFilters;
use IwacSearch\Search\SearchDefaults;
use IwacSearch\Search\SurfaceBootstrap;
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
 * locked_filters to hide non-public data.
 */
class IwacSearchBlock extends AbstractBlockLayout
{
    public function __construct(
        private readonly InitialResponseRenderer $initialRenderer,
        private readonly HtmlPurifier $htmlPurifier,
        private readonly string $contentAlias = 'iwac_current',
        private readonly string $indexAlias = 'iwac_index_current',
        // Live facet values for the form's value pickers. Null (or an
        // unreachable index) degrades to the static option lists — see
        // renderValuePickers(). Never touched by render(), only by form().
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
     */
    public function onHydrate(SitePageBlock $block, ErrorStore $errorStore): void
    {
        $data = $block->getData() ?? [];

        $introHtml = (string) ($data['intro_html'] ?? '');
        $data['intro_html'] = $introHtml === '' ? '' : $this->htmlPurifier->purify($introHtml);

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
     * Admin form. Hand-rolled HTML rather than Laminas Form — Omeka's block
     * form contract uses the o:block[__blockIndex__][o:data][...] name pattern
     * which Laminas Form doesn't support cleanly without a lot of ceremony.
     *
     * @return string Rendered form markup.
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
        $filterValues     = ScopeFilters::normalise($data['filter_values'] ?? null);
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

        <?= $this->renderValuePickers($view, $namePrefix, $uid, $filterValues) ?>

        <div class="field">
            <div class="field-meta">
                <label for="iwac-search-locked-<?= $escAttr($uid) ?>"><?= $esc($t('Locked filters (Typesense filter_by)')) ?></label>
                <div class="field-description">
                    <?= $esc($t('Advanced escape hatch, for conditions the pickers above cannot express (date ranges, exclusions). Custom scope only. Applied to every query from this block (cosmetic scoping — not a privacy boundary). Example: date:>=946684800 && type_s:!=reference')) ?>
                </div>
            </div>
            <div class="inputs">
                <input id="iwac-search-locked-<?= $escAttr($uid) ?>" type="text"
                       name="<?= $escAttr($namePrefix) ?>[locked_filters]"
                       value="<?= $escAttr($lockedFilters) ?>"
                       placeholder="date:>=946684800">
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
     * The multi-select value pickers — one checkbox list per
     * {@see ScopeFilters::FIELDS} entry.
     *
     * Options come from the live index where the vocabulary is open data
     * (newspaper titles, languages, the categorical sentiment labels) and from
     * the closed enums this codebase already declares otherwise (types,
     * countries, the 1–5 subjectivity scale). The index is also asked for
     * document counts on the closed enums, since "Photographie (0)" is what
     * tells an editor not to bother scoping to it.
     *
     * Nothing here is required for the form to work: an unreachable index
     * costs the open pickers their options and every picker its counts, and
     * says so — the page-edit screen still renders, and the raw locked_filters
     * field below is still a complete escape hatch.
     *
     * @param array<string, list<string>> $selected Normalised current selection.
     */
    private function renderValuePickers(
        PhpRenderer $view,
        string $namePrefix,
        string $uid,
        array $selected
    ): string {
        $esc     = fn(string $s): string => $view->escapeHtml($s);
        $escAttr = fn(string $s): string => $view->escapeHtmlAttr($s);
        $t       = fn(string $s): string => (string) $view->translate($s);

        // One Typesense round trip per request, memoized inside the lookup —
        // Omeka calls form() once per block on the page plus once for the
        // "add block" template.
        $counts = $this->facetValues?->counts(ScopeFilters::lookupFields());

        // Only the closed-enum labels are translatable source strings; a
        // newspaper title is data and goes through verbatim.
        $valueLabel = static function (string $field, string $value) use ($t): string {
            $label = ScopeFilters::valueLabel($field, $value);
            return $label === $value ? $value : $t($label);
        };

        ob_start();
        ?>
        <div class="field">
            <div class="field-meta">
                <label><?= $esc($t('Narrow by value')) ?></label>
                <div class="field-description">
                    <?= $esc($t('Tick any number of values. Within one picker values are OR-ed ("Bénin or Togo"); separate pickers are AND-ed ("… and only news articles"). Applies to every scope, on top of what that scope already locks — so for a multi-country block choose the "All content" scope and tick the countries here, rather than a single-country scope. Leave a picker empty to not filter on it. Visitors cannot remove these.')) ?>
                </div>
            </div>
        </div>
        <?php foreach (ScopeFilters::FIELDS as $field): ?>
            <?php
            $chosen  = $selected[$field] ?? [];
            $options = $this->pickerOptions($field, $counts, $chosen);
            // A checkbox LIST has no single labelable control, so the group
            // label can't use `for` (it would point at a div). Name the label
            // instead and let the list reference it as a labelled group.
            $labelId = 'iwac-search-values-' . $field . '-' . $uid;
            ?>
            <div class="field">
                <div class="field-meta">
                    <label id="<?= $escAttr($labelId) ?>"><?= $esc($t(ScopeFilters::label($field))) ?></label>
                    <div class="field-description">
                        <code style="opacity:.6"><?= $esc($field) ?></code>
                        <?php if (FacetValueLookup::isTruncated($counts, $field)): ?>
                            <?php // Translate the pattern, THEN interpolate — the other way
                                  // round there is no msgid for the translator to match. ?>
                            <br><?= $esc(sprintf(
                                $t('Showing the %d most common values — the index holds more.'),
                                FacetValueLookup::MAX_VALUES
                            )) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="inputs">
                    <?php if ($options === []): ?>
                        <p style="margin:0;opacity:.7">
                            <?= $esc($counts === null
                                ? $t('The search index is unreachable, so this list could not be loaded. Existing selections are preserved; reopen this form once the index is back, or use the Locked filters field below.')
                                : $t('The index holds no values for this field yet.')) ?>
                        </p>
                    <?php else: ?>
                        <div class="iwac-value-picker" role="group" aria-labelledby="<?= $escAttr($labelId) ?>"
                             style="max-height:13em;overflow-y:auto;border:1px solid #dfdfdf;border-radius:3px;padding:.4em .6em;">
                            <?php foreach ($options as $option): ?>
                                <label class="iwac-value-pick" style="display:block;">
                                    <input type="checkbox"
                                           name="<?= $escAttr($namePrefix) ?>[filter_values][<?= $escAttr($field) ?>][]"
                                           value="<?= $escAttr($option['value']) ?>"
                                           <?= in_array($option['value'], $chosen, true) ? 'checked' : '' ?>>
                                    <?= $esc($valueLabel($field, $option['value'])) ?>
                                    <?php if ($option['count'] !== null): ?>
                                        <span style="opacity:.55">(<?= $esc(number_format($option['count'])) ?>)</span>
                                    <?php endif; ?>
                                    <?php if ($option['stale']): ?>
                                        <em style="opacity:.7"><?= $esc($t('— saved, but not in the index')) ?></em>
                                    <?php endif; ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * The option list for one picker: the field's own values, plus any
     * already-saved value the index doesn't (or can't) offer.
     *
     * That last part is the whole reason this isn't inline. A saved value
     * missing from the rendered list posts back as unchecked, so re-saving the
     * block would silently drop it — losing a curated scope to a newspaper
     * leaving the corpus, or to Typesense being down at the wrong moment.
     * Rendering it checked and flagged keeps the save round-trip lossless.
     *
     * @param  array<string, list<array{value: string, count: int}>>|null $counts
     * @param  list<string> $selected
     * @return list<array{value: string, count: ?int, stale: bool}>
     */
    private function pickerOptions(string $field, ?array $counts, array $selected): array
    {
        $live = $counts[$field] ?? [];

        $liveCounts = [];
        foreach ($live as $entry) {
            $liveCounts[$entry['value']] = $entry['count'];
        }

        // Closed enums keep their declared order (which is meaningful — the
        // subjectivity scale runs 1→5); open vocabularies keep Typesense's
        // count-descending order.
        $static = ScopeFilters::staticOptions($field);
        $values = $static !== [] ? $static : array_column($live, 'value');

        $out  = [];
        $seen = [];
        foreach ($values as $value) {
            $seen[$value] = true;
            $out[] = [
                'value' => $value,
                'count' => $liveCounts[$value] ?? null,
                'stale' => false,
            ];
        }
        foreach ($selected as $value) {
            if (!isset($seen[$value])) {
                $out[] = ['value' => $value, 'count' => null, 'stale' => true];
            }
        }

        return $out;
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
    /**
     * @param  string $templateViewScript
     * @return string Rendered block markup.
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
