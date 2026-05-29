<script module lang="ts">
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
  import { facetLabel, useI18n } from '../lib/i18n';

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
   *     a small search box appears above the list. Typing filters the
   *     visible options client-side using diacritic-insensitive substring
   *     matching, so "cote" finds "Côte d'Ivoire". Selected values stay
   *     visible regardless of the filter — toggling them off shouldn't
   *     make them vanish from the panel.
   *   - "Show more" reveals all values up to Typesense's max_facet_values
   *     (50). When a search query is active, the show-more affordance is
   *     replaced by an "out of N" hint so the user knows how many matched
   *     their filter.
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
    selected: string[];
    /** Initially-shown count before "show more" expands. */
    visibleByDefault?: number;
    /** Show the in-facet search box once counts.length exceeds this. */
    searchThreshold?: number;
    onToggle: (field: string, value: string, nextChecked: boolean) => void;
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
    selected,
    visibleByDefault = 8,
    searchThreshold = 10,
    onToggle,
    label,
    sortMode = 'count',
  }: Props = $props();

  const { locale, t } = useI18n();

  const heading = $derived(label ?? facetLabel(field, locale));
  const selectedSet = $derived(new Set(selected));
  let expanded = $state(false);
  let collapsed = $state(false); // user-collapsed (whole group)
  let filterText = $state('');

  // Sort: selected first (so toggling doesn't make a value vanish under
  // the "show more" fold), then by the chosen order (count desc, or
  // numeric ascending for scale facets like subjectivity).
  const sorted = $derived.by(() => {
    return [...counts].sort((a, b) => {
      const aSel = selectedSet.has(a.value);
      const bSel = selectedSet.has(b.value);
      if (aSel !== bSel) return aSel ? -1 : 1;
      if (sortMode === 'value-asc') return Number(a.value) - Number(b.value);
      return b.count - a.count;
    });
  });

  // Apply the in-facet search filter. Selected values always pass the
  // filter so unchecking one mid-search doesn't lose the row.
  const filtered = $derived.by(() => {
    const q = fold(filterText.trim());
    if (!q) return sorted;
    return sorted.filter((fc) => selectedSet.has(fc.value) || fold(fc.value).includes(q));
  });

  const isFiltering = $derived(filterText.trim() !== '');
  const showSearch = $derived(counts.length > searchThreshold);
  const visible = $derived(
    isFiltering || expanded ? filtered : filtered.slice(0, visibleByDefault),
  );
  const hiddenCount = $derived(Math.max(0, filtered.length - visibleByDefault));
</script>

