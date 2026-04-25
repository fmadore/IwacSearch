<script lang="ts">
  import type { IwacHit } from '../lib/types';
  import type { TypesenseClient } from '../lib/typesense';

  /**
   * Floating typeahead dropdown shown just under the SearchInput.
   *
   * Lifecycle:
   *   - Hidden when `query` is empty or shorter than the suggest()
   *     min-length (handled by the client itself).
   *   - Hidden when `enabled` is false (parent toggles this on focus
   *     in / out so the dropdown disappears when the user leaves the
   *     search box but doesn't flicker mid-keystroke).
   *   - Visible when the suggest call returns at least one hit.
   *
   * UX details that matter:
   *   - 120 ms debounce so a fast typist doesn't fire one suggest per
   *     keystroke. Shorter than the main search debounce (250 ms)
   *     because the dropdown is supposed to feel snappier than the
   *     primary results.
   *   - Pointerdown inside the dropdown does NOT blur the parent input
   *     (that would close the dropdown before the click registers).
   *     Achieved by intercepting `onmousedown` at the wrapper.
   *   - Keyboard nav: ↑/↓ to highlight, Enter to navigate, Esc to close.
   *     Nav is decoupled from focus — the input stays focused while
   *     a suggestion is highlighted, so the user can keep typing.
   */
  interface Props {
    query: string;
    client: TypesenseClient;
    enabled: boolean;
    /** Tells the parent to commit the chosen text (or do nothing). */
    onPickQuery: (next: string) => void;
    /** Tells the parent to close the dropdown (e.g. after navigation). */
    onClose: () => void;
  }

  const { query, client, enabled, onPickQuery, onClose }: Props = $props();

  let hits = $state<IwacHit[]>([]);
  let highlightedIndex = $state(0);
  let lastError = $state<string | null>(null);
  let debounceTimer: number | null = null;
  let inFlightToken = 0;

  // Strip the highlight markers Typesense embeds in the snippet so we
  // can render them ourselves with our own <mark> styling. The server
  // wraps matches in <mark>; allowing only that one tag matches the
  // sanitisation policy used by ResultItem.svelte.
  function safeMarkup(html: string | undefined): string {
    if (!html) return '';
    // Allow <mark> only. Strip every other tag.
    return html
      .replace(/<(?!\/?mark\b)[^>]*>/gi, '')
      .replace(/<mark>/gi, '<mark>')
      .replace(/<\/mark>/gi, '</mark>');
  }

  function titleMarkupOf(hit: IwacHit): string {
    const titleHl = hit.highlights.find((h) => h.field === 'title_txt');
    if (titleHl?.snippet) {
      return safeMarkup(titleHl.snippet);
    }
    // Fallback to the plain title — escape it the same way Svelte would.
    const t = hit.document.title ?? '';
    return t.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function urlOf(hit: IwacHit): string | null {
    return hit.document.omeka_url ?? hit.document.source_url ?? null;
  }

  // Re-fetch suggestions whenever the query (or enabled flag) changes.
  // Reading both inside the effect makes it self-tracking; the token
  // counter `inFlightToken` discards out-of-order responses so a slow
  // 4-character call doesn't overwrite the 6-character call's results.
  $effect(() => {
    const q = query;
    const isOpen = enabled;

    if (!isOpen || q.trim().length < 2) {
      hits = [];
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
        .then((r) => {
          if (myToken !== inFlightToken) return; // superseded
          hits = r.hits;
          highlightedIndex = 0;
          lastError = null;
        })
        .catch((e: Error) => {
          if (myToken !== inFlightToken) return;
          // Swallow into a soft state — typeahead failures shouldn't
          // bother the user; the main search bar still works.
          lastError = e.message;
          hits = [];
        });
    }, 120);
  });

  // Public keydown handler — the parent passes window keydown into us
  // so navigation keys work even when focus is on the input itself.
  export function handleKeydown(e: KeyboardEvent): boolean {
    if (!enabled || hits.length === 0) {
      return false;
    }

    if (e.key === 'ArrowDown') {
      e.preventDefault();
      highlightedIndex = (highlightedIndex + 1) % hits.length;
      return true;
    }
    if (e.key === 'ArrowUp') {
      e.preventDefault();
      highlightedIndex = (highlightedIndex - 1 + hits.length) % hits.length;
      return true;
    }
    if (e.key === 'Enter') {
      const hit = hits[highlightedIndex];
      if (!hit) return false;
      const url = urlOf(hit);
      if (url) {
        e.preventDefault();
        window.location.assign(url);
        onClose();
        return true;
      }
      // No URL → fall back to seeding the query with the title. The
      // main search will then re-run with the picked text.
      const titleText = (hit.document.title ?? '').trim();
      if (titleText !== '') {
        e.preventDefault();
        onPickQuery(titleText);
        onClose();
        return true;
      }
    }
    if (e.key === 'Escape') {
      e.preventDefault();
      onClose();
      return true;
    }
    return false;
  }

  function onItemClick(hit: IwacHit, e: MouseEvent): void {
    const url = urlOf(hit);
    if (url) {
      // Let middle-click / cmd-click open in a new tab.
      if (e.metaKey || e.ctrlKey || e.button === 1) return;
      e.preventDefault();
      window.location.assign(url);
      onClose();
      return;
    }
    e.preventDefault();
    const titleText = (hit.document.title ?? '').trim();
    if (titleText !== '') {
      onPickQuery(titleText);
    }
    onClose();
  }

  // Stop pointerdown from blurring the input — otherwise the parent's
  // onblur handler closes the dropdown before our click can register.
  function preventBlur(e: MouseEvent): void {
    e.preventDefault();
  }
