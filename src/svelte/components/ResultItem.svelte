<script lang="ts">
  import type { ActiveFilters, IwacHit, ViewMode } from '../lib/types';
  import { facetLabel, useI18n } from '../lib/i18n';
  import { CHIP_ICONS, createResultCard, type CardChip } from '../lib/resultCard.svelte';
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
   *   - Thumbnails offer the browser two Omeka derivative tiers with a `sizes`
   *     describing the slot, so a 1× screen takes the 9 KB `medium` and a
   *     retina one the `large` — instead of every screen taking `large` for a
   *     190px tile (see lib/thumbnail.ts for the measured tier sizes).
   *
   * Clickable metadata: the type badge, author byline, newspaper and country
   * are facet-toggle buttons calling onFacetToggle — the same handler the
   * FacetPanel uses — so a card doubles as a filter affordance. Active filters
   * render brand-coloured (aria-pressed reflects it).
   *
   * Snippet sanitisation: Typesense returns highlighted HTML with <mark> tags.
   * We HTML-escape the snippet client-side and only reinstate literal
   * <mark>/</mark> — see lib/sanitize.ts.
   *
   * WHAT LIVES WHERE: every hit → display-field derivation (which highlight
   * becomes the snippet, how a citation line is assembled, when a date is
   * only a year) is in lib/resultCard.svelte.ts, where the pure parts are
   * unit-tested. This file owns the two layouts, the CSS both share, and the
   * facet-toggle interaction. The layouts stayed ONE component on purpose:
   * they differ by ~7 CSS rules out of ~65 and by which shared snippets they
   * render, so splitting them would either duplicate the other ~450 lines of
   * scoped CSS or push it into a global stylesheet — paying real risk to
   * separate files that mostly agree.
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

  const { locale, t } = useI18n();

  // Every displayed field, derived once from the hit (see the module's
  // docblock). Passed a thunk so the derivations track the props.
  const cardData = createResultCard(() => ({ hit, hideCountry }));

  // ── Interaction: a card badge is a facet toggle ──────────────────────
  function isActive(field: string, value: string): boolean {
    return activeFilters[field]?.includes(value) ?? false;
  }
  function toggle(field: string, value: string): void {
    onFacetToggle(field, value, !isActive(field, value));
  }
  /** aria-label that announces what clicking the chip will do. */
  function chipAria(chip: CardChip): string {
    const label = facetLabel(chip.field, locale);
    return isActive(chip.field, chip.value)
      ? t('remove_filter', { label, value: chip.display })
      : t('add_filter', { label, value: chip.display });
  }
</script>