<section class="iwac-facet" class:iwac-facet--collapsed={collapsed}>
  <button
    type="button"
    class="iwac-facet__heading"
    aria-expanded={!collapsed}
    onclick={() => (collapsed = !collapsed)}
  >
    <span class="iwac-facet__label">{heading}</span>
    {#if selected.length > 0}
      <span class="iwac-facet__active-count" aria-label={t('n_active', { n: selected.length })}>
        {selected.length}
      </span>
    {/if}
    <span class="iwac-facet__chevron" aria-hidden="true">{collapsed ? '▸' : '▾'}</span>
  </button>

  {#if !collapsed}
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
              ×
            </button>
          {/if}
        </div>
      {/if}

      {#if visible.length === 0}
        <p class="iwac-facet__empty">{t('no_matches')}</p>
      {:else}
        <ul class="iwac-facet__list">
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
                <span class="iwac-facet__value">{fc.value}</span>
                <span class="iwac-facet__count">{formatCount(fc.count)}</span>
              </label>
            </li>
          {/each}
        </ul>
      {/if}

      {#if isFiltering}
        <p class="iwac-facet__hint">
          {t('match_count', { shown: filtered.length, total: counts.length })}
        </p>
      {:else if hiddenCount > 0}
        <button type="button" class="iwac-facet__more" onclick={() => (expanded = !expanded)}>
          {expanded ? t('show_less') : t('show_more', { n: hiddenCount })}
        </button>
      {/if}
    {/if}
  {/if}
</section>

<style>
  .iwac-facet {
    padding-block: var(--space-md, 1rem);
    border-bottom: 1px solid var(--border-light, #eee);
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
    color: var(--ink-strong, var(--ink, #222));
    font-size: var(--text-xs, 0.75rem);
    font-weight: 700;
    letter-spacing: var(--tracking-wider, 0.08em);
    text-transform: uppercase;
    text-align: start;
    transition: color var(--transition-fast, 150ms ease);
  }
  .iwac-facet__heading:hover {
    color: var(--primary, #c66);
    background: none;
    box-shadow: none;
    transform: none;
  }
  .iwac-facet__heading:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
    border-radius: var(--radius-sm, 0.375rem);
  }
  .iwac-facet__label {
    flex: 1;
  }
  .iwac-facet__active-count {
    background: var(--primary, #c66);
    color: var(--primary-contrast, #fff);
    border-radius: var(--radius-full, 9999px);
    padding: 0 0.5rem;
    min-width: 1.25rem;
    height: 1.25rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: var(--text-xs, 0.7rem);
    font-weight: 600;
    letter-spacing: 0;
    text-transform: none;
  }
  .iwac-facet__chevron {
    color: var(--muted, #888);
    font-size: var(--text-xs, 0.75rem);
    /* Strip the heading's wide tracking — chevrons are pictograms,
       letter-spacing them moves the glyph off-centre. */
    letter-spacing: 0;
  }

  .iwac-facet__search {
    position: relative;
    margin-block: var(--space-sm, 0.5rem) var(--space-xs, 0.25rem);
  }
  .iwac-facet__search-input {
    width: 100%;
    height: var(--size-control-md, 2.5rem);
    padding-inline: var(--space-sm, 0.5rem);
    padding-inline-end: var(--space-xl, 2rem);
    background: var(--surface, #fff);
    color: var(--ink, #222);
    border: 1px solid var(--border, #ccc);
    border-radius: var(--radius-md, 0.75rem);
    font: inherit;
    font-size: var(--text-sm, 0.9rem);
    box-shadow: none;
    transition:
      border-color var(--transition-fast, 150ms ease),
      box-shadow var(--transition-fast, 150ms ease);
  }
  .iwac-facet__search-input::placeholder {
    color: var(--muted, #888);
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
    outline: none;
    border-color: var(--primary, #c66);
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
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
    color: var(--muted, #888);
    border: none;
    border-radius: var(--radius-full, 9999px);
    box-shadow: none;
    font-size: var(--text-base, 1rem);
    line-height: 1;
    cursor: pointer;
    transition:
      color var(--transition-fast, 150ms ease),
      background var(--transition-fast, 150ms ease);
  }
  .iwac-facet__search-clear:hover {
    background: var(--surface-sunken, #f0f0f0);
    color: var(--ink, #222);
    transform: translateY(-50%);
    box-shadow: none;
  }
  .iwac-facet__search-clear:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
  }

  .iwac-facet__list {
    list-style: none;
    margin: var(--space-xs, 0.25rem) 0 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 0.0625rem;
    /* No inner scroll: the list grows to its natural height and the
       "Show more / Show less" control bounds it instead. Per-facet
       scroll containers stacked on top of the sidebar scroll produced
       the "so many scrollbars" problem; the sidebar column owns the one
       scroll now (see .iwac-search__facets-inline in App.svelte). */
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
    color: var(--ink, #222);
    font-size: var(--text-sm, 0.9rem);
    line-height: 1.4;
    transition: background var(--transition-fast, 150ms ease);
  }
  .iwac-facet__option:hover {
    background: var(--surface-sunken, #f5f5f5);
  }
  .iwac-facet__option.is-selected {
    color: var(--ink-strong, var(--ink, #222));
    font-weight: 500;
  }
  .iwac-facet__option:has(input:focus-visible) {
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
  }
  .iwac-facet__checkbox {
    /* Use the theme primary as the checkbox tick colour so checked
       boxes pick up brand without a custom SVG control. */
    accent-color: var(--primary, #c66);
    width: 1rem;
    height: 1rem;
    margin: 0;
    cursor: pointer;
  }
  .iwac-facet__value {
    overflow-wrap: anywhere;
  }
  .iwac-facet__count {
    color: var(--muted, #888);
    font-variant-numeric: tabular-nums;
    font-size: var(--text-xs, 0.75rem);
  }
  .iwac-facet__option.is-selected .iwac-facet__count {
    color: var(--ink-light, var(--muted, #666));
  }

  .iwac-facet__more {
    margin-top: var(--space-sm, 0.5rem);
    background: none;
    border: none;
    box-shadow: none;
    color: var(--primary, #c66);
    font-size: var(--text-xs, 0.75rem);
    font-weight: 500;
    cursor: pointer;
    padding: var(--space-xs, 0.25rem) 0;
    transition: color var(--transition-fast, 150ms ease);
  }
  .iwac-facet__more:hover {
    background: none;
    box-shadow: none;
    transform: none;
    text-decoration: underline;
    text-underline-offset: 2px;
  }
  .iwac-facet__more:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
    border-radius: var(--radius-sm, 0.375rem);
  }

  .iwac-facet__hint {
    margin: var(--space-xs, 0.25rem) 0 0;
    color: var(--muted, #888);
    font-size: var(--text-xs, 0.75rem);
    font-variant-numeric: tabular-nums;
  }

  .iwac-facet__empty {
    color: var(--muted, #888);
    font-size: var(--text-sm, 0.9rem);
    margin: var(--space-xs, 0.25rem) 0 0;
    padding: var(--space-xs, 0.25rem);
  }
</style>
