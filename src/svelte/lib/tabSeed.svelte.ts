import type { ActiveFilters } from './types';

/**
 * The filter a chip on the Everything tab hands to a per-collection tab.
 *
 * Union responses carry no facets, so a chip clicked on a merged result card
 * switches to the tab whose facet panel can show the filter, and that tab
 * mounts with it as `initial_filters`. The hand-off is for THAT mount only.
 * It used to outlive it: only a new query cleared it, so a reader who removed
 * the filter, went back to Everything and returned to the tab found it
 * applied again, every time the tab remounted. Any move the reader makes
 * themself (choosing a tab, Back/Forward, a new query) now ends it.
 */
export interface TabSeed<Tab extends string> {
  /** A union chip: open `tab` filtered on `field` = `value`. */
  handOff(tab: Tab, field: string, value: string): void;
  /** The reader moved on: no later mount gets the hand-off again. */
  end(): void;
  /** The filters a mount of `tab` starts with, if the hand-off is for it. */
  filtersFor(tab: Tab): ActiveFilters | undefined;
}

export function createTabSeed<Tab extends string>(): TabSeed<Tab> {
  let seed = $state<{ tab: Tab; filters: ActiveFilters } | null>(null);
  return {
    handOff(tab, field, value) {
      seed = { tab, filters: { [field]: [value] } };
    },
    end() {
      seed = null;
    },
    filtersFor(tab) {
      return seed?.tab === tab ? seed.filters : undefined;
    },
  };
}
