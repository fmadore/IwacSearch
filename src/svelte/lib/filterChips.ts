/**
 * Active-filter chip derivation, shared across surfaces.
 *
 * One source of truth for "what filters are active, as removable chips" — the
 * FacetPanel sidebar, the persistent result-summary strip, and the scope-aware
 * empty state all render the same chips from the same selections, so they can
 * never disagree about what the user is looking at (design review §02).
 *
 * A chip's `value` is the RAW filter token (what gets toggled off on remove);
 * `displayValue` is the human label (subjectivity 1–5 → words, country spelling
 * normalised, type discriminator → its locale label). `kind` distinguishes the
 * categorical facets from the single year-range chip, which clears differently.
 */

import type { ActiveFilters, YearRange } from './types';
import { facetLabel, facetValueLabel, type Locale, type Translate } from './i18n';

/** Year-slider bounds — kept here so every chip surface shows the same span. */
export const DEFAULT_YEAR_MIN = 1960;
export const DEFAULT_YEAR_MAX = 2025;

export interface ActiveFilterChip {
  /** Schema facet field (e.g. `country_ss`), or `pub_year` for the year chip. */
  field: string;
  /** Raw value — the token toggled off when the chip is removed. */
  value: string;
  /** Human-facing value (subjectivity words, normalised country spelling, …). */
  displayValue: string;
  /** Facet field label (e.g. "Country"). */
  label: string;
  kind: 'facet' | 'year';
}

/**
 * Build the active-filter chips for a set of selections + year range.
 * Categorical chips come first (in field/value order), the year chip last.
 */
export function deriveActiveChips(args: {
  selected: ActiveFilters;
  yearRange: YearRange | null;
  locale: Locale;
  t: Translate;
  yearMin?: number;
  yearMax?: number;
  /** Rare per-field label overrides (same map FacetPanel accepts). */
  labels?: Record<string, string>;
}): ActiveFilterChip[] {
  const {
    selected,
    yearRange,
    locale,
    t,
    yearMin = DEFAULT_YEAR_MIN,
    yearMax = DEFAULT_YEAR_MAX,
    labels,
  } = args;

  const chips: ActiveFilterChip[] = [];
  for (const [field, values] of Object.entries(selected)) {
    for (const v of values) {
      chips.push({
        field,
        value: v,
        displayValue: facetValueLabel(field, v, locale),
        label: labels?.[field] ?? facetLabel(field, locale),
        kind: 'facet',
      });
    }
  }
  if (yearRange) {
    const lo = yearRange.from ?? yearMin;
    const hi = yearRange.to ?? yearMax;
    const range = `${lo} – ${hi}`;
    chips.push({
      field: 'pub_year',
      value: range,
      displayValue: range,
      label: t('year'),
      kind: 'year',
    });
  }
  return chips;
}
