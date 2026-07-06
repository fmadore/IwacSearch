<script lang="ts">
  import type { ActiveFilters, IwacHit, ViewMode } from '../lib/types';
  import {
    countryLabel,
    entityTypeLabel,
    facetLabel,
    typeLabel as typeLabelFor,
    useI18n,
  } from '../lib/i18n';
  import { sizedThumbnail } from '../lib/thumbnail';
  import { parseMentionsByYear, densifyByYear } from '../lib/sparkline';
  import Icon from './Icon.svelte';
  import Sparkline from './Sparkline.svelte';

  /**
   * One result, rendered in one of two layouts:
   *
   *   list (default)                      gallery
   *   ┌────┐ 4 NOV 1989    ARTICLE        ┌──────────────┐
   *   │img │ Title goes here              │   4:3 plate  │
   *   │    │ …matching <mark>snippet</mark>│              │
   *   └────┘ Sidwaya · Burkina Faso       └──────────────┘
   *                                       ● ARTICLE · 1989
   *                                       Title goes here
   *                                       Sidwaya
   *
   * `list` is the dense ledger row (density first); `gallery` is the
   * image-forward tile for browsing the corpus's photographs, plates and
   * scans (design review §01). Both share the dateline + categorical-dot
   * grammar — only the geometry and the amount of body text differ, so the
   * field derivations are computed once and rendered through shared snippets.
   *
   * Layout decisions:
   *   - The thumbnail is decorative (the title link is the primary target), so
   *     it's aria-hidden and not wrapped around the whole card.
   *   - Type LEADS the dateline as a dotted uppercase token, so the eye scans
   *     category down the left margin without reading every title.
   *   - Thumbnails request the Omeka derivative tier that fits the slot (list →
   *     `medium`, gallery → `large`) instead of upscaling one stored thumb
   *     (design review punch-list item 5; see lib/thumbnail.ts).
   *
   * Clickable metadata: the type badge, author byline, newspaper and country
   * are facet-toggle buttons calling onFacetToggle — the same handler the
   * FacetPanel uses — so a card doubles as a filter affordance. Active filters
   * render brand-coloured (aria-pressed reflects it).
   *
   * Snippet sanitisation: Typesense returns highlighted HTML with <mark> tags.
   * We HTML-escape the snippet client-side and only reinstate literal
   * <mark>/</mark> — see sanitizeSnippet().
   */

  interface Props {
    hit: IwacHit;
    /** Currently-active categorical filters — drives badge pressed-state. */
    activeFilters: ActiveFilters;
    /** Toggle a facet from a card badge. Same signature FacetPanel uses. */
    onFacetToggle: (field: string, value: string, nextChecked: boolean) => void;
    /**
     * Suppress the country chip. Set on single-country scopes where the
     * country is implied by the page (e.g. a Burkina Faso block), so it's
     * not repeated on every card. Country stays a working facet elsewhere.
     */
    hideCountry?: boolean;
    /** Presentation layout — `list` (default) or `gallery` tile. */
    layout?: ViewMode;
  }

  const {
    hit,
    activeFilters,
    onFacetToggle,
    hideCountry = false,
    layout = 'list',
  }: Props = $props();

  const { locale, card, t } = useI18n();

  /** A clickable card badge: a facet field + its raw filter value + display. */
  type FilterChip = { field: string; value: string; display: string };

  function isActive(field: string, value: string): boolean {
    return activeFilters[field]?.includes(value) ?? false;
  }
  function toggle(field: string, value: string): void {
    onFacetToggle(field, value, !isActive(field, value));
  }
  /** aria-label that announces what clicking the chip will do. */
  function chipAria(chip: FilterChip): string {
    const label = facetLabel(chip.field, locale);
    return isActive(chip.field, chip.value)
      ? t('remove_filter', { label, value: chip.display })
      : t('add_filter', { label, value: chip.display });
  }

  const doc = $derived(hit.document);
  const title = $derived(doc.title || t('untitled', { id: doc.id }));
  // References are cited by year; their pub_date is commonly a Jan-1 epoch, so
  // show the year (not a misleading "1 janvier 2016"). Everything else keeps its
  // precise date.
  const dateLabel = $derived(formatDate(doc.date, doc.pub_year, doc.type_s === 'reference'));
  const typeKey = $derived(doc.type_s ?? '');
  const typeLabel = $derived(typeKey ? typeLabelFor(typeKey, locale) : '');

  // Thumbnail derivative per layout: list rows take `medium`, gallery tiles take
  // `large` (the 200px `medium` would upscale at tile size). Falls back to the
  // stored URL unchanged when it isn't a recognised Omeka derivative path.
  const listThumb = $derived(sizedThumbnail(doc.thumbnail_url, 'medium'));
  const galleryThumb = $derived(sizedThumbnail(doc.thumbnail_url, 'large'));

  // Type badge → a clickable facet. References surface their publication type
  // (reference_type_ss) so the badge reads "Chapitre" / "Article de revue…";
  // every other subset shows its type_s label. null when neither is known.
  const isReference = $derived(typeKey === 'reference');
  const referenceType = $derived(doc.reference_type_ss?.[0] ?? '');
  const typeChip = $derived.by<FilterChip | null>(() => {
    if (isReference && referenceType) {
      return { field: 'reference_type_ss', value: referenceType, display: referenceType };
    }
    if (typeKey && typeLabel) {
      return { field: 'type_s', value: typeKey, display: typeLabel };
    }
    return null;
  });
  // Tint attribute for the badge: references get their own scholarly tint;
  // everything else keys off the type_s value.
  const typeTint = $derived(typeChip?.field === 'reference_type_ss' ? 'reference' : typeKey);

  // ── Entity (index) card variant ──────────────────────────────────────
  // Context-driven on the entity index surface; shape-driven as a fallback
  // so MIXED hit lists (the federated "All" union tab) render entity docs
  // as entity cards — entity docs always carry entity_type_s, content docs
  // never do.
  const isEntity = $derived(card === 'entity' || doc.entity_type_s != null);
  const entityType = $derived(doc.entity_type_s ? entityTypeLabel(doc.entity_type_s, locale) : '');
  const entityTypeChip = $derived.by<FilterChip | null>(() =>
    doc.entity_type_s
      ? { field: 'entity_type_s', value: doc.entity_type_s, display: entityType }
      : null,
  );
  const frequency = $derived(typeof doc.frequency === 'number' ? doc.frequency : null);
  const mentionsLabel = $derived(
    frequency != null
      ? t(frequency === 1 ? 'mention_one' : 'mention_other', { n: frequency.toLocaleString() })
      : '',
  );
  // The bare "mention(s)" word for the split number + label metric (the number
  // is rendered separately as a display numeral). t() with an empty n yields
  // " mentions" → trimmed.
  const mentionsWord = $derived(
    frequency != null ? t(frequency === 1 ? 'mention_one' : 'mention_other', { n: '' }).trim() : '',
  );
  // Per-year mentions series for the sparkline (design review §03B). Empty until
  // the entity collection is rebuilt with mentions_by_year_s, so the card simply
  // omits the sparkline when the data isn't there.
  const mentionsSeries = $derived(densifyByYear(parseMentionsByYear(doc.mentions_by_year_s)));
  const yearRange = $derived.by(() => {
    const a = doc.first_year;
    const b = doc.last_year;
    if (a && b) return a === b ? String(a) : `${a} – ${b}`;
    return a ? String(a) : b ? String(b) : '';
  });
  const entityCountryChips = $derived.by<FilterChip[]>(() =>
    hideCountry
      ? []
      : (doc.country_ss ?? []).map((c) => ({
          field: 'country_ss',
          value: c,
          display: countryLabel(c, locale),
        })),
  );
  // Entity category (dcterms:isPartOf) — organisation kind for organisations.
  const entityPartOfChips = $derived.by<FilterChip[]>(() =>
    (doc.is_part_of_ss ?? []).map((v) => ({ field: 'is_part_of_ss', value: v, display: v })),
  );
  // Compact source line: Newspaper · Country, each a clickable filter.
  const sourceChips = $derived.by<FilterChip[]>(() => {
    const out: FilterChip[] = [];
    if (doc.newspaper_ss?.[0]) {
      out.push({ field: 'newspaper_ss', value: doc.newspaper_ss[0], display: doc.newspaper_ss[0] });
    }
    if (!hideCountry && doc.country_ss?.[0]) {
      out.push({
        field: 'country_ss',
        value: doc.country_ss[0],
        display: countryLabel(doc.country_ss[0], locale),
      });
    }
    return out;
  });

  // Body snippet: the OCR match first (most contextual), else the abstract
  // match. The title is highlighted in place (titleMarkup below).
  const rawSnippet = $derived(
    hit.highlights?.find((h) => h.field === 'ocr_text')?.snippet ??
      hit.highlights?.find((h) => h.field === 'abstract')?.snippet ??
      '',
  );
  const snippet = $derived(sanitizeSnippet(rawSnippet));

  // Title with the query match marked. highlight_full_fields covers title_txt,
  // so `value` carries the COMPLETE title. Empty (→ plain title) when no match.
  const titleMarkup = $derived.by(() => {
    const h = hit.highlights?.find((x) => x.field === 'title_txt');
    const s = h?.value ?? h?.snippet ?? '';
    return s.includes('<mark>') ? sanitizeSnippet(s) : '';
  });

  // ── Match attribution: WHY is this hit shown? ───────────────────────
  // Surface metadata-channel matches (subject, spatial, author, journal, alias…)
  // that aren't visible in the title/body as a small "Matched in" line.
  const VISIBLE_MATCH_FIELDS = ['title_txt', 'ocr_text', 'abstract'];

  type MatchedIn = { field: string; label: string; snippet: string };
  const matchedIn = $derived.by<MatchedIn[]>(() => {
    const out: MatchedIn[] = [];
    for (const h of hit.highlights ?? []) {
      if (VISIBLE_MATCH_FIELDS.includes(h.field)) continue;
      if (out.some((m) => m.field === h.field)) continue;
      const raw = h.snippet ?? h.snippets?.[0] ?? '';
      if (!raw.includes('<mark>')) continue;
      out.push({
        field: h.field,
        label: facetLabel(h.field, locale),
        snippet: sanitizeSnippet(raw),
      });
    }
    return out.slice(0, 3);
  });

  // Source-line chip icons — same Bootstrap Icons set the IWAC theme uses.
  const CHIP_ICONS: Record<string, 'newspaper' | 'globe'> = {
    newspaper_ss: 'newspaper',
    country_ss: 'globe',
  };
  // Citation icon: journal page for periodical pieces, book covers the rest.
  const citationIcon = $derived(
    referenceType === 'Article de revue' || referenceType === 'Compte rendu'
      ? ('journal' as const)
      : ('book' as const),
  );
  const abstract = $derived((doc.abstract ?? '').trim());
  // Author byline — essential for references, informative for signed articles.
  const authors = $derived(doc.creator_ss ?? []);

  // Bibliographic source line for references — built per reference type.
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
    return [book, pub, edition].filter(Boolean).join(' — ');
  }

  function formatDate(epoch?: number, year?: number, yearOnly = false): string {
    if (!yearOnly && epoch && epoch > 0) {
      try {
        return new Date(epoch * 1000).toLocaleDateString(locale, {
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

  /**
   * Escape every HTML-significant character, then re-introduce only the literal
   * <mark>/</mark> tags Typesense uses to surround matches. Defense in depth.
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

<!-- A source/country chip rendered as a facet toggle button. -->
{#snippet filterChip(chip: FilterChip)}
  <li>
    <button
      type="button"
      class="iwac-card__chip iwac-card__chip--filter"
      class:is-active={isActive(chip.field, chip.value)}
      aria-pressed={isActive(chip.field, chip.value)}
      aria-label={chipAria(chip)}
      onclick={() => toggle(chip.field, chip.value)}
      >{#if CHIP_ICONS[chip.field]}<span class="iwac-card__meta-icon" aria-hidden="true"
          ><Icon name={CHIP_ICONS[chip.field]} /></span
        >{/if}{chip.display}</button
    >
  </li>
{/snippet}

<!-- Leading type badge (content type chip, or entity-type chip on the index). -->
{#snippet typeBadge()}
  {#if isEntity}
    {#if entityTypeChip}
      <button
        type="button"
        class="iwac-card__type iwac-card__type--filter"
        class:is-active={isActive(entityTypeChip.field, entityTypeChip.value)}
        data-entity-type={doc.entity_type_s}
        aria-pressed={isActive(entityTypeChip.field, entityTypeChip.value)}
        aria-label={chipAria(entityTypeChip)}
        onclick={() => toggle(entityTypeChip.field, entityTypeChip.value)}
        >{entityTypeChip.display}</button
      >
    {/if}
  {:else if typeChip}
    <button
      type="button"
      class="iwac-card__type iwac-card__type--filter"
      class:is-active={isActive(typeChip.field, typeChip.value)}
      data-type={typeTint}
      aria-pressed={isActive(typeChip.field, typeChip.value)}
      aria-label={chipAria(typeChip)}
      onclick={() => toggle(typeChip.field, typeChip.value)}>{typeChip.display}</button
    >
  {/if}
{/snippet}

<!-- Dateline eyebrow date (content: precise date; entity: mention year span). -->
{#snippet eyebrowDate()}
  {#if isEntity}
    {#if yearRange}<time class="iwac-card__eyebrow">{yearRange}</time>{/if}
  {:else if dateLabel}<time class="iwac-card__eyebrow">{dateLabel}</time>{/if}
{/snippet}

<!-- Title link, with the query match highlighted in place when present. -->
{#snippet titleLink()}
  {#if titleMarkup}
    <!-- sanitizeSnippet escaped everything but literal mark tags -->
    <!-- eslint-disable-next-line svelte/no-at-html-tags -->
    <a href={itemUrl}>{@html titleMarkup}</a>
  {:else}
    <a href={itemUrl}>{title}</a>
  {/if}
{/snippet}

<article
  class="iwac-card iwac-card--{layout}"
  class:iwac-card--no-thumb={layout === 'list' && !listThumb}
>
  {#if layout === 'gallery'}
    <!-- ── Gallery tile: image-forward, compact metadata below ── -->
    <a
      class="iwac-card__thumb iwac-card__thumb--gallery"
      href={itemUrl}
      aria-hidden="true"
      tabindex="-1"
    >
      {#if galleryThumb}
        <img src={galleryThumb} alt="" loading="lazy" />
      {:else}
        <span class="iwac-card__thumb-ph" aria-hidden="true"><Icon name="image" /></span>
      {/if}
    </a>
    <div class="iwac-card__body iwac-card__body--gallery">
      <header class="iwac-card__head">{@render typeBadge()}{@render eyebrowDate()}</header>
      <h3 class="iwac-card__title iwac-card__title--gallery">{@render titleLink()}</h3>
      {#if isEntity}
        {#if mentionsLabel}<p class="iwac-card__gallery-meta">{mentionsLabel}</p>{/if}
      {:else if sourceChips.length > 0}
        <ul class="iwac-card__source" aria-label={t('source')}>
          {#each sourceChips as chip (chip.field + '|' + chip.value)}
            {@render filterChip(chip)}
          {/each}
        </ul>
      {/if}
    </div>
  {:else}
    <!-- ── List ledger row ── -->
    {#if listThumb}
      <a class="iwac-card__thumb" href={itemUrl} aria-hidden="true" tabindex="-1">
        <img src={listThumb} alt="" loading="lazy" />
      </a>
    {/if}

    <div class="iwac-card__body">
      {#if isEntity}
        <!-- Index/authority entity card: year-span eyebrow, type badge,
             occurrence metric + sparkline + countries. No body text. -->
        <header class="iwac-card__head">{@render typeBadge()}{@render eyebrowDate()}</header>

        <h3 class="iwac-card__title">{@render titleLink()}</h3>

        {#if frequency != null || mentionsSeries.length >= 2}
          <div class="iwac-card__metrics">
            {#if frequency != null}
              <span class="iwac-card__mentions">
                <span class="iwac-card__mentions-n">{frequency.toLocaleString()}</span>
                <span class="iwac-card__mentions-label">{mentionsWord}</span>
              </span>
            {/if}
            {#if mentionsSeries.length >= 2}
              <span
                class="iwac-card__spark"
                data-entity-type={doc.entity_type_s}
                title={t('mentions_trend')}
              >
                <Sparkline values={mentionsSeries} />
              </span>
            {/if}
          </div>
        {/if}

        {#if entityPartOfChips.length > 0 || entityCountryChips.length > 0}
          <ul class="iwac-card__source" aria-label={t('source')}>
            {#each entityPartOfChips as chip (chip.field + '|' + chip.value)}
              {@render filterChip(chip)}
            {/each}
            {#each entityCountryChips as chip (chip.field + '|' + chip.value)}
              {@render filterChip(chip)}
            {/each}
          </ul>
        {/if}
      {:else}
        <header class="iwac-card__head">{@render typeBadge()}{@render eyebrowDate()}</header>

        <h3 class="iwac-card__title">{@render titleLink()}</h3>

        {#if authors.length > 0}
          <p class="iwac-card__byline">
            <span class="iwac-card__meta-icon" aria-hidden="true"><Icon name="person" /></span
            >{#each authors as author, i (author)}<button
                type="button"
                class="iwac-card__author"
                class:is-active={isActive('creator_ss', author)}
                aria-pressed={isActive('creator_ss', author)}
                aria-label={chipAria({ field: 'creator_ss', value: author, display: author })}
                onclick={() => toggle('creator_ss', author)}>{author}</button
              >{#if i < authors.length - 1}<span class="iwac-card__byline-sep"></span>{/if}{/each}
          </p>
        {/if}

        {#if citation}
          <p class="iwac-card__citation">
            <span class="iwac-card__meta-icon" aria-hidden="true"><Icon name={citationIcon} /></span
            >{citation}
          </p>
        {/if}

        {#if snippet}
          <!-- snippet was HTML-escaped client-side; only literal mark tags survive -->
          <!-- eslint-disable-next-line svelte/no-at-html-tags -->
          <p class="iwac-card__snippet">{@html snippet}</p>
        {:else if abstract}
          <p class="iwac-card__snippet iwac-card__snippet--abstract">{abstract}</p>
        {/if}

        {#if matchedIn.length > 0}
          <p class="iwac-card__matched">
            <span class="iwac-card__matched-label">{t('matched_in')}</span>
            {#each matchedIn as m, i (m.field)}<span class="iwac-card__matched-item"
                ><span class="iwac-card__matched-field">{m.label}</span
                ><!-- eslint-disable-next-line svelte/no-at-html-tags --><span
                  class="iwac-card__matched-value">{@html m.snippet}</span
                ></span
              >{#if i < matchedIn.length - 1}<span class="iwac-card__matched-sep"
                ></span>{/if}{/each}
          </p>
        {/if}

        {#if sourceChips.length > 0}
          <ul class="iwac-card__source" aria-label={t('source')}>
            {#each sourceChips as chip (chip.field + '|' + chip.value)}
              {@render filterChip(chip)}
            {/each}
          </ul>
        {/if}
      {/if}
    </div>
  {/if}
</article>

<style>
  /*
   * Ledger row, not a card. Rows are separated by hairlines owned by
   * ResultsList; the row itself is a flat grid with a hover wash.
   */
  .iwac-card {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: var(--space-md, 1rem);
    padding: var(--space-md, 1rem) var(--space-sm, 0.5rem);
    background: transparent;
    border: none;
    border-radius: 0;
    box-shadow: none;
    transition: background-color var(--transition-fast, 150ms ease);
  }
  .iwac-card:hover {
    background: color-mix(in oklab, var(--primary, #ce4115) 4%, transparent);
  }
  .iwac-card:has(:focus-visible) {
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
  }
  /* Drop the thumb column when there's no image (list only). */
  .iwac-card--no-thumb {
    grid-template-columns: 1fr;
  }

  /*
   * Gallery tile: image on top, compact metadata below. No boxy drop-shadow
   * card — the image is the block, a hairline border contains it. The hover
   * wash is dropped (the un-desaturating plate is the affordance instead).
   */
  .iwac-card--gallery {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm, 0.5rem);
    padding: 0;
  }
  .iwac-card--gallery:hover {
    background: transparent;
  }

  .iwac-card__thumb {
    display: block;
    /* 80px → 112px: big enough to read a portrait or colour plate while
       scanning (design review §01). */
    width: 7rem;
    height: 7rem;
    border-radius: var(--radius-sm, 0.375rem);
    overflow: hidden;
    background: var(--surface-sunken, #f4f1ef);
    border: 1px solid var(--border-light, #e2e5e8);
  }
  .iwac-card__thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    /* Newsprint at rest, full plate on engagement. Eased from saturate(0.4) so
       portraits and colour plates stay legible while scanning (punch item 5). */
    filter: saturate(0.6) contrast(1.02);
    transition: filter var(--transition-base, 200ms ease);
  }
  .iwac-card:hover .iwac-card__thumb img,
  .iwac-card__thumb:hover img {
    filter: none;
  }
  @media (prefers-reduced-motion: reduce) {
    .iwac-card__thumb img {
      transition: none;
    }
  }

  /*
   * Dark "lamplit reading room" surfaces (punch item 6): ease the rest-state
   * desaturation further and give plates a stronger 1px --border so they
   * separate from the warm dark ground instead of muddying into it. The hex
   * fallbacks are the LIGHT canonical values (degraded-mode only — the tokens
   * themselves resolve to the right per-theme value when the theme is present).
   * Both the manual (data-theme="dark") and system (prefers-color-scheme)
   * registers, but never when light is forced.
   */
  :global(body[data-theme='dark']) .iwac-card__thumb {
    border-color: var(--border, #ced1d6);
  }
  :global(body[data-theme='dark']) .iwac-card__thumb img {
    filter: saturate(0.78) contrast(1.02);
  }
  @media (prefers-color-scheme: dark) {
    :global(body:not([data-theme='light'])) .iwac-card__thumb {
      border-color: var(--border, #ced1d6);
    }
    :global(body:not([data-theme='light'])) .iwac-card__thumb img {
      filter: saturate(0.78) contrast(1.02);
    }
  }

  /*
   * Gallery plate: a 4:3 frame so tall newspaper plates aren't decapitated by a
   * square crop (design review crop-awareness). object-fit:cover still trims to
   * the frame, but 4:3 keeps far more of a portrait page than 1:1.
   */
  .iwac-card__thumb--gallery {
    width: 100%;
    height: auto;
    aspect-ratio: 4 / 3;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  /* Placeholder for image-less items: a quiet muted mark, never empty. */
  .iwac-card__thumb-ph {
    color: var(--muted, #66696e);
    opacity: 0.5;
    font-size: 2rem;
    display: inline-flex;
  }

  .iwac-card__body {
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: var(--space-xs, 0.25rem);
  }
  .iwac-card__body--gallery {
    gap: 0.2rem;
  }
  .iwac-card__head {
    display: flex;
    align-items: baseline;
    flex-wrap: wrap;
    gap: 0;
    min-height: 1.25rem;
  }
  .iwac-card__eyebrow {
    color: var(--muted, #66696e);
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 600;
    letter-spacing: var(--tracking-wider, 0.08em);
    text-transform: uppercase;
    font-variant-numeric: tabular-nums;
  }
  /*
   * Type badge: outlined chip with a categorical dot. The dot carries the type
   * colour (semantic theme tokens only); a scan down the list reads category
   * from the dots without a pastel fill on every row.
   */
  .iwac-card__type {
    --iwac-type-dot: var(--muted, #66696e);
    display: inline-flex;
    align-items: center;
    gap: 0.4em;
    padding: 0;
    background: transparent;
    color: var(--ink-light, var(--ink, #13161c));
    border: none;
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 700;
    letter-spacing: var(--tracking-wide, 0.04em);
    text-transform: uppercase;
    white-space: nowrap;
  }
  /* Primary interpunct between the leading type token and the date. */
  .iwac-card__type + .iwac-card__eyebrow::before {
    content: '·';
    color: var(--primary, #ce4115);
    font-weight: 700;
    padding-inline: 0.45em 0.5em;
  }
  .iwac-card__type::before {
    content: '';
    width: 0.5em;
    height: 0.5em;
    border-radius: 50%;
    background: var(--iwac-type-dot);
    flex-shrink: 0;
  }
  /* Categorical dot colours come from the theme's single-source --type-* map
     (IWAC-theme docs/DESIGN-SYSTEM.md §3); the hex is the degraded-mode
     fallback when the theme isn't loaded. */
  .iwac-card__type[data-type='article'] {
    --iwac-type-dot: var(--type-article, #ce4115);
  }
  .iwac-card__type[data-type='audiovisual'] {
    --iwac-type-dot: var(--type-audiovisual, #037ac0);
  }
  .iwac-card__type[data-type='publication'] {
    --iwac-type-dot: var(--type-publication, #394f68);
  }
  .iwac-card__type[data-type='document'] {
    --iwac-type-dot: var(--type-document, #de7000);
  }
  .iwac-card__type[data-type='photograph'] {
    --iwac-type-dot: var(--type-photograph, #2e9052);
  }
  .iwac-card__type[data-type='reference'] {
    --iwac-type-dot: var(--type-reference, #66696e);
  }
  .iwac-card__type[data-entity-type='Personnes'] {
    --iwac-type-dot: var(--type-entity-personnes, #037ac0);
  }
  .iwac-card__type[data-entity-type='Lieux'] {
    --iwac-type-dot: var(--type-entity-lieux, #2e9052);
  }
  .iwac-card__type[data-entity-type='Organisations'] {
    --iwac-type-dot: var(--type-entity-organisations, #de7000);
  }

  /*
   * Clickable type badge. The IWAC theme paints every <button> primary + glow +
   * hover-translate; we zero box-shadow/transform explicitly so it can't leak.
   */
  .iwac-card__type--filter {
    cursor: pointer;
    font-family: inherit;
    box-shadow: none;
    transition: color var(--transition-fast, 150ms ease);
  }
  .iwac-card__type--filter:hover {
    background: transparent;
    color: var(--ink-strong, var(--ink, #13161c));
    box-shadow: none;
    transform: none;
  }
  /* Active type filter: the label goes primary; the categorical dot keeps its
     own colour. No pill fill on every row. */
  .iwac-card__type--filter.is-active {
    background: transparent;
    color: var(--primary, #ce4115);
  }
  .iwac-card__type--filter:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
    border-radius: var(--radius-sm, 0.375rem);
  }

  .iwac-card__title {
    margin: 0;
    font-size: var(--text-lg, 1.1875rem);
    line-height: 1.3;
    color: var(--ink-strong, var(--ink, #13161c));
    /* Clarendon slabs clog at tighter tracking — shared display token. */
    letter-spacing: var(--tracking-display, -0.01em);
  }
  /* Gallery titles are smaller and clamp to two lines so tiles stay even. */
  .iwac-card__title--gallery {
    font-size: var(--text-base, 1.0625rem);
    line-height: 1.25;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
  .iwac-card__title a {
    color: inherit;
    text-decoration: none;
  }
  .iwac-card__title a:hover {
    color: var(--primary, #ce4115);
    text-decoration: underline;
    text-underline-offset: 2px;
  }

  .iwac-card__byline {
    margin: 0;
    font-size: var(--text-sm, 0.9375rem);
    color: var(--ink-light, var(--ink, #13161c));
    line-height: 1.4;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
  .iwac-card__author {
    display: inline;
    margin: 0;
    padding: 0;
    border: none;
    background: none;
    box-shadow: none;
    font: inherit;
    color: inherit;
    cursor: pointer;
    text-align: start;
    transition: color var(--transition-fast, 150ms ease);
  }
  .iwac-card__author:hover {
    color: var(--primary, #ce4115);
    background: none;
    box-shadow: none;
    transform: none;
    text-decoration: underline;
    text-underline-offset: 2px;
  }
  .iwac-card__author.is-active {
    color: var(--primary, #ce4115);
    font-weight: 600;
    text-decoration: underline;
    text-underline-offset: 2px;
  }
  .iwac-card__author:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
    border-radius: var(--radius-sm, 0.375rem);
  }
  .iwac-card__byline-sep::before {
    content: ', ';
    color: var(--ink-light, var(--ink, #13161c));
  }

  .iwac-card__meta-icon {
    display: inline-block;
    color: var(--muted, #66696e);
    margin-inline-end: 0.35em;
    font-size: 0.875em;
  }

  .iwac-card__citation {
    margin: 0;
    font-size: var(--text-sm, 0.9375rem);
    font-style: italic;
    color: var(--muted, #66696e);
    line-height: 1.4;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }

  .iwac-card__snippet {
    margin: var(--space-xs, 0.25rem) 0 0;
    font-size: var(--text-sm, 0.9375rem);
    color: var(--ink-light, var(--ink, #13161c));
    line-height: var(--line-height-normal, 1.5);
    display: -webkit-box;
    -webkit-line-clamp: 3;
    line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
  .iwac-card__snippet--abstract {
    -webkit-line-clamp: 2;
    line-clamp: 2;
    color: var(--muted, #66696e);
  }
  .iwac-card__snippet :global(mark),
  .iwac-card__matched :global(mark),
  .iwac-card__title :global(mark) {
    background: color-mix(
      in oklab,
      var(--primary, #ce4115) var(--accent-mix-subtle, 25%),
      transparent
    );
    color: inherit;
    border-radius: var(--radius-sm, 0.375rem);
    padding-inline: 0.15em;
  }

  .iwac-card__matched {
    margin: var(--space-xs, 0.25rem) 0 0;
    font-size: var(--text-xs, 0.8125rem);
    color: var(--muted, #66696e);
    line-height: 1.5;
  }
  .iwac-card__matched-label {
    font-weight: 600;
    letter-spacing: var(--tracking-wide, 0.04em);
    text-transform: uppercase;
    font-size: 0.85em;
    margin-inline-end: 0.35em;
  }
  .iwac-card__matched-field {
    font-weight: 600;
    color: var(--ink-light, var(--ink, #13161c));
  }
  .iwac-card__matched-field::after {
    content: ' : ';
  }
  .iwac-card__matched-sep::before {
    content: ' · ';
    font-weight: 700;
  }

  /*
   * Entity metric row: the occurrence count as a display numeral beside the
   * mentions-over-time sparkline (design review §03B). The sparkline inherits
   * the entity's categorical colour via currentColor on the wrapper — same
   * token the dot uses, read at runtime, never hardcoded.
   */
  .iwac-card__metrics {
    display: flex;
    align-items: flex-end;
    gap: var(--space-md, 1rem);
    margin-block-start: var(--space-xs, 0.25rem);
  }
  .iwac-card__mentions {
    display: inline-flex;
    align-items: baseline;
    gap: 0.35em;
  }
  .iwac-card__mentions-n {
    font-family: var(--font-headings, Georgia, serif);
    font-size: var(--text-xl, 1.5rem);
    font-weight: 700;
    line-height: 1;
    color: var(--ink-strong, var(--ink, #13161c));
    font-variant-numeric: tabular-nums;
    letter-spacing: var(--tracking-display, -0.01em);
  }
  .iwac-card__mentions-label {
    font-size: var(--text-xs, 0.8125rem);
    color: var(--muted, #66696e);
  }
  .iwac-card__spark {
    display: inline-flex;
    align-items: flex-end;
    /* Default to the muted ink; per-type rules below override with the entity
       colour. The inline SVG inherits this via stroke/fill:currentColor. */
    color: var(--muted, #66696e);
  }
  .iwac-card__spark[data-entity-type='Personnes'] {
    color: var(--type-entity-personnes, #037ac0);
  }
  .iwac-card__spark[data-entity-type='Lieux'] {
    color: var(--type-entity-lieux, #2e9052);
  }
  .iwac-card__spark[data-entity-type='Organisations'] {
    color: var(--type-entity-organisations, #de7000);
  }

  /*
   * Source line: quiet text tokens separated by interpuncts — a byline, not a
   * chip tray. Plain INLINE flow so every token shares one baseline.
   */
  .iwac-card__source {
    list-style: none;
    margin: var(--space-xs, 0.25rem) 0 0;
    padding: 0;
    font-size: var(--text-xs, 0.8125rem);
    line-height: 1.5;
  }
  .iwac-card__source li {
    display: inline;
  }
  .iwac-card__source li + li::before {
    content: '·';
    color: var(--muted, #66696e);
    padding-inline: 0.35em 0.5em;
    font-weight: 700;
  }
  .iwac-card__chip {
    display: inline;
    margin: 0;
    padding: 0;
    background: transparent;
    color: var(--muted, #66696e);
    border: none;
    border-radius: 0;
    font: inherit;
    font-weight: 500;
    vertical-align: baseline;
  }
  .iwac-card__chip--filter {
    cursor: pointer;
    font-family: inherit;
    box-shadow: none;
    transition: color var(--transition-fast, 150ms ease);
  }
  .iwac-card__chip--filter:hover {
    background: transparent;
    color: var(--primary, #ce4115);
    text-decoration: underline;
    text-underline-offset: 2px;
    box-shadow: none;
    transform: none;
  }
  .iwac-card__chip--filter.is-active {
    background: transparent;
    color: var(--primary, #ce4115);
    font-weight: 600;
    text-decoration: underline;
    text-underline-offset: 2px;
  }
  .iwac-card__chip--filter:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
    border-radius: var(--radius-sm, 0.375rem);
  }

  /* Compact entity meta in a gallery tile (e.g. "312 mentions"). */
  .iwac-card__gallery-meta {
    margin: 0;
    font-size: var(--text-xs, 0.8125rem);
    color: var(--muted, #66696e);
    font-variant-numeric: tabular-nums;
  }

  /* Narrow viewport: stack the list thumb on top so titles get full width. */
  @media (max-width: 32rem) {
    .iwac-card--list {
      grid-template-columns: 1fr;
      gap: var(--space-sm, 0.5rem);
    }
    .iwac-card--list .iwac-card__thumb {
      width: 100%;
      height: 9rem;
    }
  }
</style>