<!-- A source/country chip rendered as a facet toggle button. -->
{#snippet filterChip(chip: CardChip)}
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
  <!-- {@const} binds the chip once so it narrows: reading it off cardData
       twice is two getter calls, which TypeScript can't prove are the same
       non-null value. -->
  {#if cardData.isEntity}
    {@const chip = cardData.entityTypeChip}
    {#if chip}
      <button
        type="button"
        class="iwac-card__type iwac-card__type--filter"
        class:is-active={isActive(chip.field, chip.value)}
        data-entity-type={cardData.doc.entity_type_s}
        aria-pressed={isActive(chip.field, chip.value)}
        aria-label={chipAria(chip)}
        onclick={() => toggle(chip.field, chip.value)}>{chip.display}</button
      >
    {/if}
  {:else}
    {@const chip = cardData.typeChip}
    {#if chip}
      <button
        type="button"
        class="iwac-card__type iwac-card__type--filter"
        class:is-active={isActive(chip.field, chip.value)}
        data-type={cardData.typeTint}
        aria-pressed={isActive(chip.field, chip.value)}
        aria-label={chipAria(chip)}
        onclick={() => toggle(chip.field, chip.value)}>{chip.display}</button
      >
    {/if}
  {/if}
{/snippet}

<!-- Dateline eyebrow date (content: precise date; entity: mention year span). -->
{#snippet eyebrowDate()}
  {#if cardData.isEntity}
    {#if cardData.yearRange}<time class="iwac-card__eyebrow">{cardData.yearRange}</time>{/if}
  {:else if cardData.dateLabel}<time class="iwac-card__eyebrow">{cardData.dateLabel}</time>{/if}
{/snippet}

<!-- Running time, for the audiovisual records that carry one. Sits at the end
     of the dateline so the eye reads TYPE · DATE · 5:32 in one sweep. -->
{#snippet eyebrowDuration()}
  {#if !cardData.isEntity && cardData.duration}
    <span class="iwac-card__eyebrow iwac-card__eyebrow--duration" title={t('duration')}>
      <span class="iwac-card__meta-icon" aria-hidden="true"><Icon name="clock" /></span
      >{cardData.duration}
    </span>
  {/if}
{/snippet}

<!-- Canonical external link (e.g. "Watch on YouTube"). Rendered BESIDE the
     source chips, never as the title target: the card's primary link must
     stay on the IWAC record, which is the provenance. -->
{#snippet externalAction()}
  {#if cardData.externalLink}
    {@const link = cardData.externalLink}
    <li>
      <a
        class="iwac-card__chip iwac-card__chip--external"
        href={link.url}
        target="_blank"
        rel="noopener noreferrer external"
        ><span class="iwac-card__meta-icon" aria-hidden="true"><Icon name="play" /></span
        >{link.label}</a
      >
    </li>
  {/if}
{/snippet}

<!-- Title link, with the query match highlighted in place when present. -->
{#snippet titleLink()}
  {#if cardData.titleMarkup}
    <!-- sanitizeHighlight escaped everything but literal mark tags -->
    <!-- eslint-disable-next-line svelte/no-at-html-tags -->
    <a href={cardData.itemUrl} lang={cardData.langTag}>{@html cardData.titleMarkup}</a>
  {:else}
    <a href={cardData.itemUrl} lang={cardData.langTag}>{cardData.title}</a>
  {/if}
{/snippet}

<article
  class="iwac-card iwac-card--{layout}"
  class:iwac-card--no-thumb={layout === 'list' && !cardData.thumbSrc}
>
  {#if layout === 'gallery'}
    <!-- ── Gallery tile: image-forward, compact metadata below ── -->
    <a
      class="iwac-card__thumb iwac-card__thumb--gallery"
      href={cardData.itemUrl}
      aria-hidden="true"
      tabindex="-1"
    >
      {#if cardData.thumbSrc}
        <!-- 4:3 tile. `sizes` describes the SLOT so the browser can pick the
             tier: two-up under 600px, otherwise the ~200px the auto-fill grid
             settles on. Intrinsic width/height are the `medium` constraint,
             present so the box is reserved even if the CSS aspect-ratio
             hasn't applied yet. -->
        <img
          src={cardData.thumbSrc}
          srcset={cardData.thumbSrcset}
          sizes="(max-width: 599px) 45vw, 200px"
          width="200"
          height="150"
          alt=""
          loading="lazy"
          decoding="async"
        />
      {:else}
        <span class="iwac-card__thumb-ph" aria-hidden="true"><Icon name="image" /></span>
      {/if}
    </a>
    <div class="iwac-card__body iwac-card__body--gallery">
      <header class="iwac-card__head">
        {@render typeBadge()}{@render eyebrowDate()}{@render eyebrowDuration()}
      </header>
      <h3 class="iwac-card__title iwac-card__title--gallery">{@render titleLink()}</h3>
      {#if cardData.isEntity}
        {#if cardData.mentionsLabel}<p class="iwac-card__gallery-meta">
            {cardData.mentionsLabel}
          </p>{/if}
      {:else if cardData.sourceChips.length > 0 || cardData.externalLink}
        <ul class="iwac-card__source" aria-label={t('source')}>
          {#each cardData.sourceChips as chip (chip.field + '|' + chip.value)}
            {@render filterChip(chip)}
          {/each}
          {@render externalAction()}
        </ul>
      {/if}
    </div>
  {:else}
    <!-- ── List ledger row ── -->
    {#if cardData.thumbSrc}
      <a class="iwac-card__thumb" href={cardData.itemUrl} aria-hidden="true" tabindex="-1">
        <!-- Fixed 7rem square (9rem tall and full-width under 600px, see the
             media query), so `sizes` is stated rather than computed. -->
        <img
          src={cardData.thumbSrc}
          srcset={cardData.thumbSrcset}
          sizes="(max-width: 599px) 100vw, 112px"
          width="200"
          height="150"
          alt=""
          loading="lazy"
          decoding="async"
        />
      </a>
    {/if}

    <div class="iwac-card__body">
      {#if cardData.isEntity}
        <!-- Index/authority entity card: year-span eyebrow, type badge,
             occurrence metric + sparkline + countries. No body text. -->
        <header class="iwac-card__head">{@render typeBadge()}{@render eyebrowDate()}</header>

        <h3 class="iwac-card__title">{@render titleLink()}</h3>

        {#if cardData.frequency != null || cardData.mentionsSeries.length >= 2}
          <div class="iwac-card__metrics">
            {#if cardData.frequency != null}
              <span class="iwac-card__mentions">
                <span class="iwac-card__mentions-n">{cardData.frequency.toLocaleString()}</span>
                <span class="iwac-card__mentions-label">{cardData.mentionsWord}</span>
              </span>
            {/if}
            {#if cardData.authoredCount != null}
              <span class="iwac-card__authored">{cardData.authoredLabel}</span>
            {/if}
            {#if cardData.mentionsSeries.length >= 2}
              <span
                class="iwac-card__spark"
                data-entity-type={cardData.doc.entity_type_s}
                title={t('mentions_trend')}
              >
                <Sparkline values={cardData.mentionsSeries} />
              </span>
            {/if}
          </div>
        {/if}

        {#if cardData.entityPartOfChips.length > 0 || cardData.entityCountryChips.length > 0}
          <ul class="iwac-card__source" aria-label={t('source')}>
            {#each cardData.entityPartOfChips as chip (chip.field + '|' + chip.value)}
              {@render filterChip(chip)}
            {/each}
            {#each cardData.entityCountryChips as chip (chip.field + '|' + chip.value)}
              {@render filterChip(chip)}
            {/each}
          </ul>
        {/if}
      {:else}
        <header class="iwac-card__head">
          {@render typeBadge()}{@render eyebrowDate()}{@render eyebrowDuration()}
        </header>

        <h3 class="iwac-card__title">{@render titleLink()}</h3>

        {#if cardData.authors.length > 0}
          <p class="iwac-card__byline">
            <span class="iwac-card__meta-icon" aria-hidden="true"><Icon name="person" /></span
            >{#each cardData.authors as author, i (author)}<button
                type="button"
                class="iwac-card__author"
                class:is-active={isActive('creator_ss', author)}
                aria-pressed={isActive('creator_ss', author)}
                aria-label={chipAria({ field: 'creator_ss', value: author, display: author })}
                onclick={() => toggle('creator_ss', author)}>{author}</button
              >{#if i < cardData.authors.length - 1}<span class="iwac-card__byline-sep"
                ></span>{/if}{/each}
          </p>
        {/if}

        {#if cardData.citation}
          <p class="iwac-card__citation">
            <span class="iwac-card__meta-icon" aria-hidden="true"
              ><Icon name={cardData.citationIcon} /></span
            >{cardData.citation}
          </p>
        {/if}

        {#if cardData.snippet}
          <!-- cardData.snippet was HTML-escaped client-side; only literal mark tags survive -->
          <!-- eslint-disable-next-line svelte/no-at-html-tags -->
          <p class="iwac-card__snippet" lang={cardData.langTag}>{@html cardData.snippet}</p>
        {:else if cardData.abstract}
          <p class="iwac-card__snippet iwac-card__snippet--abstract" lang={cardData.langTag}>
            {cardData.abstract}
          </p>
        {/if}

        {#if cardData.matchedIn.length > 0}
          <p class="iwac-card__matched">
            <span class="iwac-card__matched-label">{t('matched_in')}</span>
            {#each cardData.matchedIn as m, i (m.field)}<span class="iwac-card__matched-item"
                ><span class="iwac-card__matched-field">{m.label}</span
                ><!-- eslint-disable-next-line svelte/no-at-html-tags --><span
                  class="iwac-card__matched-value">{@html m.snippet}</span
                ></span
              >{#if i < cardData.matchedIn.length - 1}<span class="iwac-card__matched-sep"
                ></span>{/if}{/each}
          </p>
        {/if}

        {#if cardData.sourceChips.length > 0 || cardData.externalLink}
          <ul class="iwac-card__source" aria-label={t('source')}>
            {#each cardData.sourceChips as chip (chip.field + '|' + chip.value)}
              {@render filterChip(chip)}
            {/each}
            {@render externalAction()}
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
    transition: background-color var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1));
  }
  .iwac-card:hover {
    background: color-mix(in oklab, var(--primary, #ce4115) 4%, transparent);
  }
  /* Row-level context for whichever control inside is focused. The indicator
     itself is that control's own --focus-outline; this is the hover wash
     reused so the eye finds the row, not a second (weaker) ring competing
     with it. */
  .iwac-card:has(:focus-visible) {
    background: color-mix(in oklab, var(--primary, #ce4115) 4%, transparent);
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
    transition: filter var(--transition-base, 200ms cubic-bezier(0.25, 1, 0.5, 1));
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
    /* A glyph, not text — but still a font-size, so it comes off the scale
       (nearest step to the previous bare 2rem). */
    font-size: var(--text-2xl, 1.875rem);
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
    color: var(--ink-light, #3f4349);
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
  /* Running time closes the dateline; a muted interpunct separates it from
     the date (or from the type badge on an undated recording). */
  .iwac-card__eyebrow--duration::before {
    content: '·';
    color: var(--muted, #66696e);
    font-weight: 700;
    padding-inline: 0.45em 0.5em;
  }
  .iwac-card__eyebrow--duration {
    color: var(--muted, #66696e);
    letter-spacing: normal;
    text-transform: none;
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
    --iwac-type-dot: var(--type-document, #d66800);
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
    --iwac-type-dot: var(--type-entity-organisations, #d66800);
  }

  /*
   * Clickable type badge. The IWAC theme paints every <button> primary + glow +
   * hover-translate; we zero box-shadow/transform explicitly so it can't leak.
   */
  /*
   * SC 2.5.8: the card's facet toggles measured 20–21px tall at 375. They sit
   * INSIDE running text — a dateline, a byline, a source line, each with
   * non-target interpuncts between them — so the Inline exception arguably
   * covers them; but the block padding below buys the 24px outright and costs
   * nothing, because vertical padding on an inline box grows the hit area (and
   * the focus ring) without touching the line box.
   */
  .iwac-card__type--filter {
    cursor: pointer;
    font-family: inherit;
    box-shadow: none;
    padding-block: 0.15rem;
    transition: color var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1));
  }
  .iwac-card__type--filter:hover {
    background: transparent;
    color: var(--ink-strong, #05070c);
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
    outline: var(--focus-outline, 2px solid #ce4115);
    outline-offset: 2px;
  }

  .iwac-card__title {
    margin: 0;
    font-size: var(--text-lg, 1.1875rem);
    line-height: 1.3;
    color: var(--ink-strong, #05070c);
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
    color: var(--ink-light, #3f4349);
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
    /* See the SC 2.5.8 note on .iwac-card__type--filter. */
    padding: 0.15rem 0;
    border: none;
    background: none;
    box-shadow: none;
    font: inherit;
    color: inherit;
    cursor: pointer;
    text-align: start;
    transition: color var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1));
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
    outline: var(--focus-outline, 2px solid #ce4115);
    outline-offset: 2px;
  }
  .iwac-card__byline-sep::before {
    content: ', ';
    color: var(--ink-light, #3f4349);
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
    color: var(--ink-light, #3f4349);
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
    color: var(--ink-light, #3f4349);
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
    font-family: var(
      --font-headings,
      'Besley',
      'Source Serif 4',
      Georgia,
      'Times New Roman',
      serif
    );
    font-size: var(--text-xl, 1.5rem);
    font-weight: 700;
    line-height: 1;
    color: var(--ink-strong, #05070c);
    /* Lining, NOT tabular. Besley's `tnum` zero is frozen at a 55-unit
       advance on every weight while 1-9 track the weight axis (66 at 700),
       so a tabular zero renders 17% narrow and reads as un-bolded ("3 009").
       Declared as font-feature-settings because the theme's body sets an
       inherited "onum"/"pnum" that outranks font-variant-numeric. */
    font-feature-settings:
      'kern' 1,
      'liga' 1,
      'lnum' 1;
    letter-spacing: var(--tracking-display, -0.01em);
  }
  .iwac-card__mentions-label {
    font-size: var(--text-xs, 0.8125rem);
    color: var(--muted, #66696e);
  }
  /* Authorship breakdown ("dont 8 signés"). Deliberately in the LABEL
     register, not the numeral one: it qualifies the count beside it rather
     than competing with it, so it gets no display figure of its own. */
  .iwac-card__authored {
    font-size: var(--text-xs, 0.8125rem);
    color: var(--muted, #66696e);
    font-style: italic;
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
    color: var(--type-entity-organisations, #d66800);
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
  .iwac-card__chip--filter,
  .iwac-card__chip--external {
    /* See the SC 2.5.8 note on .iwac-card__type--filter. */
    padding-block: 0.15rem;
  }
  .iwac-card__chip--filter {
    cursor: pointer;
    font-family: inherit;
    box-shadow: none;
    transition: color var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1));
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
    outline: var(--focus-outline, 2px solid #ce4115);
    outline-offset: 2px;
  }

  /*
   * The one OUTBOUND link on a card (e.g. "Watch on YouTube"). Styled like the
   * filter chips beside it but underlined at rest, because it leaves the site
   * — the row's other tokens all filter in place, and an off-site jump should
   * not look identical to them.
   */
  .iwac-card__chip--external {
    color: var(--muted, #66696e);
    text-decoration: underline;
    text-underline-offset: 2px;
    text-decoration-style: dotted;
  }
  .iwac-card__chip--external:hover {
    color: var(--primary, #ce4115);
    text-decoration-style: solid;
  }
  .iwac-card__chip--external:focus-visible {
    outline: var(--focus-outline, 2px solid #ce4115);
    outline-offset: 2px;
  }

  /* Compact entity meta in a gallery tile (e.g. "312 mentions"). */
  .iwac-card__gallery-meta {
    margin: 0;
    font-size: var(--text-xs, 0.8125rem);
    color: var(--muted, #66696e);
    font-variant-numeric: tabular-nums;
  }

  /* Narrow viewport: stack the list thumb on top so titles get full width. */
  @media (max-width: 599px) {
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
