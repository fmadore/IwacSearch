import type { IwacFacetCount } from '../../src/svelte/lib/types';

/**
 * Reactive props for mounting a FacetGroup in a test: `mount()` keeps a
 * reference to the object it is given, so a `$state` one lets the test change
 * a prop after mounting, as the parent does when a new search arrives.
 */
export function facetProps(initial: {
  field: string;
  counts: IwacFacetCount[];
  totalValues?: number;
  onFacetSearch: (field: string, text: string) => Promise<IwacFacetCount[]>;
}) {
  const props = $state({
    selected: [] as string[],
    searchThreshold: 0,
    onToggle: (): void => {},
    ...initial,
  });
  return props;
}
