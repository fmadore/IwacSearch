<script lang="ts">
  import type { IwacHit } from '../lib/types';

  /**
   * One result row. Title links to the Omeka item-detail page (Omeka
   * still owns item rendering — this module owns discovery only).
   *
   * Snippet sanitisation: Typesense returns highlighted HTML containing
   * <mark>...</mark> tags around matched tokens. We do NOT trust this
   * verbatim — the snippet is HTML-escaped client-side and only literal
   * <mark>/</mark> tags are reinstated. If a document somehow contained
   * embedded HTML, this rendering would treat it as text. See
   * sanitizeSnippet() below.
   */

  interface Props {
    hit: IwacHit;
  }

  const { hit }: Props = $props();

  const doc = $derived(hit.document);
  const title = $derived(doc.title || `[Untitled #${doc.id}]`);
  const dateLabel = $derived(formatDate(doc.date, doc.pub_year));
  const meta = $derived(buildMeta(doc));
  // Prefer the OCR snippet (more contextual than the title snippet).
  const rawSnippet = $derived(
    hit.highlights?.find((h) => h.field === 'ocr_text')?.snippet ??
      hit.highlights?.find((h) => h.field === 'title_txt')?.snippet ??
      '',
  );
  const snippet = $derived(sanitizeSnippet(rawSnippet));
  const itemUrl = $derived(doc.omeka_url || `/s/afrique_ouest/item/${doc.id}`);

  function formatDate(epoch?: number, year?: number): string {
    if (epoch && epoch > 0) {
      try {
        return new Date(epoch * 1000).toLocaleDateString(undefined, {
          year: 'numeric',
          month: 'long',
          day: 'numeric',
        });
      } catch {
        // fallthrough
      }
    }
    return year ? String(year) : '';
  }

  function buildMeta(d: IwacHit['document']): string[] {
    const parts: string[] = [];
    if (d.newspaper_ss?.[0]) parts.push(d.newspaper_ss[0]);
    if (d.country_ss?.[0]) parts.push(d.country_ss[0]);
    if (d.type_s) parts.push(d.type_s);
    return parts;
  }

  /**
   * Escape every HTML-significant character, then re-introduce only the
   * literal <mark>/</mark> tags Typesense uses to surround matches.
   * Anything else in the snippet — including any user content that
   * somehow contained tags — renders as text. Defense in depth: even
   * if Typesense's own escaping had a bug, this layer would catch it.
   */
  function sanitizeSnippet(html: string): string {
    if (!html) return '';
    const escaped = html.replace(
      /[&<>"']/g,
      (c) =>
        ({
          '&': '&amp;',
          '<': '&lt;',
          '>': '&gt;',
          '"': '&quot;',
          "'": '&#39;',
        })[c]!,
    );
    return escaped.replace(/&lt;mark&gt;/g, '<mark>').replace(/&lt;\/mark&gt;/g, '</mark>');
  }
</script>

<article class="iwac-hit">
  {#if doc.thumbnail_url}
    <a class="iwac-hit__thumb" href={itemUrl} aria-hidden="true" tabindex="-1">
      <img src={doc.thumbnail_url} alt="" loading="lazy" />
    </a>
  {/if}
  <div class="iwac-hit__body">
    <h3 class="iwac-hit__title">
      <a href={itemUrl}>{title}</a>
    </h3>
    {#if dateLabel || meta.length}
      <p class="iwac-hit__meta">
        {#if dateLabel}<time>{dateLabel}</time>{/if}
        {#each meta as m, i (m)}
          {#if i > 0 || dateLabel}
            ·
          {/if}<span>{m}</span>
        {/each}
      </p>
    {/if}
    {#if snippet}
      <!-- snippet was HTML-escaped client-side; only literal mark tags survive (see sanitizeSnippet) -->
      <!-- eslint-disable-next-line svelte/no-at-html-tags -->
      <p class="iwac-hit__snippet">{@html snippet}</p>
    {/if}
  </div>
</article>

<style>
  .iwac-hit {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: var(--space-md, 1rem);
    padding: var(--space-md, 1rem);
    background: var(--surface, #fff);
    border: 1px solid var(--border-light, #eee);
    border-radius: var(--radius-md, 0.75rem);
    transition:
      border-color 120ms ease,
      transform 120ms ease;
  }
  .iwac-hit:hover {
    border-color: var(--border-strong, var(--border, #ccc));
    transform: translateY(var(--lift-xxs, -1px));
  }
  .iwac-hit:has(:focus-visible) {
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
  }
  .iwac-hit__thumb {
    display: block;
    width: 5rem;
    height: 5rem;
    border-radius: var(--radius-sm, 0.375rem);
    overflow: hidden;
    background: var(--surface-sunken, #f5f5f5);
  }
  .iwac-hit__thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
  .iwac-hit__body {
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: var(--space-xs, 0.25rem);
  }
  .iwac-hit__title {
    margin: 0;
    font-size: var(--text-lg, 1.125rem);
    line-height: 1.3;
    color: var(--ink-strong, var(--ink, #222));
  }
  .iwac-hit__title a {
    color: inherit;
    text-decoration: none;
  }
  .iwac-hit__title a:hover {
    color: var(--primary, #c66);
    text-decoration: underline;
  }
  .iwac-hit__meta {
    margin: 0;
    font-size: var(--text-sm, 0.9rem);
    color: var(--muted, #666);
  }
  .iwac-hit__snippet {
    margin: var(--space-xs, 0.25rem) 0 0;
    font-size: var(--text-sm, 0.9rem);
    color: var(--ink-light, var(--ink, #444));
    line-height: 1.5;
  }
  .iwac-hit__snippet :global(mark) {
    background: color-mix(in srgb, var(--primary, #c66) 18%, transparent);
    color: inherit;
    border-radius: var(--radius-sm, 0.25rem);
    padding-inline: 0.125em;
  }

  /* Drop the thumb column when there's no image. */
  .iwac-hit:not(:has(.iwac-hit__thumb)) {
    grid-template-columns: 1fr;
  }
</style>
