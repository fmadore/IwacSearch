import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushSync, mount, unmount } from 'svelte';
import FacetGroup from '../../src/svelte/components/FacetGroup.svelte';
import type { IwacFacetCount } from '../../src/svelte/lib/types';
import { defined } from './defined';
import { facetProps } from './facetProps.svelte';

/**
 * A facet's value search, when the facet holds more values than were loaded:
 * the typed text is sent to the server (App's onFacetSearch), whose counts
 * replace the list.
 *
 * Two regressions pinned here. The search depended on the typed text only, so
 * a new query or another filter left the previous search's counts on screen.
 * And its live regions were mounted inside {#if}, together with their text,
 * which screen readers often do not announce.
 */

let app: ReturnType<typeof mount> | null = null;

afterEach(() => {
  if (app) void unmount(app);
  app = null;
  document.body.innerHTML = '';
  vi.useRealTimers();
});

const loaded = (n: number): IwacFacetCount[] =>
  Array.from({ length: n }, (_, i) => ({ value: `Valeur ${i}`, count: 100 - i }));

function setup() {
  vi.useFakeTimers();
  const onFacetSearch = vi.fn(async (_field: string, text: string) => [
    { value: `${text} (recherche ${onFacetSearch.mock.calls.length})`, count: 7 },
  ]);
  const props = facetProps({
    field: 'organisations_ss',
    counts: loaded(10),
    totalValues: 500,
    onFacetSearch,
  });
  app = mount(FacetGroup, { target: document.body, props });
  flushSync();
  const box = defined(
    document.querySelector<HTMLInputElement>('input[type="search"], .iwac-facet__search input'),
    'the value search box',
  );
  const live = (): string =>
    [...document.querySelectorAll('[aria-live]')].map((el) => el.textContent?.trim()).join('|');
  return { props, onFacetSearch, box, live };
}

async function typeAndSettle(box: HTMLInputElement, text: string): Promise<void> {
  box.value = text;
  box.dispatchEvent(new Event('input', { bubbles: true }));
  flushSync();
  await vi.advanceTimersByTimeAsync(250);
  flushSync();
}

describe('FacetGroup value search', () => {
  it('searches again when the search behind the counts changes', async () => {
    const { props, onFacetSearch, box } = setup();
    await typeAndSettle(box, 'Conseil');
    expect(onFacetSearch).toHaveBeenCalledTimes(1);
    expect(document.body.textContent).toContain('Conseil (recherche 1)');

    // A new query or another filter: the parent passes new counts.
    props.counts = loaded(10).map((c) => ({ ...c, count: c.count - 50 }));
    flushSync();
    await vi.advanceTimersByTimeAsync(250);
    flushSync();
    expect(onFacetSearch).toHaveBeenCalledTimes(2);
    expect(onFacetSearch).toHaveBeenLastCalledWith('organisations_ss', 'Conseil');
    expect(document.body.textContent).toContain('Conseil (recherche 2)');
  });

  it('keeps one live region mounted and changes its text', async () => {
    const { box, live } = setup();
    const regions = (): Element[] => [...document.querySelectorAll('[aria-live]')];
    const before = regions();
    expect(before).toHaveLength(1);
    expect(live()).toBe('');

    box.value = 'Conseil';
    box.dispatchEvent(new Event('input', { bubbles: true }));
    flushSync();
    expect(live()).toBe('Recherche…');
    await vi.advanceTimersByTimeAsync(250);
    flushSync();
    expect(regions()).toEqual(before); // the same node, not a new one
    expect(live()).toMatch(/1/);
  });
});
