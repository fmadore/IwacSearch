import { afterEach, describe, expect, it } from 'vitest';
import { flushSync, mount, unmount } from 'svelte';
import { parseHeadingLevel } from '../../src/svelte/lib/headings';
import type { HeadingLevel } from '../../src/svelte/lib/headings';
import type { IwacFacet, IwacHit } from '../../src/svelte/lib/types';
import HeadingsHarness from './HeadingsHarness.svelte';
import { defined } from './defined';

/**
 * Heading levels come from the page around a surface, not from a fixed h2/h3
 * (lib/headings.ts). Under a titled page block's own h2, "Filters" used to be
 * a second h2 BESIDE the title, and every result title sat beside the filters
 * rather than under the block.
 *
 * The real FacetPanel and ResultItem are mounted (HeadingsHarness.svelte):
 * the levels are rendered markup, which a test of the helper alone would
 * not see. The browser suite checks the same through the built bundle.
 */

const FACETS: IwacFacet[] = [
  { field_name: 'country_ss', counts: [{ value: 'Niger', count: 3 }] },
  { field_name: 'gpt_5_6_luna_polarite_ss', counts: [{ value: 'Neutre', count: 2 }] },
];

const HIT: IwacHit = {
  document: { id: '7', title: 'Le Ramadan à Niamey', type_s: 'article', country_ss: ['Niger'] },
};

let app: ReturnType<typeof mount> | null = null;

afterEach(() => {
  if (app) void unmount(app);
  app = null;
  document.body.innerHTML = '';
});

/** Mount the harness, open the sentiment group, and list `[level, text]` of every heading. */
function headingsAt(level?: HeadingLevel): Array<[number, string]> {
  app = mount(HeadingsHarness, {
    target: document.body,
    props: { level, facets: FACETS, hit: HIT },
  });
  flushSync();
  defined(
    document.querySelector<HTMLButtonElement>('.iwac-facets__group-heading'),
    'the sentiment toggle',
  ).click();
  flushSync();
  return [
    ...document.querySelectorAll<HTMLElement>('h1, h2, h3, h4, h5, h6, [role="heading"]'),
  ].map((el) => [
    el.getAttribute('aria-level') !== null
      ? Number(el.getAttribute('aria-level'))
      : Number(el.tagName.slice(1)),
    (el.textContent ?? '').replace(/\s+/g, ' ').trim(),
  ]);
}

describe('parseHeadingLevel', () => {
  it('reads 3, and treats anything else as 2', () => {
    expect(parseHeadingLevel('3')).toBe(3);
    expect(parseHeadingLevel('2')).toBe(2);
    expect(parseHeadingLevel(undefined)).toBe(2);
    expect(parseHeadingLevel('4')).toBe(2);
    expect(parseHeadingLevel('')).toBe(2);
  });
});

describe('heading levels inside a surface', () => {
  it('puts Filters at h2 and the groups and card title at h3 by default', () => {
    expect(headingsAt()).toEqual([
      [2, 'Filters'],
      [3, expect.stringContaining('Country')],
      [3, expect.stringContaining('Sentiment')],
      [4, expect.stringContaining('Polarity')],
      [3, 'Le Ramadan à Niamey'],
    ]);
    expect(document.querySelector('h3.iwac-card__title')).not.toBeNull();
  });

  it('steps every heading down one under a titled block', () => {
    expect(headingsAt(3).map(([level]) => level)).toEqual([3, 4, 4, 5, 4]);
    // Real heading elements where they were real before; the facet groups
    // stay role=heading.
    expect(document.querySelector('h3.iwac-facets__heading')?.textContent).toBe('Filters');
    expect(document.querySelector('h4.iwac-card__title')?.textContent?.trim()).toBe(
      'Le Ramadan à Niamey',
    );
    expect(document.querySelector('h2')).toBeNull();
  });

  it('keeps the page-level default when the surface says 2', () => {
    expect(headingsAt(2).map(([level]) => level)).toEqual([2, 3, 3, 4, 3]);
  });
});
