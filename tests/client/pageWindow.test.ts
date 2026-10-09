import { describe, expect, it } from 'vitest';
import { pageWindow } from '../../src/svelte/lib/pageWindow';

/** S-14: the pager's window — never wider than seven cells, both ends always present. */
describe('pageWindow', () => {
  it('lists every page when there are seven or fewer', () => {
    expect(pageWindow(1, 1)).toEqual([1]);
    expect(pageWindow(4, 7)).toEqual([1, 2, 3, 4, 5, 6, 7]);
  });

  it('collapses both sides around a page in the middle', () => {
    expect(pageWindow(5, 12)).toEqual([1, 'gap', 4, 5, 6, 'gap', 12]);
  });

  it('opens straight onto the bookends near either end', () => {
    expect(pageWindow(1, 12)).toEqual([1, 2, 'gap', 12]);
    expect(pageWindow(3, 12)).toEqual([1, 2, 3, 4, 'gap', 12]);
    expect(pageWindow(12, 12)).toEqual([1, 'gap', 11, 12]);
  });

  it('stays at most seven cells on a deep set', () => {
    const cells = pageWindow(4999, 1655 * 4);
    expect(cells.length).toBeLessThanOrEqual(7);
    expect(cells[0]).toBe(1);
    expect(cells.at(-1)).toBe(6620);
  });
});
