<script lang="ts">
  import type { EntitySuggestion, IwacHit, SuggestResult } from '../lib/types';
  import type { TypesenseClient } from '../lib/typesense';
  import { facetLabel, useI18n } from '../lib/i18n';

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
   *   - Pointerdown inside the dropdown does NOT blur the parent input
   *     (intercepted via onmousedown) so a click registers before close.
   *   - The same scoped key + locked_filters apply, so suggestions respect
   *     the surface's curatorial scope.
   */
  interface Props {
    query: string;
    client: TypesenseClient;
    enabled: boolean;
    /** Seed the query with chosen text (article-with-no-URL fallback). */
    onPickQuery: (next: string) => void;
    /** Run a full-text search for the typed text and close the dropdown. */
    onRunSearch: (text: string) => void;
    /** Apply an entity as a facet filter (field + value). */
    onPickEntity: (field: string, value: string) => void;
    /** Close the dropdown (e.g. after navigation). */
    onClose: () => void;
  }

  const { query, client, enabled, onPickQuery, onRunSearch, onPickEntity, onClose }: Props =
    $props();

  const { locale, t } = useI18n();

  let articles = $state<IwacHit[]>([]);
  let entities = $state<EntitySuggestion[]>([]);
  let highlightedIndex = $state(0);
  let lastError = $state<string | null>(null);
  let debounceTimer: number | null = null;
  let inFlightToken = 0;

  // Flat, ordered row list for keyboard nav + rendering. The search action
  // is index 0 so Enter (default highlight) runs the search.
  type Row =
    | { kind: 'search' }
    | { kind: 'article'; hit: IwacHit }
    | { kind: 'entity'; entity: EntitySuggestion };

  const rows = $derived.by<Row[]>(() => {
    const out: Row[] = [{ kind: 'search' }];
    for (const hit of articles) out.push({ kind: 'article', hit });
    for (const entity of entities) out.push({ kind: 'entity', entity });
    return out;
  });

  const hasSuggestions = $derived(articles.length > 0 || entities.length > 0);

  // Strip the highlight markers Typesense embeds in the snippet so we can
  // render them with our own <mark> styling. Allow only <mark>.
  function safeMarkup(html: string | undefined): string {
    if (!html) return '';
    return html.replace(/<(?!\/?mark\b)[^>]*>/gi, '');
  }

  function titleMarkupOf(hit: IwacHit): string {
    const titleHl = hit.highlights.find((h) => h.field === 'title_txt');
    if (titleHl?.snippet) {
      return safeMarkup(titleHl.snippet);
    }
    const tx = hit.document.title ?? '';
    return tx.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function urlOf(hit: IwacHit): string | null {
    return hit.document.omeka_url ?? hit.document.source_url ?? null;
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
        .catch((e: Error) => {
          if (myToken !== inFlightToken) return;
          // Typeahead failures shouldn't bother the user; the main search
          // bar still works. Keep the search action available.
          lastError = e.message;
          articles = [];
          entities = [];
        });
    }, 120);
  });

  function act(row: Row): void {
    if (row.kind === 'search') {
      onRunSearch(query);
      onClose();
      return;
    }
    if (row.kind === 'entity') {
      onPickEntity(row.entity.field, row.entity.value);
      onClose();
      return;
    }
    // article
    const url = urlOf(row.hit);
    if (url) {
      window.location.assign(url);
      onClose();
      return;
    }
    const titleText = (row.hit.document.title ?? '').trim();
    if (titleText !== '') {
      onPickQuery(titleText);
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

  function onRowClick(row: Row, i: number, e: MouseEvent): void {
    // Let middle-click / cmd-click on an article open in a new tab.
    if (row.kind === 'article' && urlOf(row.hit) && (e.metaKey || e.ctrlKey || e.button === 1)) {
      return;
    }
    e.preventDefault();
    highlightedIndex = i;
    act(row);
  }

  function hrefOf(row: Row): string {
    return row.kind === 'article' ? (urlOf(row.hit) ?? '#') : '#';
  }

  // Stop pointerdown from blurring the input — otherwise the parent's
  // onblur handler closes the dropdown before our click can register.
  function preventBlur(e: MouseEvent): void {
    e.preventDefault();
  }
</script>

{#if enabled && query.trim().length >= 2}
  <div
    class="iwac-suggest"
    role="listbox"
    aria-label={t('suggestions')}
    tabindex="-1"
    onmousedown={preventBlur}
  >
    {#each rows as row, i (row.kind + ':' + (row.kind === 'article' ? row.hit.document.id : row.kind === 'entity' ? row.entity.field + row.entity.value : 'search'))}
      {#if row.kind === 'search'}
        <button
          type="button"
          class="iwac-suggest__item iwac-suggest__item--search"
          class:iwac-suggest__item--active={i === highlightedIndex}
          onclick={(e) => onRowClick(row, i, e)}
          onmouseenter={() => (highlightedIndex = i)}
          role="option"
          aria-selected={i === highlightedIndex}
        >
          <span class="iwac-suggest__icon" aria-hidden="true">⌕</span>
          <span class="iwac-suggest__title">{t('search_for', { q: query.trim() })}</span>
        </button>
      {:else if row.kind === 'article'}
        <a
          class="iwac-suggest__item"
          class:iwac-suggest__item--active={i === highlightedIndex}
          href={hrefOf(row)}
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
          class="iwac-suggest__item iwac-suggest__item--entity"
          class:iwac-suggest__item--active={i === highlightedIndex}
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
    {#if !hasSuggestions && lastError === null}
      <div class="iwac-suggest__empty" role="status">{t('no_matches')}</div>
    {/if}
    {#if lastError}
      <div class="iwac-suggest__error" role="status">{lastError}</div>
    {/if}
  </div>
{/if}

<style>
  .iwac-suggest {
    position: absolute;
    inset-inline: 0;
    inset-block-start: calc(100% + var(--space-2xs, 0.25rem));
    z-index: 30;
    background: var(--surface, #fdfdfd);
    border: 1px solid var(--border, #d4d6da);
    border-radius: var(--radius-md, 0.5rem);
    box-shadow:
      0 4px 12px rgba(0, 0, 0, 0.08),
      0 1px 3px rgba(0, 0, 0, 0.05);
    overflow: hidden;
    max-height: 24rem;
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
    color: var(--ink, #2c2f37);
    background: transparent;
    border: 0;
    border-bottom: 1px solid var(--border-light, #e6e7eb);
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
    background: color-mix(in oklab, var(--primary, #e64a19) 8%, var(--surface, #fdfdfd));
    box-shadow: none;
    transform: none;
    outline: none;
  }
  /* The "Search for …" action reads as the primary affordance. */
  .iwac-suggest__item--search {
    font-weight: 500;
    color: var(--ink-strong, var(--ink, #2c2f37));
  }
  .iwac-suggest__icon {
    flex-shrink: 0;
    color: var(--primary, #e64a19);
    font-size: var(--text-base, 1.0625rem);
    line-height: 1;
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
    background: color-mix(in oklab, var(--primary, #e64a19) 22%, transparent);
    color: var(--ink-strong, var(--ink, #2c2f37));
    border-radius: 0.125em;
    padding: 0 0.125em;
    font-weight: 500;
  }
  /* Entity rows get a small field-type tag (Place / Topic / …). */
  .iwac-suggest__tag {
    flex-shrink: 0;
    font-size: var(--text-xs, 0.8125rem);
    color: var(--muted, #767880);
    background: var(--surface-sunken, #f3f3f1);
    padding: 0.125rem 0.5rem;
    border-radius: var(--radius-full, 9999px);
  }
  .iwac-suggest__empty,
  .iwac-suggest__error {
    padding: var(--space-sm, 0.5rem) var(--space-md, 1rem);
    font-size: var(--text-xs, 0.8125rem);
    color: var(--muted, #767880);
    border-top: 1px solid var(--border-light, #e6e7eb);
  }
</style>
