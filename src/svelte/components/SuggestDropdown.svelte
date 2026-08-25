<script lang="ts">
  import type { EntitySuggestion, IwacHit, SuggestResult } from '../lib/types';
  import type { TypesenseClient } from '../lib/typesense';
  import { isAbortError } from '../lib/transport';
  import {
    actionOf,
    buildHistoryRows,
    buildSuggestRows,
    rowKey,
    titleMarkupOf,
    urlOf,
    type SuggestRow,
  } from '../lib/suggestions';
  import { clearHistory, readHistory } from '../lib/searchHistory';
  import { facetLabel, useI18n } from '../lib/i18n';
  import Icon from './Icon.svelte';

  /**
   * Floating typeahead dropdown shown just under the SearchInput.
   *
   * Row model (top → bottom), all keyboard-navigable:
   *   1. "Search for «query»" — ALWAYS first and highlighted by default,
   *      so pressing Enter runs a full-text search for what was typed
   *      instead of jumping into the first suggested article (the old
   *      behaviour the brief flagged). Arrow down to reach suggestions.
   *   2. Article title hits.
   *   3. Entity suggestions (places / topics / persons / organisations)
   *      surfaced via Typesense facet_query — picking one applies it as a
   *      facet filter rather than a text query.
   *
   * UX details that matter:
   *   - 120 ms debounce so a fast typist doesn't fire one suggest per
   *     keystroke. Shorter than the main search debounce (250 ms).
   *   - `query` is the RAW box contents, not the committed search query, so
   *     the rows describe what is on screen rather than trailing it by the
   *     250 ms main debounce.
   *   - Pointerdown on a ROW does not blur the parent input (intercepted via
   *     onmousedown) so its click registers before close. This used to sit on
   *     the panel CONTAINER, which meant a press on the panel's dead space —
   *     over the toolbar it covers — also held focus and kept the panel open,
   *     so the occluded control could not be reached by clicking at all.
   *   - The same scoped key + locked_filters apply, so suggestions respect
   *     the surface's curatorial scope.
   */
  interface Props {
    query: string;
    client: TypesenseClient;
    enabled: boolean;
    /**
     * Stable id of this listbox, shared with the SearchInput's aria-controls.
     * Option ids derive from it so the input's aria-activedescendant can point
     * at the highlighted row.
     */
    listboxId: string;
    /** Seed the query with chosen text (article-with-no-URL fallback). */
    onPickQuery: (next: string) => void;
    /** Run a full-text search for the typed text and close the dropdown. */
    onRunSearch: (text: string) => void;
    /** Apply an entity as a facet filter (field + value). */
    onPickEntity: (field: string, value: string) => void;
    /** Close the dropdown (e.g. after navigation). */
    onClose: () => void;
    /**
     * Report the active option's id (or null when the listbox is closed/empty)
     * so the parent can mirror it onto the input's aria-activedescendant.
     */
    onActiveChange?: (id: string | null) => void;
  }

  const {
    query,
    client,
    enabled,
    listboxId,
    onPickQuery,
    onRunSearch,
    onPickEntity,
    onClose,
    onActiveChange,
  }: Props = $props();

  /** Stable per-row id for aria-activedescendant wiring. */
  const optionId = (i: number): string => `${listboxId}-opt-${i}`;

  const { locale, t } = useI18n();

  let articles = $state<IwacHit[]>([]);
  let entities = $state<EntitySuggestion[]>([]);
  let highlightedIndex = $state(0);
  let lastError = $state<string | null>(null);
  let debounceTimer: number | null = null;
  let inFlightToken = 0;

  // Recent searches for the empty-focused state, refreshed each time the
  // dropdown (re)opens so a search committed elsewhere shows up.
  let history = $state<string[]>([]);
  $effect(() => {
    if (enabled) history = readHistory();
  });

  // Two row modes share one flat, keyboard-navigable list (shared model in
  // lib/suggestions.ts): a typed prefix builds search + article + entity
  // rows (the search action first, so Enter runs the search); an EMPTY
  // focused box shows recent searches instead.
  const isPrefixMode = $derived(query.trim().length >= 2);
  const rows = $derived.by<SuggestRow[]>(() =>
    isPrefixMode ? buildSuggestRows(query, articles, entities) : buildHistoryRows(history),
  );

  const hasSuggestions = $derived(articles.length > 0 || entities.length > 0);

  function handleClearHistory(): void {
    clearHistory();
    history = [];
  }

  // Re-fetch suggestions whenever the query (or enabled flag) changes.
  $effect(() => {
    const q = query;
    const isOpen = enabled;

    if (!isOpen || q.trim().length < 2) {
      articles = [];
      entities = [];
      lastError = null;
      highlightedIndex = 0;
      return;
    }

    if (debounceTimer !== null) {
      clearTimeout(debounceTimer);
    }
    const myToken = ++inFlightToken;
    debounceTimer = window.setTimeout(() => {
      debounceTimer = null;
      client
        .suggest(q)
        .then((r: SuggestResult) => {
          if (myToken !== inFlightToken) return; // superseded
          articles = r.articles;
          entities = r.entities;
          highlightedIndex = 0; // re-arm on the search action
          lastError = null;
        })
        .catch((e: unknown) => {
          if (myToken !== inFlightToken) return;
          // A superseded (aborted) request means a newer one is in flight —
          // never an error state. Other typeahead failures shouldn't bother
          // the user either; the main search bar still works.
          if (isAbortError(e)) return;
          lastError = e instanceof Error ? e.message : String(e);
          articles = [];
          entities = [];
        });
    }, 120);
  });

  // Mirror the active option's id onto the parent so it can set the input's
  // aria-activedescendant; null when closed/empty so the input drops the
  // stale reference.
  $effect(() => {
    onActiveChange?.(enabled && rows.length > 0 ? optionId(highlightedIndex) : null);
  });

  function act(row: SuggestRow): void {
    const action = actionOf(row);
    switch (action.type) {
      case 'run-search':
        if (action.query !== '') onRunSearch(action.query);
        break;
      case 'pick-entity':
        onPickEntity(action.field, action.value);
        break;
      case 'navigate':
        window.location.assign(action.url);
        break;
      case 'pick-query':
        onPickQuery(action.query);
        break;
    }
    onClose();
  }

  // Public keydown handler — the parent passes input keydown into us so
  // navigation works while focus stays on the <input>.
  export function handleKeydown(e: KeyboardEvent): boolean {
    if (!enabled || rows.length === 0) {
      return false;
    }
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      highlightedIndex = (highlightedIndex + 1) % rows.length;
      return true;
    }
    if (e.key === 'ArrowUp') {
      e.preventDefault();
      highlightedIndex = (highlightedIndex - 1 + rows.length) % rows.length;
      return true;
    }
    if (e.key === 'Enter') {
      const row = rows[highlightedIndex] ?? rows[0];
      // Article rows with a real URL hand off to the browser via
      // location.assign in act(); for those we must preventDefault so the
      // form doesn't also submit. Search/entity rows always preventDefault.
      e.preventDefault();
      act(row);
      return true;
    }
    if (e.key === 'Escape') {
      e.preventDefault();
      onClose();
      return true;
    }
    return false;
  }

  function onRowClick(row: SuggestRow, i: number, e: MouseEvent): void {
    // Let middle-click / cmd-click on an article open in a new tab.
    if (row.kind === 'article' && urlOf(row.hit) && (e.metaKey || e.ctrlKey || e.button === 1)) {
      return;
    }
    e.preventDefault();
    highlightedIndex = i;
    act(row);
  }

  function hrefOf(row: SuggestRow): string {
    return row.kind === 'article' ? (urlOf(row.hit) ?? '#') : '#';
  }

  // Stop pointerdown on a ROW from blurring the input — otherwise the parent's
  // onblur handler closes the dropdown before the click can register. Bound
  // per row, never on the container: the container's dead space is precisely
  // where a press aimed at the occluded toolbar lands, and suppressing the
  // blur there is what made the panel un-dismissable by pointer.
  function preventBlur(e: MouseEvent): void {
    e.preventDefault();
  }
