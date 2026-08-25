import type { IwacHit } from './types';
import { countryLabel, entityTypeLabel, typeLabel as typeLabelFor, useI18n } from './i18n';
import { sizedThumbnail, thumbnailSrcset } from './thumbnail';
import { itemPath, localizeSiteUrl } from './siteUrl';
import { densifyByYear, parseMentionsByYear } from './sparkline';
import {
  buildCitation,
  buildSourceChips,
  formatDate,
  formatDuration,
  formatYearRange,
  pickExternalLink,
  pickMatchedIn,
  pickSnippet,
  pickTitleMarkup,
  resultLanguageTag,
  type CardChip,
} from './resultCard';

export { CHIP_ICONS, type CardChip, type MatchedIn } from './resultCard';

/**
 * Everything a card renders, kept reactive to the hit and the surrounding
 * scope.
 *
 * MUST be called during component initialisation — it reads the i18n
 * context, like every other composable in this codebase.
 *
 * @param input Reactive accessor for the card's inputs (a thunk, so the
 *   returned values track the component's props).
 */
export function createResultCard(input: () => { hit: IwacHit; hideCountry: boolean }) {
  const { locale, card, t } = useI18n();

  const doc = $derived(input().hit.document);
  const hideCountry = $derived(input().hideCountry);

  const title = $derived(doc.title || t('untitled', { id: doc.id }));
  /**
   * BCP-47 tag for this record's own text, or undefined when it names no
   * language the map knows. The card stamps it on the title and the body
   * text so a French headline is not read aloud in an English voice on the
   * English site (and vice versa on the French one).
   */
  const langTag = $derived(resultLanguageTag(doc));
  const typeKey = $derived(doc.type_s ?? '');
  const typeLabel = $derived(typeKey ? typeLabelFor(typeKey, locale) : '');
  const isReference = $derived(typeKey === 'reference');
  const referenceType = $derived(doc.reference_type_ss?.[0] ?? '');
  const dateLabel = $derived(formatDate(locale, doc.date, doc.pub_year, isReference));

  // Thumbnail source. ONE derivation for both layouts now: `medium` is the
  // 1× answer at both the 112px list thumb and the ~190px gallery tile, and
  // the srcset lets the browser take `large` on a high-DPR screen instead of
  // on every screen. The two used to differ — the gallery asked for `large`
  // unconditionally, which cost 41–141 KB per 190px tile (see
  // lib/thumbnail.ts for the measured tiers). The layouts still differ in
  // `sizes`, which is a property of the slot, so it lives in the component.
  const thumbSrc = $derived(sizedThumbnail(doc.thumbnail_url, 'medium'));
  const thumbSrcset = $derived(thumbnailSrcset(doc.thumbnail_url, ['medium', 'large']));

  // Type badge → a clickable facet. References surface their publication
  // type (reference_type_ss) so the badge reads "Chapitre" / "Article de
  // revue…"; every other subset shows its type_s label.
  const typeChip = $derived.by<CardChip | null>(() => {
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

  // Entity (index) card variant. Context-driven on the entity index surface;
  // shape-driven as a fallback so MIXED hit lists (the federated "All" union
  // tab) render entity docs as entity cards — entity docs always carry
  // entity_type_s, content docs never do.
  const isEntity = $derived(card === 'entity' || doc.entity_type_s != null);
  const entityTypeChip = $derived.by<CardChip | null>(() =>
    doc.entity_type_s
      ? {
          field: 'entity_type_s',
          value: doc.entity_type_s,
          display: entityTypeLabel(doc.entity_type_s, locale),
        }
      : null,
  );
  const frequency = $derived(typeof doc.frequency === 'number' ? doc.frequency : null);
  const mentionsLabel = $derived(
    frequency != null
      ? t(frequency === 1 ? 'mention_one' : 'mention_other', { n: frequency.toLocaleString() })
      : '',
  );
  // The bare "mention(s)" word for the split number + label metric (the
  // number is rendered separately as a display numeral). t() with an empty n
  // yields " mentions" → trimmed.
  const mentionsWord = $derived(
    frequency != null ? t(frequency === 1 ? 'mention_one' : 'mention_other', { n: '' }).trim() : '',
  );
  // Per-year mentions series for the sparkline. Empty until the entity
  // collection is rebuilt with mentions_by_year_s, so the card simply omits
  // the sparkline when the data isn't there.
  const mentionsSeries = $derived(densifyByYear(parseMentionsByYear(doc.mentions_by_year_s)));
  const yearRange = $derived(formatYearRange(doc.first_year, doc.last_year));

  const entityCountryChips = $derived.by<CardChip[]>(() =>
    hideCountry
      ? []
      : (doc.country_ss ?? []).map((c) => ({
          field: 'country_ss',
          value: c,
          display: countryLabel(c, locale),
        })),
  );
  // Entity category (dcterms:isPartOf) — organisation kind for organisations.
  const entityPartOfChips = $derived.by<CardChip[]>(() =>
    (doc.is_part_of_ss ?? []).map((v) => ({ field: 'is_part_of_ss', value: v, display: v })),
  );
  // Compact source line: publisher · country (· carrier). The rule lives in
  // resultCard.ts, where it is unit-tested.
  const sourceChips = $derived(buildSourceChips(doc, { hideCountry, locale }));

  const snippet = $derived(pickSnippet(input().hit));
  const titleMarkup = $derived(pickTitleMarkup(input().hit));
  const matchedIn = $derived(pickMatchedIn(input().hit, locale));

  // Citation icon: journal page for periodical pieces, book covers the rest.
  const citationIcon = $derived(
    referenceType === 'Article de revue' || referenceType === 'Compte rendu'
      ? ('journal' as const)
      : ('book' as const),
  );
  // Audiovisual: running time in the dateline, and the canonical watch link
  // as a secondary action. The title link stays on the IWAC item — the
  // external URL is offered beside provenance, never in place of it.
  const duration = $derived(formatDuration(doc.duration_seconds));
  const externalLink = $derived.by(() => {
    const link = pickExternalLink(doc);
    return link ? { url: link.url, label: t(link.labelKey) } : null;
  });

  const citation = $derived(buildCitation(doc, t('cite_eds')));
  const abstract = $derived((doc.abstract ?? '').trim());
  // Author byline — essential for references, informative for signed articles.
  const authors = $derived(doc.creator_ss ?? []);
  // The index stores every omeka_url against the canonical FRENCH site, so it
  // is rewritten onto the site the reader is on (see lib/siteUrl.ts). The
  // fallback used to hardcode the French slug too, which sent an English
  // reader across editions even for a document with no indexed URL at all.
  const itemUrl = $derived(doc.omeka_url ? localizeSiteUrl(doc.omeka_url) : itemPath(doc.id));

  return {
    get doc() {
      return doc;
    },
    get title() {
      return title;
    },
    get titleMarkup() {
      return titleMarkup;
    },
    get itemUrl() {
      return itemUrl;
    },
    get dateLabel() {
      return dateLabel;
    },
    get duration() {
      return duration;
    },
    get externalLink() {
      return externalLink;
    },
    get thumbSrc() {
      return thumbSrc;
    },
    get thumbSrcset() {
      return thumbSrcset;
    },
    get typeChip() {
      return typeChip;
    },
    get typeTint() {
      return typeTint;
    },
    get isEntity() {
      return isEntity;
    },
    get entityTypeChip() {
      return entityTypeChip;
    },
    get frequency() {
      return frequency;
    },
    get mentionsLabel() {
      return mentionsLabel;
    },
    get mentionsWord() {
      return mentionsWord;
    },
    get mentionsSeries() {
      return mentionsSeries;
    },
    get yearRange() {
      return yearRange;
    },
    get entityCountryChips() {
      return entityCountryChips;
    },
    get entityPartOfChips() {
      return entityPartOfChips;
    },
    get sourceChips() {
      return sourceChips;
    },
    get snippet() {
      return snippet;
    },
    get abstract() {
      return abstract;
    },
    get matchedIn() {
      return matchedIn;
    },
    get authors() {
      return authors;
    },
    get citation() {
      return citation;
    },
    get citationIcon() {
      return citationIcon;
    },
    get langTag() {
      return langTag;
    },
  };
}