</script>

{#if enabled && hits.length > 0}
  <div
    class="iwac-suggest"
    role="listbox"
    aria-label="Suggestions"
    tabindex="-1"
    onmousedown={preventBlur}
  >
    {#each hits as hit, i (hit.document.id)}
      <a
        class="iwac-suggest__item"
        class:iwac-suggest__item--active={i === highlightedIndex}
        href={urlOf(hit) ?? '#'}
        onclick={(e) => onItemClick(hit, e)}
        onmouseenter={() => (highlightedIndex = i)}
        role="option"
        aria-selected={i === highlightedIndex}
      >
        <span class="iwac-suggest__title">
          <!-- eslint-disable-next-line svelte/no-at-html-tags -->
          {@html titleMarkupOf(hit)}
        </span>
        {#if hit.document.type_s}
          <span class="iwac-suggest__type">{hit.document.type_s}</span>
        {/if}
      </a>
    {/each}
    {#if lastError}
      <div class="iwac-suggest__error" role="status">
        {lastError}
      </div>
    {/if}
  </div>
{/if}

<style>
  .iwac-suggest {
    position: absolute;
    inset-inline: 0;
    inset-block-start: calc(100% + var(--space-2xs, 0.25rem));
    z-index: 30;
    background: var(--surface, #fff);
    border: 1px solid var(--border, #ccc);
    border-radius: var(--radius-md, 0.75rem);
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
  .iwac-suggest__item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-sm, 0.5rem);
    padding: var(--space-sm, 0.5rem) var(--space-md, 1rem);
    text-decoration: none;
    color: var(--ink, #222);
    border-bottom: 1px solid var(--border-light, #eee);
    transition: background 80ms ease;
    cursor: pointer;
  }
  .iwac-suggest__item:last-child {
    border-bottom: none;
  }
  .iwac-suggest__item--active,
  .iwac-suggest__item:focus-visible {
    background: color-mix(in srgb, var(--primary, #c66) 8%, var(--surface, #fff));
    outline: none;
  }
  .iwac-suggest__title {
    flex: 1;
    font-size: var(--text-sm, 0.9rem);
    line-height: 1.35;
    color: var(--ink-strong, var(--ink, #222));
    /* Single-line truncation: dropdowns rarely look right when wrapping. */
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  /* Inherited <mark> from the highlight snippet. */
  .iwac-suggest__title :global(mark) {
    background: color-mix(in srgb, var(--primary, #c66) 22%, transparent);
    color: var(--ink-strong, var(--ink, #222));
    border-radius: 0.125em;
    padding: 0 0.125em;
    font-weight: 500;
  }
  .iwac-suggest__type {
    font-size: var(--text-xs, 0.75rem);
    color: var(--muted, #666);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    flex-shrink: 0;
    background: var(--surface-sunken, #f5f5f5);
    padding: 0.125rem 0.5rem;
    border-radius: var(--radius-full, 9999px);
  }
  .iwac-suggest__error {
    padding: var(--space-sm, 0.5rem) var(--space-md, 1rem);
    font-size: var(--text-xs, 0.75rem);
    color: var(--muted, #666);
    border-top: 1px solid var(--border-light, #eee);
  }
</style>