</script>

{#if enabled && rows.length > 0}
  <div
    class="iwac-suggest"
    id={listboxId}
    role="listbox"
    aria-label={isPrefixMode ? t('suggestions') : t('recent_searches')}
    tabindex="-1"
  >
    {#if !isPrefixMode}
      <div class="iwac-suggest__heading" role="presentation">
        <span>{t('recent_searches')}</span>
        <!-- Mouse/touch affordance; focus stays on the input (combobox
             pattern), so this is deliberately not arrow-navigable. -->
        <button type="button" class="iwac-suggest__clear" onclick={handleClearHistory}>
          {t('clear_history')}
        </button>
      </div>
    {/if}
    {#each rows as row, i (rowKey(row))}
      {#if row.kind === 'search'}
        <button
          type="button"
          id={optionId(i)}
          class="iwac-suggest__item iwac-suggest__item--search"
          class:iwac-suggest__item--active={i === highlightedIndex}
          onmousedown={preventBlur}
          onclick={(e) => onRowClick(row, i, e)}
          onmouseenter={() => (highlightedIndex = i)}
          role="option"
          aria-selected={i === highlightedIndex}
        >
          <span class="iwac-suggest__icon" aria-hidden="true"><Icon name="search" /></span>
          <span class="iwac-suggest__title">{t('search_for', { q: query.trim() })}</span>
        </button>
      {:else if row.kind === 'history'}
        <button
          type="button"
          id={optionId(i)}
          class="iwac-suggest__item iwac-suggest__item--history"
          class:iwac-suggest__item--active={i === highlightedIndex}
          onmousedown={preventBlur}
          onclick={(e) => onRowClick(row, i, e)}
          onmouseenter={() => (highlightedIndex = i)}
          role="option"
          aria-selected={i === highlightedIndex}
        >
          <span class="iwac-suggest__icon iwac-suggest__icon--muted" aria-hidden="true">
            <Icon name="clock" />
          </span>
          <span class="iwac-suggest__title">{row.query}</span>
        </button>
      {:else if row.kind === 'article'}
        <a
          id={optionId(i)}
          class="iwac-suggest__item"
          class:iwac-suggest__item--active={i === highlightedIndex}
          href={hrefOf(row)}
          onmousedown={preventBlur}
          onclick={(e) => onRowClick(row, i, e)}
          onmouseenter={() => (highlightedIndex = i)}
          role="option"
          aria-selected={i === highlightedIndex}
        >
          <span class="iwac-suggest__title">
            <!-- eslint-disable-next-line svelte/no-at-html-tags -->
            {@html titleMarkupOf(row.hit)}
          </span>
        </a>
      {:else}
        <button
          type="button"
          id={optionId(i)}
          class="iwac-suggest__item iwac-suggest__item--entity"
          class:iwac-suggest__item--active={i === highlightedIndex}
          onmousedown={preventBlur}
          onclick={(e) => onRowClick(row, i, e)}
          onmouseenter={() => (highlightedIndex = i)}
          role="option"
          aria-selected={i === highlightedIndex}
        >
          <span class="iwac-suggest__title">{row.entity.value}</span>
          <span class="iwac-suggest__tag">{facetLabel(row.entity.field, locale)}</span>
        </button>
      {/if}
    {/each}
    {#if isPrefixMode && !hasSuggestions && lastError === null}
      <div class="iwac-suggest__empty" role="status">{t('no_matches')}</div>
    {/if}
    {#if isPrefixMode && lastError}
      <div class="iwac-suggest__error" role="status">{lastError}</div>
    {/if}
  </div>
{/if}

<style>
  .iwac-suggest {
    position: absolute;
    inset-inline: 0;
    inset-block-start: calc(100% + var(--space-xs, 0.25rem));
    z-index: 30;
    background: var(--surface, #fdfcfb);
    border: 1px solid var(--border, #ced1d6);
    border-radius: var(--radius-md, 0.5rem);
    /* Published overlay shadow (dark-variant aware), not a hand-rolled pair. */
    box-shadow: var(
      --shadow-lg,
      0 10px 15px -3px rgba(9, 11, 15, 0.12),
      0 4px 6px -4px rgba(20, 22, 27, 0.06)
    );
    overflow: hidden;
    /* Bounded against the VIEWPORT too, not just an absolute cap: 24rem is two
       thirds of a 375×812 phone screen, and the panel is floating over the
       results the reader came for. Matches the masthead enhancer's clamp. */
    max-height: min(24rem, 60vh);
    overflow-y: auto;
    animation: iwac-suggest-in 120ms ease;
  }
  @keyframes iwac-suggest-in {
    from {
      opacity: 0;
      transform: translateY(-4px);
    }
    to {
      opacity: 1;
      transform: translateY(0);
    }
  }
  /*
   * Rows are a mix of <a> (article hits → real links, new-tab friendly)
   * and <button> (the "Search for…" + entity actions). The theme paints
   * every <button> primary, so this rule resets appearance/border/
   * background/shadow for both element types to one shared look.
   */
  .iwac-suggest__item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-sm, 0.5rem);
    width: 100%;
    margin: 0;
    padding: var(--space-sm, 0.5rem) var(--space-md, 1rem);
    appearance: none;
    -webkit-appearance: none;
    text-decoration: none;
    text-align: start;
    color: var(--ink, #13161c);
    background: transparent;
    border: 0;
    border-bottom: 1px solid var(--border-light, #e2e5e8);
    box-shadow: none;
    font: inherit;
    transition: background 80ms ease;
    cursor: pointer;
  }
  .iwac-suggest__item:last-child {
    border-bottom: none;
  }
  .iwac-suggest__item--active,
  .iwac-suggest__item:hover,
  .iwac-suggest__item:focus-visible {
    background: color-mix(in oklab, var(--primary, #ce4115) 8%, var(--surface, #fdfcfb));
    box-shadow: none;
    transform: none;
  }
  /* --active is the aria-activedescendant highlight (focus stays in the
     input), so it keeps the tint alone. Real keyboard focus on the row gets a
     solid indicator, drawn INSET because the panel is rounded and clipped. */
  .iwac-suggest__item:focus-visible {
    outline: var(--focus-outline, 2px solid #ce4115);
    outline-offset: -2px;
  }
  /* The "Search for …" action reads as the primary affordance. */
  .iwac-suggest__item--search {
    font-weight: 500;
    color: var(--ink-strong, #05070c);
  }
  .iwac-suggest__icon {
    display: inline-flex;
    align-items: center;
    flex-shrink: 0;
    color: var(--primary, #ce4115);
    font-size: var(--text-base, 1.0625rem);
    line-height: 1;
  }
  .iwac-suggest__icon--muted {
    color: var(--muted, #66696e);
  }
  /* Recent-searches header row: label left, quiet clear action right. */
  .iwac-suggest__heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-sm, 0.5rem);
    padding: var(--space-xs, 0.25rem) var(--space-md, 1rem);
    font-size: var(--text-xs, 0.8125rem);
    color: var(--muted, #66696e);
    border-bottom: 1px solid var(--border-light, #e2e5e8);
  }
  .iwac-suggest__clear {
    appearance: none;
    -webkit-appearance: none;
    margin: 0;
    padding: 0.125rem 0.375rem;
    background: transparent;
    border: 0;
    border-radius: var(--radius-sm, 0.375rem);
    box-shadow: none;
    font: inherit;
    font-size: var(--text-xs, 0.8125rem);
    color: var(--muted, #66696e);
    cursor: pointer;
  }
  .iwac-suggest__clear:hover,
  .iwac-suggest__clear:focus-visible {
    color: var(--primary, #ce4115);
    background: transparent;
    box-shadow: none;
    transform: none;
    text-decoration: underline;
  }
  .iwac-suggest__clear:focus-visible {
    outline: var(--focus-outline, 2px solid #ce4115);
    outline-offset: -2px;
  }
  .iwac-suggest__title {
    flex: 1;
    font-size: var(--text-sm, 0.9375rem);
    line-height: 1.35;
    color: inherit;
    /* Single-line truncation: dropdowns rarely look right when wrapping. */
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  /* Inherited <mark> from the highlight snippet. */
  .iwac-suggest__title :global(mark) {
    background: color-mix(in oklab, var(--primary, #ce4115) 22%, transparent);
    color: var(--ink-strong, #05070c);
    border-radius: 0.125em;
    padding: 0 0.125em;
    font-weight: 500;
  }
  /* Entity rows get a small field-type tag (Place / Topic / …). */
  .iwac-suggest__tag {
    flex-shrink: 0;
    font-size: var(--text-xs, 0.8125rem);
    color: var(--muted, #66696e);
    background: var(--surface-sunken, #f4f1ef);
    padding: 0.125rem 0.5rem;
    border-radius: var(--radius-full, 9999px);
  }
  .iwac-suggest__empty,
  .iwac-suggest__error {
    padding: var(--space-sm, 0.5rem) var(--space-md, 1rem);
    font-size: var(--text-xs, 0.8125rem);
    color: var(--muted, #66696e);
    border-top: 1px solid var(--border-light, #e2e5e8);
  }
</style>
