<script lang="ts">
  import type { IwacHit } from '../lib/types';
  import { entityTypeLabel, typeLabel as typeLabelFor, useI18n } from '../lib/i18n';

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

  const { locale, card, t } = useI18n();

  const doc = $derived(hit.document);
  const title = $derived(doc.title || t('untitled', { id: doc.id }));
  const dateLabel = $derived(formatDate(doc.date, doc.pub_year));
  const typeKey = $derived(doc.type_s ?? '');
  const typeLabel = $derived(typeKey ? typeLabelFor(typeKey, locale) : '');

  // ── Entity (index) card variant ──────────────────────────────────────
  const isEntity = $derived(card === 'entity');
  const entityType = $derived(doc.entity_type_s ? entityTypeLabel(doc.entity_type_s, locale) : '');
  const frequency = $derived(typeof doc.frequency === 'number' ? doc.frequency : null);
  const mentionsLabel = $derived(
    frequency != null
      ? t(frequency === 1 ? 'mention_one' : 'mention_other', { n: frequency.toLocaleString() })
      : '',
  );
  const yearRange = $derived.by(() => {
    const a = doc.first_year;
    const b = doc.last_year;
    if (a && b) return a === b ? String(a) : `${a} – ${b}`;
    return a ? String(a) : b ? String(b) : '';
  });
  const entityCountries = $derived(doc.country_ss ?? []);
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
  // Card body: prefer the query-match snippet (shows WHY this hit matched);
  // fall back to the abstract/description so browse-mode cards (no query,
  // no highlight) still show a couple of lines of context. The abstract is
  // plain text — Svelte escapes it — whereas the snippet carries <mark>.
  const abstract = $derived((doc.abstract ?? '').trim());
  // Author byline — essential for references (a citation without its author
  // is useless), informative for signed articles; simply absent when a doc
  // carries no creator_ss (e.g. unsigned press).
  const byline = $derived((doc.creator_ss ?? []).join(', '));

  // Bibliographic source line for references — "journal vol(issue), pages",
  // "Book title (eds. …), pages — Publisher", etc. Built per reference type
  // from the structured fields (see schema.yaml / ReferenceMapper). Other
  // subsets don't carry these fields, so this stays empty for them.
  const citation = $derived.by(() => buildCitation(doc));
  const itemUrl = $derived(doc.omeka_url || `/s/afrique_ouest/item/${doc.id}`);

  function buildCitation(d: IwacHit['document']): string {
    const rt = d.reference_type_ss?.[0] ?? '';
    const pub = (d.publisher_s ?? '').trim();
    const book = (d.book_title_s ?? '').trim();
    const vol = (d.volume_s ?? '').trim();
    const iss = (d.issue_s ?? '').trim();
    const pages = (d.pages_s ?? '').trim();
    const edition = (d.edition_s ?? '').trim();
    const editors = (d.editor_ss ?? []).join(', ');
    const volIss = vol && iss ? `${vol}(${iss})` : vol || (iss ? `(${iss})` : '');

    if (rt === 'Chapitre') {
      let s = book;
      if (editors) s += ` (${t('cite_eds')} ${editors})`;
      if (pages) s += `${s ? ', ' : ''}${pages}`;
      if (pub) s += `${s ? ' — ' : ''}${pub}`;
      return s.trim();
    }
    if (rt === 'Article de revue' || rt === 'Compte rendu') {
      let s = pub;
      if (volIss) s += `${s ? ' ' : ''}${volIss}`;
      if (pages) s += `${s ? ', ' : ''}${pages}`;
      return s.trim();
    }
    // Books, theses, reports, edited volumes, encyclopaedia entries, fallback.
    return [book, pub, edition].filter(Boolean).join(' — ');
  }

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
    {#if isEntity}
      <!-- Index/authority entity card: year-span eyebrow, type badge,
           occurrence count + countries. No body text. -->
      <header class="iwac-card__head">
        {#if yearRange}
          <time class="iwac-card__eyebrow">{yearRange}</time>
        {/if}
        {#if entityType}
          <span class="iwac-card__type" data-entity-type={doc.entity_type_s}>{entityType}</span>
        {/if}
      </header>

      <h3 class="iwac-card__title">
        <a href={itemUrl}>{title}</a>
      </h3>

      {#if mentionsLabel || entityCountries.length > 0}
        <ul class="iwac-card__source" aria-label={t('source')}>
          {#if mentionsLabel}
            <li class="iwac-card__chip iwac-card__chip--count">{mentionsLabel}</li>
          {/if}
          {#each entityCountries as c (c)}
            <li class="iwac-card__chip">{c}</li>
          {/each}
        </ul>
      {/if}
    {:else}
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

      {#if byline}
        <p class="iwac-card__byline">{byline}</p>
      {/if}

      {#if citation}
        <p class="iwac-card__citation">{citation}</p>
      {/if}

      {#if snippet}
        <!-- snippet was HTML-escaped client-side; only literal mark tags survive (see sanitizeSnippet) -->
        <!-- eslint-disable-next-line svelte/no-at-html-tags -->
        <p class="iwac-card__snippet">{@html snippet}</p>
      {:else if abstract}
        <p class="iwac-card__snippet iwac-card__snippet--abstract">{abstract}</p>
      {/if}

      {#if sourceChips.length > 0}
        <ul class="iwac-card__source" aria-label={t('source')}>
          {#each sourceChips as chip (chip)}
            <li class="iwac-card__chip">{chip}</li>
          {/each}
        </ul>
      {/if}
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
  /* Entity-type tints so persons / places / organisations read distinctly
     when scanning the index. */
  .iwac-card__type[data-entity-type='Personnes'] {
    background: color-mix(in srgb, var(--info, #4a90c8) 18%, var(--surface, #fff));
  }
  .iwac-card__type[data-entity-type='Lieux'] {
    background: color-mix(in srgb, var(--success, #6cc18b) 18%, var(--surface, #fff));
  }
  .iwac-card__type[data-entity-type='Organisations'] {
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

  .iwac-card__byline {
    margin: 0;
    font-size: var(--text-sm, 0.9rem);
    color: var(--ink-light, var(--ink, #444));
    line-height: 1.4;
    /* Clamp long author lists to two lines so cards stay even. */
    display: -webkit-box;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }

  /* Source line for references (journal · volume · pages, book · publisher).
     Italic + muted so it reads as bibliographic metadata under the byline,
     distinct from the abstract below it. */
  .iwac-card__citation {
    margin: 0;
    font-size: var(--text-sm, 0.9rem);
    font-style: italic;
    color: var(--muted, #666);
    line-height: 1.4;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
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
  /* The abstract/description fallback reads as supporting context, so
     clamp it tighter ("a couple of lines") than a query-match snippet. */
  .iwac-card__snippet--abstract {
    -webkit-line-clamp: 2;
    line-clamp: 2;
    color: var(--muted, #666);
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
  /* Occurrence count on entity cards — the headline metric, so tint it. */
  .iwac-card__chip--count {
    background: color-mix(in srgb, var(--primary, #c66) 14%, var(--surface, #fff));
    color: var(--ink-strong, var(--ink, #222));
    font-weight: 600;
    font-variant-numeric: tabular-nums;
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
