import { describe, expect, it } from 'vitest';
import { ssrTabCounts } from '../../src/svelte/lib/federatedCounts';
import type { IwacFederatedTab } from '../../src/svelte/lib/types';

/**
 * /search/everything asked Typesense for its tab badges on every load,
 * although with no query each tab's server-rendered first page already holds
 * its total.
 */

const tab = (id: string, found?: number): IwacFederatedTab =>
  ({
    id,
    bootstrap: found === undefined ? {} : { initial_response: { found, hits: [], page: 1 } },
  }) as unknown as IwacFederatedTab;

describe('ssrTabCounts', () => {
  it('reads every tab badge from the snapshots when there is no query', () => {
    expect(ssrTabCounts([tab('content', 16715), tab('entities', 9000)], '  ')).toEqual({
      content: 16715,
      entities: 9000,
    });
  });

  it('defers to Typesense for a query, or when a tab has no snapshot', () => {
    expect(ssrTabCounts([tab('content', 1), tab('entities', 2)], 'tabaski')).toBeNull();
    expect(ssrTabCounts([tab('content', 1), tab('entities')], '')).toBeNull();
  });
});
