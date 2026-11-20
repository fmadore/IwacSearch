import { describe, expect, it } from 'vitest';
import { createTabSeed } from '../../src/svelte/lib/tabSeed.svelte';

/**
 * The Everything tab's chip hand-off (FederatedApp.svelte). A chip opens the
 * per-collection tab filtered on its value, for that one mount. The bug: the
 * hand-off outlived it, so after the reader removed the filter, left the tab
 * and came back, the remount applied it again. Only a new query cleared it.
 *
 * The component calls exactly these three methods: handOff() from the chip,
 * end() from a tab the reader chooses, Back/Forward and a committed query, and
 * filtersFor() for the mounted tab's bootstrap.
 */

type Tab = 'all' | 'content' | 'entities';

describe('createTabSeed', () => {
  it('hands the chip filter to the target tab only', () => {
    const seed = createTabSeed<Tab>();
    seed.handOff('content', 'country_ss', 'Niger');
    expect(seed.filtersFor('content')).toEqual({ country_ss: ['Niger'] });
    expect(seed.filtersFor('entities')).toBeUndefined();
    expect(seed.filtersFor('all')).toBeUndefined();
  });

  it('does not re-apply the filter after a tab switch and back', () => {
    const seed = createTabSeed<Tab>();
    // Chip on Everything → Content mounts with the filter.
    seed.handOff('content', 'country_ss', 'Niger');
    expect(seed.filtersFor('content')).toEqual({ country_ss: ['Niger'] });
    // The reader removes it inside Content (App's own state), then chooses
    // Everything (selectTab → end) and Content again: a fresh mount.
    seed.end();
    expect(seed.filtersFor('all')).toBeUndefined();
    expect(seed.filtersFor('content')).toBeUndefined();
  });

  it('a later chip hands off again, to its own tab', () => {
    const seed = createTabSeed<Tab>();
    seed.handOff('content', 'country_ss', 'Niger');
    seed.end();
    seed.handOff('entities', 'entity_type_s', 'Lieux');
    expect(seed.filtersFor('entities')).toEqual({ entity_type_s: ['Lieux'] });
    expect(seed.filtersFor('content')).toBeUndefined();
  });
});
