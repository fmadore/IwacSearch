<script lang="ts">
  /**
   * The real facet panel and result card under a surface's heading context,
   * for tests/client/headings.test.ts — App itself needs the network.
   */
  import { provideI18n } from '../../src/svelte/lib/i18n';
  import { provideHeadingLevel, type HeadingLevel } from '../../src/svelte/lib/headings';
  import FacetPanel from '../../src/svelte/components/FacetPanel.svelte';
  import ResultItem from '../../src/svelte/components/ResultItem.svelte';
  import type { IwacFacet, IwacHit } from '../../src/svelte/lib/types';

  interface Props {
    /** Absent: no surface provided one (a component mounted on its own). */
    level?: HeadingLevel;
    facets: IwacFacet[];
    hit: IwacHit;
  }

  const { level, facets, hit }: Props = $props();

  provideI18n('en', 'content');
  // svelte-ignore state_referenced_locally
  if (level) provideHeadingLevel(level);

  const noop = (): void => {};
</script>

<FacetPanel
  {facets}
  selected={{}}
  yearRange={null}
  onToggle={noop}
  onClearAll={noop}
  onClearField={noop}
  onYearRangeChange={noop}
/>
<ResultItem {hit} activeFilters={{}} onFacetToggle={noop} />
