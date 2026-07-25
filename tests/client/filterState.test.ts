import { describe, expect, it, vi } from 'vitest';
import { createFilterState } from '../../src/svelte/lib/filterState.svelte';

/**
 * The filter-selection rules extracted from App.svelte. Small, but each one
 * exists for a concrete reason and each has a way of silently regressing:
 *
 *   - dropping the KEY (not just the value) on the last removal keeps
 *     `?f.country_ss=` husks out of shared links;
 *   - every mutation resets to page 1, or a narrowed result set leaves the
 *     user on an out-of-range page;
 *   - assignments replace rather than mutate, or Svelte's `$state` proxy
 *     never sees the change and dependent effects don't re-run.
 */

function make(filters = {}, yearRange = null) {
  const onChange = vi.fn();
  return { state: createFilterState(filters, yearRange, onChange), onChange };
}

describe('createFilterState', () => {
  it('adds a value and reports it', () => {
    const { state, onChange } = make();
    state.toggle('country_ss', 'Niger', true);
    expect(state.filters).toEqual({ country_ss: ['Niger'] });
    expect(onChange).toHaveBeenCalledTimes(1);
  });

  it('accumulates values within one field', () => {
    const { state } = make();
    state.toggle('country_ss', 'Niger', true);
    state.toggle('country_ss', 'Bénin', true);
    expect(state.filters.country_ss).toEqual(['Niger', 'Bénin']);
  });

  it('never adds the same value twice', () => {
    const { state } = make();
    state.toggle('country_ss', 'Niger', true);
    state.toggle('country_ss', 'Niger', true);
    expect(state.filters.country_ss).toEqual(['Niger']);
  });

  it('removes one value but keeps the field while others remain', () => {
    const { state } = make({ country_ss: ['Niger', 'Bénin'] });
    state.toggle('country_ss', 'Niger', false);
    expect(state.filters).toEqual({ country_ss: ['Bénin'] });
  });

  it('DELETES the field key when its last value is removed', () => {
    const { state } = make({ country_ss: ['Niger'] });
    state.toggle('country_ss', 'Niger', false);
    expect(state.filters).toEqual({});
    expect('country_ss' in state.filters).toBe(false);
  });

  it('replaces the object rather than mutating it', () => {
    const { state } = make();
    const before = state.filters;
    state.toggle('country_ss', 'Niger', true);
    expect(state.filters).not.toBe(before);
  });

  it('clears one field without touching the others', () => {
    const { state } = make({ country_ss: ['Niger'], topics_ss: ['Islam'] });
    state.clearField('country_ss');
    expect(state.filters).toEqual({ topics_ss: ['Islam'] });
  });

  it('clears everything, year range included', () => {
    const { state } = make({ country_ss: ['Niger'] }, { from: 1990 });
    state.clearAll();
    expect(state.filters).toEqual({});
    expect(state.yearRange).toBeNull();
  });

  it('notifies on every mutation, so the caller can reset the page', () => {
    const { state, onChange } = make({ country_ss: ['Niger'] });
    state.toggle('topics_ss', 'Islam', true);
    state.clearField('topics_ss');
    state.setYearRange({ from: 1990 });
    state.clearAll();
    expect(onChange).toHaveBeenCalledTimes(4);
  });

  describe('activeCount', () => {
    it('counts every selected value plus one for the year range', () => {
      const { state } = make({ country_ss: ['Niger', 'Bénin'], topics_ss: ['Islam'] });
      expect(state.activeCount).toBe(3);
      state.setYearRange({ from: 1990, to: 1999 });
      expect(state.activeCount).toBe(4);
    });

    it('is zero when nothing is selected', () => {
      expect(make().state.activeCount).toBe(0);
    });
  });

  describe('removeChip', () => {
    it('clears the range for the year chip', () => {
      const { state } = make({}, { from: 1990 });
      state.removeChip({
        kind: 'year',
        field: 'pub_year',
        value: '1990-1999',
        displayValue: '1990–1999',
        label: 'Year',
      });
      expect(state.yearRange).toBeNull();
    });

    it('toggles the value off for a facet chip', () => {
      const { state } = make({ country_ss: ['Niger'] });
      state.removeChip({
        kind: 'facet',
        field: 'country_ss',
        value: 'Niger',
        displayValue: 'Niger',
        label: 'Country',
      });
      expect(state.filters).toEqual({});
    });
  });

  it('hydrate() replaces wholesale without notifying (back-button path)', () => {
    // The popstate handler is restoring state that the URL already reflects;
    // firing onChange would reset the page the user just navigated back to.
    const { state, onChange } = make({ country_ss: ['Niger'] }, { from: 1990 });
    state.hydrate({ topics_ss: ['Islam'] }, null);
    expect(state.filters).toEqual({ topics_ss: ['Islam'] });
    expect(state.yearRange).toBeNull();
    expect(onChange).not.toHaveBeenCalled();
  });
});
