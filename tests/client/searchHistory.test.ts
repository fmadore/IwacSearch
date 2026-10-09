import { beforeEach, describe, expect, it } from 'vitest';
import { readHistory, recordSearch } from '../../src/svelte/lib/searchHistory';

/**
 * S-05: search-as-you-type records every fruitful commit, so without this
 * one typed query filled the recent-searches list with its own fragments.
 */
describe('recordSearch', () => {
  beforeEach(() => window.localStorage.clear());

  it('replaces the newest entry with the query that extends it', () => {
    recordSearch('tabaski');
    recordSearch('tabaski au');
    recordSearch('tabaski au Burkina');
    expect(readHistory()).toEqual(['tabaski au Burkina']);
  });

  it('replaces it when backspacing shortens it, too', () => {
    recordSearch('tabaski au Burkina');
    recordSearch('tabaski au');
    expect(readHistory()).toEqual(['tabaski au']);
  });

  it('keeps an older search that merely shares a prefix — only the newest is a fragment', () => {
    recordSearch('islam');
    recordSearch('coran');
    recordSearch('islam au Niger');
    expect(readHistory()).toEqual(['islam au Niger', 'coran', 'islam']);
  });

  it('still moves a repeated search to the front and ignores short ones', () => {
    recordSearch('coran');
    recordSearch('tabaski');
    recordSearch('Coran');
    recordSearch('ab');
    expect(readHistory()).toEqual(['Coran', 'tabaski']);
  });
});
