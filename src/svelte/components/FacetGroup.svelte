<script module lang="ts">
  // Per-instance counter so each group's collapsible body gets a page-unique
  // id for the heading's aria-controls (multiple search surfaces can render
  // the same facet field on one page).
  let groupUid = 0;
  function nextFacetBodyId(): string {
    return `iwac-facet-body-${++groupUid}`;
  }

  function formatCount(n: number): string {
    return new Intl.NumberFormat().format(n);
  }

  /**
   * Lowercase, strip diacritics — so typing "cote" matches "Côte d'Ivoire"
   * and "moham" matches "Mohammed" / "Mahomet" in any IWAC locale. We do
   * this on every keystroke for the visible facet list (capped at 50 by
   * `max_facet_values`), which is cheap.
   */
  function fold(s: string): string {
    return s
      .normalize('NFKD')
      .replace(/\p{Diacritic}/gu, '')
      .toLowerCase();
  }
</script>

<script lang="ts">
  import type { IwacFacetCount } from '../lib/types';
  import { facetLabel, facetValueLabel, useI18n } from '../lib/i18n';
  import { MAX_FACET_VALUES } from '../lib/typesense';
  import Icon from './Icon.svelte';

  /**
   * One facet field rendered as a collapsible checklist with an optional
   * per-facet search box.
   *
   *   COUNTRY                         (2) ▾
   *   ─────────────────────────────────────
   *   ☑ Burkina Faso             1,234
   *   ☐ Côte d'Ivoire              892
   *   ☐ Niger                      450
   *   Show 7 more
   *
   * Behavioural choices:
   *   - Heading is the click target (bigger hit area than a chevron
   *     alone) and renders as a small-caps eyebrow so the column reads
   *     like a sequence of editorial sections, not a config dialog.
   *   - When the facet has more than `searchThreshold` values (default 10),
   *     a small search box appears above the list. If the field holds more
   *     values than were loaded (totalValues > counts.length) AND a server
   *     search callback is wired, typing queries Typesense (facet_query) so a
   *     value beyond the loaded top-N — e.g. an author past the first 50 — is
   *     still findable. Otherwise typing filters the loaded values
   *     client-side using diacritic-insensitive substring matching, so "cote"
   *     finds "Côte d'Ivoire". Selected values stay visible regardless.
   *   - "Show more" reveals the rest of the loaded values. With a query
   *     active, the show-more affordance is replaced by a match-count hint.
   *   - Counts come from Typesense's facet_counts response — they're
   *     post-filter, so they update as other facets are toggled.
   *
   * Theme defence: the IWAC theme paints every <button> with primary
   * orange + glow-sm + hover-translate. Our class-level overrides win on
   * specificity, but we also explicitly zero out `box-shadow` and
   * `transform` on hover so the theme can't leak through.
   */

  interface Props {
    field: string;
    counts: IwacFacetCount[];
    /**
     * Total distinct values for this facet in the current result set
     * (Typesense facet stats.total_values). When it exceeds the loaded
     * `counts.length`, the field has MORE values than were returned, so the
     * in-facet search routes to the server to reach them.
     */
    totalValues?: number;
    selected: string[];
    /** Initially-shown count before "show more" expands. */
    visibleByDefault?: number;
    /** Show the in-facet search box once counts.length exceeds this. */
    searchThreshold?: number;
    onToggle: (field: string, value: string, nextChecked: boolean) => void;
    /**
     * Search this field's values server-side (Typesense facet_query), so a
     * value beyond the loaded top-N is still findable. Absent → the search
     * box filters the loaded values client-side only.
     */
    onFacetSearch?: (field: string, text: string) => Promise<IwacFacetCount[]>;
    /** Optional override; defaults to the locale label table. */
    label?: string;
    /**
     * Value ordering. 'count' (default) sorts by descending doc count;
     * 'value-asc' sorts numerically ascending — used for the 1–5
     * subjectivity scale, where a count order ("2, 1, 4, 3, 5") reads as
     * noise.
     */
    sortMode?: 'count' | 'value-asc';
  }

  const {
    field,
    counts,
    totalValues,
    selected,
    visibleByDefault = 8,
    searchThreshold = 10,
    onToggle,
    onFacetSearch,
    label,
    sortMode = 'count',
  }: Props = $props();

  const { locale, t } = useI18n();

  const heading = $derived(label ?? facetLabel(field, locale));
  const selectedSet = $derived(new Set(selected));
  const bodyId = nextFacetBodyId();
  let expanded = $state(false);
  let collapsed = $state(false); // user-collapsed (whole group)
  let filterText = $state('');

  // Sort: selected first (so toggling doesn't make a value vanish under
  // the "show more" fold), then by the chosen order (count desc, or
  // numeric ascending for scale facets like subjectivity). Shared by the
  // loaded list and the server search results.
  function orderValues(list: IwacFacetCount[]): IwacFacetCount[] {
    return [...list].sort((a, b) => {
      const aSel = selectedSet.has(a.value);
      const bSel = selectedSet.has(b.value);
      if (aSel !== bSel) return aSel ? -1 : 1;
      if (sortMode === 'value-asc') return Number(a.value) - Number(b.value);
      return b.count - a.count;
    });
  }

  const sorted = $derived(orderValues(counts));

  // Client-side filter over the loaded values. Selected values always pass so
  // unchecking one mid-search doesn't lose the row.
  const localFiltered = $derived.by(() => {
    const q = fold(filterText.trim());
    if (!q) return sorted;
    return sorted.filter((fc) => selectedSet.has(fc.value) || fold(fc.value).includes(q));
  });

  const isFiltering = $derived(filterText.trim() !== '');
  const showSearch = $derived(counts.length > searchThreshold);

  // Does the facet hold more values than were loaded? If so, the search box
  // must hit the server to reach them; otherwise the loaded list is complete
  // (a full max_facet_values page implies truncation when total_values isn't
  // reported).
  const hasMoreValues = $derived(
    totalValues != null ? totalValues > counts.length : counts.length >= MAX_FACET_VALUES,
  );
  const useServerSearch = $derived(!!onFacetSearch && hasMoreValues);

  // ── Server-side value search (debounced) ─────────────────────────────
  let serverCounts = $state<IwacFacetCount[] | null>(null);
  let searchLoading = $state(false);
  let searchSeq = 0; // race guard — only the latest request may set state

  $effect(() => {
    const text = filterText.trim();
    // Bump first so any in-flight request from a previous run is invalidated,
    // even when this run early-returns (e.g. the box was just cleared).
    const seq = ++searchSeq;
    if (!useServerSearch || !onFacetSearch || text === '') {
      serverCounts = null;
      searchLoading = false;
      return;
    }
    searchLoading = true;
    const timer = window.setTimeout(() => {
      onFacetSearch(field, text)
        .then((c) => {
          if (seq === searchSeq) serverCounts = c;
        })
        .catch(() => {
          // Show "no matches" rather than stale/incorrect values on error.
          if (seq === searchSeq) serverCounts = [];
        })
        .finally(() => {
          if (seq === searchSeq) searchLoading = false;
        });
    }, 250);
    return () => clearTimeout(timer);
  });

  const serverSorted = $derived(orderValues(serverCounts ?? []));

  // When the server search is active, its results replace the local list
  // entirely (they already span the whole field, not just the loaded top-N).
  const usingServerResults = $derived(useServerSearch && isFiltering);
  const visible = $derived.by(() => {
    if (usingServerResults) return serverSorted;
    if (isFiltering || expanded) return localFiltered;
    return localFiltered.slice(0, visibleByDefault);
  });
  const hiddenCount = $derived(
    usingServerResults ? 0 : Math.max(0, localFiltered.length - visibleByDefault),
  );
  // A server search is mid-flight with nothing to show yet → render a
  // "searching…" placeholder instead of a premature "no matches".
  const searchPending = $derived(usingServerResults && searchLoading && visible.length === 0);

  // Bound the list height (with its own scroll) once it shows more than the
  // default truncated view — i.e. after "show more" or while searching — so a
  // 50- (or up to 100-) value facet doesn't shove the groups below it
  // off-screen. The default 8-item view is never bounded, so this does NOT
  // bring back a scrollbar on every facet.
  const listBounded = $derived(expanded || isFiltering);
