<script lang="ts">
  import type { ActiveFilters, IwacHit } from '../lib/types';
  import {
    countryLabel,
    entityTypeLabel,
    facetLabel,
    typeLabel as typeLabelFor,
    useI18n,
  } from '../lib/i18n';
  import Icon from './Icon.svelte';

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
   * Clickable metadata: the type badge, author byline, newspaper and
   * country are rendered as toggle buttons. Clicking one applies (or
   * removes) the matching facet on the parent search via onFacetToggle —
   * the same handler the FacetPanel uses — so a card doubles as a filter
   * affordance. Active filters render brand-filled (aria-pressed reflects
   * it). For references the badge shows the publication type (Chapitre,
   * Article de revue…) and filters reference_type_ss, not the generic
   * "Référence" type_s pill.
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
    /** Currently-active categorical filters — drives badge pressed-state. */
    activeFilters: ActiveFilters;
    /** Toggle a facet from a card badge. Same signature FacetPanel uses. */
    onFacetToggle: (field: string, value: string, nextChecked: boolean) => void;
  }

  const { hit, activeFilters, onFacetToggle }: Props = $props();

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
  // References are bibliographic citations — cited by year. Their pub_date
  // is commonly year-only, which the indexer stores as a Jan-1 epoch (see
  // AbstractMapper::dateToEpoch); formatting that as a full date invented a
  // misleading "1 janvier 2016". Show the publication year for references;
  // every other subset keeps its precise date.
  const dateLabel = $derived(formatDate(doc.date, doc.pub_year, doc.type_s === 'reference'));
  const typeKey = $derived(doc.type_s ?? '');
  const typeLabel = $derived(typeKey ? typeLabelFor(typeKey, locale) : '');

  // Type badge → a clickable facet. References surface their publication type
  // (reference_type_ss) so the badge reads "Chapitre" / "Article de revue…"
  // and filters academic literature by kind; every other subset shows its
  // type_s label and filters on that. null when neither is known.
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
  const isEntity = $derived(card === 'entity');
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
  const yearRange = $derived.by(() => {
    const a = doc.first_year;
    const b = doc.last_year;
    if (a && b) return a === b ? String(a) : `${a} – ${b}`;
    return a ? String(a) : b ? String(b) : '';
  });
  const entityCountryChips = $derived.by<FilterChip[]>(() =>
    (doc.country_ss ?? []).map((c) => ({
      field: 'country_ss',
      value: c,
      display: countryLabel(c, locale),
    })),
  );
  // Entity category (dcterms:isPartOf) — organisation kind for
  // organisations ("Organisation islamique"). Clickable is_part_of_ss filter.
  const entityPartOfChips = $derived.by<FilterChip[]>(() =>
    (doc.is_part_of_ss ?? []).map((v) => ({ field: 'is_part_of_ss', value: v, display: v })),
  );
  // Compact source line: Newspaper · Country, each a clickable filter. Kept
  // inside the body so the eyebrow row above the title only carries the date
  // + type chip — too many tokens up there reads as visual noise.
  const sourceChips = $derived.by<FilterChip[]>(() => {
    const out: FilterChip[] = [];
    if (doc.newspaper_ss?.[0]) {
      out.push({ field: 'newspaper_ss', value: doc.newspaper_ss[0], display: doc.newspaper_ss[0] });
    }
    if (doc.country_ss?.[0]) {
      out.push({
        field: 'country_ss',
        value: doc.country_ss[0],
        display: countryLabel(doc.country_ss[0], locale),
      });
    }
    return out;
  });

  // Body snippet: the OCR match first (most contextual), else the abstract
  // match. The title no longer doubles as a body snippet — it's highlighted
  // in place (see titleMarkup below).
  const rawSnippet = $derived(
    hit.highlights?.find((h) => h.field === 'ocr_text')?.snippet ??
      hit.highlights?.find((h) => h.field === 'abstract')?.snippet ??
      '',
  );
  const snippet = $derived(sanitizeSnippet(rawSnippet));

  // Title with the query match marked. highlight_full_fields covers
  // title_txt, so the highlight carries the COMPLETE title in `value`
  // (the `snippet` is a window past ~30 tokens — long reference titles
  // would render truncated). Empty (→ plain title) when no title match.
  const titleMarkup = $derived.by(() => {
    const h = hit.highlights?.find((x) => x.field === 'title_txt');
    const s = h?.value ?? h?.snippet ?? '';
    return s.includes('<mark>') ? sanitizeSnippet(s) : '';
  });

  // ── Match attribution: WHY is this hit shown? ───────────────────────
  // Title and body matches are visible on the card itself; matches in the
  // metadata channels (subject, spatial, author, journal, alternative
  // title, entity alias…) used to be invisible — the card just appeared,
  // unexplained. Surface them as a small "Matched in" line built from the
  // per-field highlights.
  const VISIBLE_MATCH_FIELDS = ['title_txt', 'ocr_text', 'abstract'];

  type MatchedIn = { field: string; label: string; snippet: string };
  const matchedIn = $derived.by<MatchedIn[]>(() => {
    const out: MatchedIn[] = [];
    for (const h of hit.highlights ?? []) {
      if (VISIBLE_MATCH_FIELDS.includes(h.field)) continue;
      if (out.some((m) => m.field === h.field)) continue;
      // Scalar fields carry `snippet`; string[] fields carry `snippets`.
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
  // Card body: prefer the query-match snippet (shows WHY this hit matched);
  // fall back to the abstract/description so browse-mode cards (no query,
  // no highlight) still show a couple of lines of context. The abstract is
  // plain text — Svelte escapes it — whereas the snippet carries <mark>.
  const abstract = $derived((doc.abstract ?? '').trim());
  // Author byline — essential for references (a citation without its author
  // is useless), informative for signed articles; simply absent when a doc
  // carries no creator_ss (e.g. unsigned press). Each author is an individual
  // creator_ss filter toggle.
  const authors = $derived(doc.creator_ss ?? []);

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

  function formatDate(epoch?: number, year?: number, yearOnly = false): string {
    if (!yearOnly && epoch && epoch > 0) {
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
      </header>

      <h3 class="iwac-card__title">
        {#if titleMarkup}
          <!-- sanitizeSnippet escaped everything but literal mark tags -->
          <!-- eslint-disable-next-line svelte/no-at-html-tags -->
          <a href={itemUrl}>{@html titleMarkup}</a>
        {:else}
          <a href={itemUrl}>{title}</a>
        {/if}
      </h3>

      {#if mentionsLabel || entityPartOfChips.length > 0 || entityCountryChips.length > 0}
        <ul class="iwac-card__source" aria-label={t('source')}>
          {#if mentionsLabel}
            <li class="iwac-card__chip iwac-card__chip--count">{mentionsLabel}</li>
          {/if}
          {#each entityPartOfChips as chip (chip.field + '|' + chip.value)}
            {@render filterChip(chip)}
          {/each}
          {#each entityCountryChips as chip (chip.field + '|' + chip.value)}
            {@render filterChip(chip)}
          {/each}
        </ul>
      {/if}
    {:else}
      <header class="iwac-card__head">
        {#if dateLabel}
          <time class="iwac-card__eyebrow">{dateLabel}</time>
        {/if}
        {#if typeChip}
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
      </header>

      <h3 class="iwac-card__title">
        {#if titleMarkup}
          <!-- sanitizeSnippet escaped everything but literal mark tags -->
          <!-- eslint-disable-next-line svelte/no-at-html-tags -->
          <a href={itemUrl}>{@html titleMarkup}</a>
        {:else}
          <a href={itemUrl}>{title}</a>
        {/if}
      </h3>

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
        <!-- snippet was HTML-escaped client-side; only literal mark tags survive (see sanitizeSnippet) -->
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
            >{#if i < matchedIn.length - 1}<span class="iwac-card__matched-sep"></span>{/if}{/each}
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
</article>

<style>
  /*
   * Ledger row, not a card. Rows are separated by hairlines owned by
   * ResultsList; the row itself is a flat grid with a hover wash. This
   * roughly doubles results-per-screen versus the boxed cards and reads
   * like the press archive it serves.
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
    background: color-mix(in oklab, var(--primary, #e64a19) 4%, transparent);
  }
  .iwac-card:has(:focus-visible) {
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
  }
  /* Drop the thumb column when there's no image. */
  .iwac-card--no-thumb {
    grid-template-columns: 1fr;
  }

  .iwac-card__thumb {
    display: block;
    width: 5rem;
    height: 5rem;
    border-radius: var(--radius-sm, 0.375rem);
    overflow: hidden;
    background: var(--surface-sunken, #f3f3f1);
    border: 1px solid var(--border-light, #e6e7eb);
  }
  .iwac-card__thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    /* Newsprint at rest, full plate on engagement. */
    filter: saturate(0.4) contrast(1.02);
    transition: filter var(--transition-base, 200ms ease);
  }
  .iwac-card:hover .iwac-card__thumb img {
    filter: none;
  }
  @media (prefers-reduced-motion: reduce) {
    .iwac-card__thumb img {
      transition: none;
    }
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
    color: var(--muted, #767880);
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 600;
    letter-spacing: var(--tracking-wider, 0.08em);
    text-transform: uppercase;
    font-variant-numeric: tabular-nums;
  }
  /*
   * Type badge: outlined chip with a categorical dot. The dot carries the
   * type colour (semantic theme tokens only — see the per-type rules), so a
   * scan down the list reads category from the dots without a pastel fill
   * shouting on every row. The old filled-orange active state put the brand
   * on every card of a filtered list; active is now border + ink + wash.
   */
  .iwac-card__type {
    --iwac-type-dot: var(--muted, #767880);
    display: inline-flex;
    align-items: center;
    gap: 0.4em;
    padding: 0.125rem 0.5rem;
    background: transparent;
    color: var(--ink-light, var(--ink, #2c2f37));
    border: 1px solid var(--border, #d4d6da);
    border-radius: var(--radius-full, 9999px);
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 600;
    letter-spacing: var(--tracking-wide, 0.04em);
    text-transform: uppercase;
    white-space: nowrap;
  }
  .iwac-card__type::before {
    content: '';
    width: 0.5em;
    height: 0.5em;
    border-radius: 50%;
    background: var(--iwac-type-dot);
    flex-shrink: 0;
  }
  /* Categorical dot colours — semantic theme tokens, no new hexes. */
  .iwac-card__type[data-type='article'] {
    --iwac-type-dot: var(--primary, #e64a19);
  }
  .iwac-card__type[data-type='audiovisual'] {
    --iwac-type-dot: var(--info, #4a90c8);
  }
  .iwac-card__type[data-type='publication'] {
    --iwac-type-dot: var(--secondary, #394f68);
  }
  .iwac-card__type[data-type='document'] {
    --iwac-type-dot: var(--warning, #e89c4a);
  }
  .iwac-card__type[data-type='photograph'] {
    --iwac-type-dot: var(--success, #6cc18b);
  }
  .iwac-card__type[data-type='reference'] {
    --iwac-type-dot: var(--muted, #767880);
  }
  .iwac-card__type[data-entity-type='Personnes'] {
    --iwac-type-dot: var(--info, #4a90c8);
  }
  .iwac-card__type[data-entity-type='Lieux'] {
    --iwac-type-dot: var(--success, #6cc18b);
  }
  .iwac-card__type[data-entity-type='Organisations'] {
    --iwac-type-dot: var(--warning, #e89c4a);
  }

  /*
   * Clickable type badge. The IWAC theme paints every <button> primary +
   * glow + hover-translate; class-level overrides win on specificity, but we
   * also zero box-shadow/transform explicitly so the theme can't leak through.
   */
  .iwac-card__type--filter {
    cursor: pointer;
    font-family: inherit;
    box-shadow: none;
    transition:
      background var(--transition-fast, 150ms ease),
      border-color var(--transition-fast, 150ms ease),
      color var(--transition-fast, 150ms ease),
      box-shadow var(--transition-fast, 150ms ease);
  }
  .iwac-card__type--filter:hover {
    background: transparent;
    border-color: var(--ink-strong, var(--ink, #2c2f37));
    color: var(--ink-strong, var(--ink, #2c2f37));
    box-shadow: none;
    transform: none;
  }
  .iwac-card__type--filter.is-active {
    background: color-mix(in oklab, var(--primary, #e64a19) 12%, transparent);
    border-color: var(--primary, #e64a19);
    color: var(--ink-strong, var(--ink, #2c2f37));
  }
  .iwac-card__type--filter:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
  }

  .iwac-card__title {
    margin: 0;
    font-size: var(--text-lg, 1.1875rem);
    line-height: 1.3;
    color: var(--ink-strong, var(--ink, #2c2f37));
    /* Clarendon slabs clog at tighter tracking. */
    letter-spacing: -0.01em;
  }
  .iwac-card__title a {
    color: inherit;
    text-decoration: none;
  }
  .iwac-card__title a:hover {
    color: var(--primary, #e64a19);
    text-decoration: underline;
    text-underline-offset: 2px;
  }

  .iwac-card__byline {
    margin: 0;
    font-size: var(--text-sm, 0.9375rem);
    color: var(--ink-light, var(--ink, #2c2f37));
    line-height: 1.4;
    /* Clamp long author lists to two lines so cards stay even. */
    display: -webkit-box;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
  /*
   * Each author is an inline creator_ss filter toggle. Reset the theme's
   * button paint so the byline still reads as text; brand the active author.
   */
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
    color: var(--primary, #e64a19);
    background: none;
    box-shadow: none;
    transform: none;
    text-decoration: underline;
    text-underline-offset: 2px;
  }
  .iwac-card__author.is-active {
    color: var(--primary, #e64a19);
    font-weight: 600;
    text-decoration: underline;
    text-underline-offset: 2px;
  }
  .iwac-card__author:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
    border-radius: var(--radius-sm, 0.375rem);
  }
  /* CSS-generated ", " — the Svelte compiler trims a literal trailing
     space at the {#if} block boundary (the authors used to render as
     "Madore,Anato"), and generated content is immune to that. */
  .iwac-card__byline-sep::before {
    content: ', ';
    color: var(--ink-light, var(--ink, #2c2f37));
  }

  /*
   * Small leading icon on metadata lines and chips (author, citation,
   * newspaper, country). Muted so the icon reads as a field marker, not a
   * control; the inline SVG inherits this color via currentColor.
   */
  .iwac-card__meta-icon {
    display: inline-block;
    color: var(--muted, #767880);
    margin-inline-end: 0.35em;
    font-size: 0.875em;
  }

  /* Source line for references (journal · volume · pages, book · publisher).
     Italic + muted so it reads as bibliographic metadata under the byline,
     distinct from the abstract below it. */
  .iwac-card__citation {
    margin: 0;
    font-size: var(--text-sm, 0.9375rem);
    font-style: italic;
    color: var(--muted, #767880);
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
    color: var(--ink-light, var(--ink, #2c2f37));
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
    color: var(--muted, #767880);
  }
  .iwac-card__snippet :global(mark),
  .iwac-card__matched :global(mark),
  .iwac-card__title :global(mark) {
    background: color-mix(
      in oklab,
      var(--primary, #e64a19) var(--accent-mix-subtle, 25%),
      transparent
    );
    color: inherit;
    border-radius: var(--radius-sm, 0.375rem);
    padding-inline: 0.15em;
  }

  /*
   * "Matched in" attribution — why this hit is in the list when the match
   * isn't visible in title/body: subject, spatial coverage, author,
   * journal, alternative title, entity alias. Quiet one-liner above the
   * source line; the <mark> inside each value carries the evidence.
   */
  .iwac-card__matched {
    margin: var(--space-xs, 0.25rem) 0 0;
    font-size: var(--text-xs, 0.8125rem);
    color: var(--muted, #767880);
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
    color: var(--ink-light, var(--ink, #2c2f37));
  }
  /* Generated separators (see .iwac-card__byline-sep for why). */
  .iwac-card__matched-field::after {
    content: ' : ';
  }
  .iwac-card__matched-sep::before {
    content: ' · ';
    font-weight: 700;
  }

  /*
   * Source line: quiet text tokens separated by interpuncts — a byline,
   * not a chip tray. Plain INLINE flow (not flex) so every token and
   * separator shares one text baseline; flex items drifted vertically.
   * Each token is still a working facet toggle.
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
    color: var(--muted, #767880);
    padding-inline: 0.35em 0.5em;
    font-weight: 700;
  }
  .iwac-card__chip {
    display: inline;
    margin: 0;
    padding: 0;
    background: transparent;
    color: var(--muted, #767880);
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
    color: var(--primary, #e64a19);
    text-decoration: underline;
    text-underline-offset: 2px;
    box-shadow: none;
    transform: none;
  }
  .iwac-card__chip--filter.is-active {
    background: transparent;
    color: var(--primary, #e64a19);
    font-weight: 600;
    text-decoration: underline;
    text-underline-offset: 2px;
  }
  .iwac-card__chip--filter:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
    border-radius: var(--radius-sm, 0.375rem);
  }
  /* Occurrence count on entity cards — the headline metric. */
  .iwac-card__chip--count {
    color: var(--ink-strong, var(--ink, #2c2f37));
    font-weight: 700;
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
