import type { ActiveFilters, YearRange } from './types';
import type { ActiveFilterChip } from './filterChips';

/**
 * The user's active filter selections: categorical facet values plus the
 * year range.
 *
 * Extracted from App.svelte, where five handlers and a derived count were
 * interleaved with query/page/sort/response state. The rules here are small
 * but load-bearing and worth having in one testable place:
 *
 *   - toggling the last value off DELETES the field key rather than leaving
 *     an empty array, so the URL codec doesn't emit `?f.country_ss=` husks;
 *   - every mutation resets to page 1, because a narrowed result set makes
 *     the current page number meaningless (and often out of range);
 *   - assignments replace rather than mutate, so Svelte's `$state` proxy sees
 *     a new object and dependent effects re-run.
 *
 * `onChange` fires after every mutation — App uses it to reset the page.
 */
export function createFilterState(
  initialFilters: ActiveFilters,
  initialYearRange: YearRange | null,
  onChange: () => void,
) {
  let filters = $state<ActiveFilters>(initialFilters);
  let yearRange = $state<YearRange | null>(initialYearRange);

  // Drives the badge on the mobile Filters trigger: every selected facet
  // value, plus one for an active year range.
  const activeCount = $derived(
    Object.values(filters).reduce((n, vs) => n + (vs?.length ?? 0), 0) + (yearRange ? 1 : 0),
  );

  return {
    get filters(): ActiveFilters {
      return filters;
    },
    get yearRange(): YearRange | null {
      return yearRange;
    },
    get activeCount(): number {
      return activeCount;
    },

    /** Replace wholesale — used when the back button re-hydrates from the URL. */
    hydrate(nextFilters: ActiveFilters, nextYearRange: YearRange | null): void {
      filters = nextFilters;
      yearRange = nextYearRange;
    },

    toggle(field: string, value: string, nextChecked: boolean): void {
      const current = filters[field] ?? [];
      if (nextChecked) {
        if (!current.includes(value)) {
          filters = { ...filters, [field]: [...current, value] };
        }
      } else {
        const kept = current.filter((v) => v !== value);
        if (kept.length === 0) {
          // Drop the key entirely so empty filter entries don't pollute
          // the URL state.
          const next = { ...filters };
          delete next[field];
          filters = next;
        } else {
          filters = { ...filters, [field]: kept };
        }
      }
      onChange();
    },

    clearField(field: string): void {
      const next = { ...filters };
      delete next[field];
      filters = next;
      onChange();
    },

    clearAll(): void {
      filters = {};
      yearRange = null;
      onChange();
    },

    setYearRange(next: YearRange | null): void {
      yearRange = next;
      onChange();
    },

    /**
     * Remove one chip from the summary strip / empty state. The year chip
     * clears the range; every other chip toggles its facet value off.
     */
    removeChip(chip: ActiveFilterChip): void {
      if (chip.kind === 'year') {
        this.setYearRange(null);
      } else {
        this.toggle(chip.field, chip.value, false);
      }
    },
  };
}
