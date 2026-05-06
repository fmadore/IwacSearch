<script module lang="ts">
  /**
   * Display labels for the schema's `type_s` enum. Kept here (next to the
   * card that renders them) rather than in lib/labels because they're only
   * used in this component — and grouping them with the badge styling
   * keeps the i18n surface compact.
   */
  const TYPE_LABEL: Readonly<Record<string, string>> = {
    article: 'Article',
    publication: 'Publication',
    document: 'Document',
    audiovisual: 'Audiovisual',
  };
</script>

<script lang="ts">
  import type { IwacHit } from '../lib/types';

  /**
   * One result rendered as a card.
   *
   *   ┌──────────────────────────────────────────────────────────┐
   *   │ ┌────┐  4 NOVEMBRE 1989                       ARTICLE    │
   *   │ │img │  Title of the article goes here                   │
   *   │ │    │  …matching <mark>snippet</mark> of body text…     │
   *   │ └────┘  Sidwaya · Burkina Faso                            │
   *   └──────────────────────────────────────────────────────────┘
   *
   * Layout decisions:
   *   - Whole card is hover-affordant (lift + tinted border) but the
   *     primary click target is the <h3> link to keep screen-readers
   *     happy. We don't wrap the entire card in an <a> because the
   *     thumbnail is decorative and shouldn't double-announce.
   *   - Type badge sits in the top-right corner so users can scan a
   *     long result list and pick out audiovisual / publication rows
   *     without reading every title.
   *   - Date is an "eyebrow" above the title (small, uppercase,
   *     letter-spaced) — that's where editors expect publication date
   *     in the IWAC theme, so the cards match the surrounding chrome.
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
  const typeKey = $derived(doc.type_s ?? '');
  const typeLabel = $derived(TYPE_LABEL[typeKey] ?? '');
  // Compact source line: Newspaper · Country. Kept inside the body so
  // the eyebrow row above the title only carries the date + type chip
  // — too many tokens up there reads as visual noise.
  const sourceChips = $derived(buildSourceChips(doc));

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

  function buildSourceChips(d: IwacHit['document']): string[] {
    const parts: string[] = [];
    if (d.newspaper_ss?.[0]) parts.push(d.newspaper_ss[0]);
    if (d.country_ss?.[0]) parts.push(d.country_ss[0]);
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

<article class="iwac-card" class:iwac-card--no-thumb={!doc.thumbnail_url}>
  {#if doc.thumbnail_url}
    <a class="iwac-card__thumb" href={itemUrl} aria-hidden="true" tabindex="-1">
      <img src={doc.thumbnail_url} alt="" loading="lazy" />
    </a>
  {/if}

  <div class="iwac-card__body">
    <header class="iwac-card__head">
      {#if dateLabel}
        <time class="iwac-card__eyebrow">{dateLabel}</time>
      {/if}
      {#if typeLabel}
        <span class="iwac-card__type" data-type={typeKey}>{typeLabel}</span>
      {/if}
    </header>

    <h3 class="iwac-card__title">
      <a href={itemUrl}>{title}</a>
    </h3>

    {#if snippet}
      <!-- snippet was HTML-escaped client-side; only literal mark tags survive (see sanitizeSnippet) -->
      <!-- eslint-disable-next-line svelte/no-at-html-tags -->
      <p class="iwac-card__snippet">{@html snippet}</p>
    {/if}

    {#if sourceChips.length > 0}
      <ul class="iwac-card__source" aria-label="Source">
        {#each sourceChips as chip (chip)}
          <li class="iwac-card__chip">{chip}</li>
        {/each}
      </ul>
    {/if}
  </div>
</article>

<style>
  .iwac-card {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: var(--space-md, 1rem);
    padding: var(--space-md, 1rem);
    background: var(--panel-bg, var(--surface, #fff));
    border: var(--panel-border, 1px solid var(--border-light, #eee));
    border-radius: var(--panel-radius, var(--radius-md, 0.75rem));
    box-shadow: var(--shadow-xs, 0 1px 2px rgba(0, 0, 0, 0.04));
    transition:
      border-color var(--transition-base, 200ms ease),
      box-shadow var(--transition-base, 200ms ease),
      transform var(--transition-base, 200ms ease);
  }
  .iwac-card:hover {
    border-color: color-mix(
      in srgb,
      var(--primary, #c66) var(--accent-mix-medium, 40%),
      var(--border, #ccc)
    );
    box-shadow: var(--shadow-md, 0 4px 12px rgba(0, 0, 0, 0.08));
    transform: translateY(var(--lift-xxs, -1px));
  }
  .iwac-card:has(:focus-visible) {
    box-shadow:
      var(--shadow-md, 0 4px 12px rgba(0, 0, 0, 0.08)),
      var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
  }
  /* Drop the thumb column when there's no image. */
  .iwac-card--no-thumb {
    grid-template-columns: 1fr;
  }

  .iwac-card__thumb {
    display: block;
    width: 6.5rem;
    height: 6.5rem;
    border-radius: var(--radius-sm, 0.375rem);
    overflow: hidden;
    background: var(--surface-sunken, #f5f5f5);
    border: 1px solid var(--border-light, #eee);
  }
  .iwac-card__thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }

  .iwac-card__body {
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: var(--space-xs, 0.25rem);
  }
  .iwac-card__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-sm, 0.5rem);
    min-height: 1.25rem;
  }
  .iwac-card__eyebrow {
    color: var(--muted, #666);
    font-size: var(--text-xs, 0.75rem);
    font-weight: 600;
    letter-spacing: var(--tracking-wider, 0.08em);
    text-transform: uppercase;
    font-variant-numeric: tabular-nums;
  }
  .iwac-card__type {
    display: inline-flex;
    align-items: center;
    padding: 0.125rem 0.5rem;
    background: color-mix(
      in srgb,
      var(--primary, #c66) var(--accent-mix-subtle, 25%),
      var(--surface, #fff)
    );
    color: var(--ink-on-pastel, var(--ink-strong, var(--ink, #222)));
    border-radius: var(--radius-full, 9999px);
    font-size: var(--text-xs, 0.7rem);
    font-weight: 600;
    letter-spacing: var(--tracking-wide, 0.04em);
    text-transform: uppercase;
    white-space: nowrap;
  }
  /* Subtle per-type tint so audiovisual / publication rows are
     scannable without reading the badge text. Falls through to the
     default tint for any type the schema doesn't enumerate. */
  .iwac-card__type[data-type='audiovisual'] {
    background: color-mix(in srgb, var(--info, #4a90c8) 18%, var(--surface, #fff));
  }
  .iwac-card__type[data-type='publication'] {
    background: color-mix(in srgb, var(--success, #6cc18b) 18%, var(--surface, #fff));
  }
  .iwac-card__type[data-type='document'] {
    background: color-mix(in srgb, var(--warning, #e89c4a) 18%, var(--surface, #fff));
  }

  .iwac-card__title {
    margin: 0;
    font-size: var(--text-lg, 1.125rem);
    line-height: 1.35;
    color: var(--ink-strong, var(--ink, #222));
    letter-spacing: var(--tracking-tight, -0.01em);
  }
  .iwac-card__title a {
    color: inherit;
    text-decoration: none;
  }
  .iwac-card__title a:hover {
    color: var(--primary, #c66);
    text-decoration: underline;
    text-underline-offset: 2px;
  }

  .iwac-card__snippet {
    margin: var(--space-xs, 0.25rem) 0 0;
    font-size: var(--text-sm, 0.9rem);
    color: var(--ink-light, var(--ink, #444));
    line-height: var(--line-height-normal, 1.5);
    /* Clamp to ~3 lines to keep cards visually consistent in a long list. */
    display: -webkit-box;
    -webkit-line-clamp: 3;
    line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
  .iwac-card__snippet :global(mark) {
    background: color-mix(in srgb, var(--primary, #c66) var(--accent-mix-subtle, 25%), transparent);
    color: inherit;
    border-radius: var(--radius-sm, 0.25rem);
    padding-inline: 0.15em;
  }

  .iwac-card__source {
    list-style: none;
    margin: var(--space-xs, 0.25rem) 0 0;
    padding: 0;
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-xs, 0.25rem);
  }
  .iwac-card__chip {
    display: inline-flex;
    align-items: center;
    padding: 0.125rem 0.5rem;
    background: var(--surface-sunken, #f5f5f5);
    color: var(--ink-light, var(--ink, #444));
    border-radius: var(--radius-sm, 0.375rem);
    font-size: var(--text-xs, 0.75rem);
    font-weight: 500;
    line-height: 1.4;
  }

  /* Narrow viewport: stack the thumb on top so titles get full width. */
  @media (max-width: 32rem) {
    .iwac-card {
      grid-template-columns: 1fr;
      gap: var(--space-sm, 0.5rem);
    }
    .iwac-card__thumb {
      width: 100%;
      height: 9rem;
    }
  }
</style>