</script>

<section class="iwac-facet" class:iwac-facet--collapsed={collapsed}>
  <button
    type="button"
    class="iwac-facet__heading"
    aria-expanded={!collapsed}
    aria-controls={!collapsed ? bodyId : undefined}
    onclick={() => (collapsed = !collapsed)}
  >
    <span class="iwac-facet__label">{heading}</span>
    {#if selected.length > 0}
      <span class="iwac-facet__active-count" aria-label={t('n_active', { n: selected.length })}>
        {selected.length}
      </span>
    {/if}
    <span class="iwac-facet__chevron" aria-hidden="true"
      ><Icon name={collapsed ? 'chevron-right' : 'chevron-down'} /></span
    >
  </button>

  {#if !collapsed}
    <div id={bodyId}>
      {#if counts.length === 0}
        <p class="iwac-facet__empty">{t('no_values')}</p>
      {:else}
        {#if showSearch}
          <div class="iwac-facet__search">
            <input
              type="search"
              class="iwac-facet__search-input"
              placeholder={t('search_values', { name: heading.toLowerCase() })}
              aria-label={t('filter_values', { name: heading })}
              bind:value={filterText}
            />
            {#if isFiltering}
              <button
                type="button"
                class="iwac-facet__search-clear"
                aria-label={t('clear_filter')}
                onclick={() => (filterText = '')}
              >
                <Icon name="x" />
              </button>
            {/if}
          </div>
        {/if}

        {#if searchPending}
          <p class="iwac-facet__empty" aria-live="polite">{t('searching')}</p>
        {:else if visible.length === 0}
          <p class="iwac-facet__empty">{t('no_matches')}</p>
        {:else}
          <ul class="iwac-facet__list" class:iwac-facet__list--bounded={listBounded}>
            {#each visible as fc (fc.value)}
              <li class="iwac-facet__item">
                <label class="iwac-facet__option" class:is-selected={selectedSet.has(fc.value)}>
                  <input
                    type="checkbox"
                    class="iwac-facet__checkbox"
                    checked={selectedSet.has(fc.value)}
                    onchange={(e) =>
                      onToggle(field, fc.value, (e.currentTarget as HTMLInputElement).checked)}
                  />
                  <span class="iwac-facet__value">{facetValueLabel(field, fc.value, locale)}</span>
                  <span class="iwac-facet__count">{formatCount(fc.count)}</span>
                </label>
              </li>
            {/each}
          </ul>
        {/if}

        {#if usingServerResults}
          {#if visible.length > 0}
            <p class="iwac-facet__hint" aria-live="polite">
              {searchLoading ? t('searching') : t('facet_search_count', { n: visible.length })}
            </p>
          {/if}
        {:else if isFiltering}
          <p class="iwac-facet__hint">
            {t('match_count', { shown: localFiltered.length, total: counts.length })}
          </p>
        {:else if hiddenCount > 0}
          <button type="button" class="iwac-facet__more" onclick={() => (expanded = !expanded)}>
            {expanded ? t('show_less') : t('show_more', { n: hiddenCount })}
          </button>
        {/if}
      {/if}
    </div>
  {/if}
</section>

<style>
  .iwac-facet {
    padding-block: var(--space-md, 1rem);
    border-bottom: 1px solid var(--border-light, #e2e5e8);
  }
  .iwac-facet:last-child {
    border-bottom: none;
  }

  /*
   * Heading button. The IWAC theme paints every <button> primary + glow,
   * so we explicitly nuke background, padding, shadow, and the hover
   * translate to keep the heading reading like an eyebrow label.
   */
  .iwac-facet__heading {
    display: flex;
    align-items: center;
    gap: var(--space-xs, 0.25rem);
    width: 100%;
    padding: 0;
    background: none;
    border: none;
    box-shadow: none;
    cursor: pointer;
    font: inherit;
    color: var(--ink-strong, #05070c);
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 700;
    letter-spacing: var(--tracking-wider, 0.08em);
    text-transform: uppercase;
    text-align: start;
    transition: color var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1));
  }
  .iwac-facet__heading:hover {
    color: var(--primary, #ce4115);
    background: none;
    box-shadow: none;
    transform: none;
  }
  .iwac-facet__heading:focus-visible {
    outline: var(--focus-outline, 2px solid #ce4115);
    outline-offset: 2px;
  }
  .iwac-facet__label {
    flex: 1;
  }
  .iwac-facet__active-count {
    color: var(--primary, #ce4115);
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    letter-spacing: 0;
    text-transform: none;
  }
  .iwac-facet__chevron {
    display: inline-flex;
    align-items: center;
    color: var(--muted, #66696e);
    font-size: var(--text-xs, 0.8125rem);
  }

  .iwac-facet__search {
    position: relative;
    margin-block: var(--space-sm, 0.5rem) var(--space-xs, 0.25rem);
  }
  .iwac-facet__search-input {
    width: 100%;
    /* Theme global field rule adds margin-bottom — the wrapper owns rhythm. */
    margin: 0;
    height: var(--size-control-md, 2.5rem);
    padding-inline: var(--space-sm, 0.5rem);
    padding-inline-end: var(--space-xl, 2rem);
    background: var(--surface, #fdfcfb);
    color: var(--ink, #13161c);
    border: 1px solid var(--border, #ced1d6);
    border-radius: var(--radius-md, 0.5rem);
    font: inherit;
    font-size: var(--text-sm, 0.9375rem);
    box-shadow: none;
    transition:
      border-color var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1)),
      box-shadow var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1));
  }
  .iwac-facet__search-input::placeholder {
    color: var(--muted, #66696e);
  }
  /* Hide the browser's native type=search clear glyph — we render our
     own .iwac-facet__search-clear button, so the native one is a
     duplicate × sitting right beside it. */
  .iwac-facet__search-input::-webkit-search-cancel-button {
    -webkit-appearance: none;
    appearance: none;
    display: none;
  }
  .iwac-facet__search-input:focus {
    border-color: var(--primary, #ce4115);
    outline: var(--focus-outline, 2px solid #ce4115);
    outline-offset: 2px;
  }
  .iwac-facet__search-clear {
    position: absolute;
    inset-inline-end: var(--space-xs, 0.25rem);
    inset-block-start: 50%;
    transform: translateY(-50%);
    width: 1.5rem;
    height: 1.5rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    background: transparent;
    color: var(--muted, #66696e);
    border: none;
    border-radius: var(--radius-full, 9999px);
    box-shadow: none;
    font-size: var(--text-base, 1.0625rem);
    line-height: 1;
    cursor: pointer;
    transition:
      color var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1)),
      background var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1));
  }
  .iwac-facet__search-clear:hover {
    background: var(--surface-sunken, #f4f1ef);
    color: var(--ink, #13161c);
    transform: translateY(-50%);
    box-shadow: none;
  }
  .iwac-facet__search-clear:focus-visible {
    outline: var(--focus-outline, 2px solid #ce4115);
    outline-offset: 2px;
  }

  .iwac-facet__list {
    list-style: none;
    margin: var(--space-xs, 0.25rem) 0 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 0.0625rem;
    /* The default (truncated) list grows to its natural height — no inner
       scroll, so a column of collapsed facets shows the single sidebar
       scroll only (see .iwac-search__facets-inline in App.svelte). Only the
       EXPANDED / searching list gets its own bounded scroll (below). */
  }
  /*
   * Expanded ("show more") or searching: the list can run to ~50 loaded
   * values — or up to 100 server matches — which would push every facet
   * below it off-screen. Bound the height and let it scroll WITHIN the
   * group. Only this state scrolls; the default 8-item view never does, so
   * we don't reintroduce the old "a scrollbar on every facet" problem.
   * overscroll-behavior keeps the scroll from chaining into the sidebar at
   * the ends; the inline padding keeps option focus rings from being clipped.
   */
  .iwac-facet__list--bounded {
    max-height: min(24rem, 60vh);
    overflow-y: auto;
    overscroll-behavior: contain;
    scrollbar-width: thin;
    scrollbar-gutter: stable;
    padding-inline: 0.1875rem;
  }
  .iwac-facet__item {
    margin: 0;
  }
  .iwac-facet__option {
    display: grid;
    grid-template-columns: auto 1fr auto;
    gap: var(--space-sm, 0.5rem);
    align-items: center;
    padding: 0.375rem var(--space-xs, 0.25rem);
    border-radius: var(--radius-sm, 0.375rem);
    cursor: pointer;
    color: var(--ink, #13161c);
    font-size: var(--text-sm, 0.9375rem);
    line-height: 1.4;
    transition: background var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1));
  }
  .iwac-facet__option:hover {
    background: var(--surface-sunken, #f4f1ef);
  }
  .iwac-facet__option.is-selected {
    color: var(--ink-strong, #05070c);
    font-weight: 500;
  }
  /* The row, not the (visually small) checkbox, carries the indicator — the
     whole <label> is the click target. Room for the outset ring comes from
     .iwac-facet__list--bounded's inline padding here and from the facet
     column's own inline padding in App.svelte; both are scroll containers,
     which clip ink drawn outside their padding box. */
  .iwac-facet__option:has(input:focus-visible) {
    outline: var(--focus-outline, 2px solid #ce4115);
    outline-offset: 2px;
  }
  .iwac-facet__checkbox {
    /* Restore the native control completely. The IWAC theme globally turns
       checkboxes into custom grid controls and shifts them down by 0.2em;
       those declarations otherwise leak into this component and misalign
       the square with its label. The local grid owns centering, while the
       native control keeps platform focus/checked/high-contrast behaviour. */
    appearance: auto;
    -webkit-appearance: checkbox;
    display: inline-block;
    place-content: normal;
    box-sizing: border-box;
    accent-color: var(--primary, #ce4115);
    width: 1rem;
    height: 1rem;
    margin: 0;
    padding: 0;
    border: 0;
    border-radius: 0;
    background: transparent;
    box-shadow: none;
    transform: none;
    vertical-align: middle;
    cursor: pointer;
  }
  .iwac-facet__option .iwac-facet__checkbox::before {
    /* Suppress the pseudo-element used by the theme's custom checkbox. */
    content: none;
  }
  .iwac-facet__value {
    overflow-wrap: anywhere;
  }
  .iwac-facet__count {
    color: var(--muted, #66696e);
    font-variant-numeric: tabular-nums;
    font-size: var(--text-xs, 0.8125rem);
  }
  .iwac-facet__option.is-selected .iwac-facet__count {
    color: var(--ink-light, #3f4349);
  }

  .iwac-facet__more {
    margin-top: var(--space-sm, 0.5rem);
    background: none;
    border: none;
    box-shadow: none;
    color: var(--primary, #ce4115);
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 500;
    cursor: pointer;
    padding: var(--space-xs, 0.25rem) 0;
    transition: color var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1));
  }
  .iwac-facet__more:hover {
    background: none;
    box-shadow: none;
    transform: none;
    text-decoration: underline;
    text-underline-offset: 2px;
  }
  .iwac-facet__more:focus-visible {
    outline: var(--focus-outline, 2px solid #ce4115);
    outline-offset: 2px;
  }

  .iwac-facet__hint {
    margin: var(--space-xs, 0.25rem) 0 0;
    color: var(--muted, #66696e);
    font-size: var(--text-xs, 0.8125rem);
    font-variant-numeric: tabular-nums;
  }

  .iwac-facet__empty {
    color: var(--muted, #66696e);
    font-size: var(--text-sm, 0.9375rem);
    margin: var(--space-xs, 0.25rem) 0 0;
    padding: var(--space-xs, 0.25rem);
  }
</style>
