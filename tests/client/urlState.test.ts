import { describe, expect, it } from 'vitest';
import { FALLBACK_SORT, readUrlState, writeUrlState } from '../../src/svelte/lib/urlState';
import type { SearchState } from '../../src/svelte/lib/types';

/**
 * The URL codec is the module's share-link contract AND the source of the
 * initial state on every syncing surface, so a silent change here is a
 * silent behaviour change for users.
 *
 * The default-sort cases exist because of a real bug: `readUrlState` used to
 * hardcode `_text_match:desc`, so a full-mode page block never saw its own
 * configured default — the admin's Default-sort setting did nothing, the SSR
 * snapshot was discarded on every mount, and entity blocks rendered a sort
 * control that disagreed with the applied order. See A1 in
 * docs/module-review-2026-07.md.
 */

const base = 'https://islam.zmo.de/search';

function state(overrides: Partial<SearchState> = {}): SearchState {
  return {
    q: '',
    page: 1,
    sort: FALLBACK_SORT,
    filters: {},
    yearRange: null,
    // null, not the resolved number: the pristine state is "use whatever this
    // surface is configured for", which a page block's admin owns.
    perPage: null,
    // null, not 'list': the pristine state is "the reader has not chosen a
    // view", which is a different thing from having chosen the list.
    view: null,
    ...overrides,
  };
}

describe('readUrlState', () => {
  it('returns pristine state for a bare URL', () => {
    expect(readUrlState(base)).toEqual(state());
  });

  it('decodes query, page, sort and the year range', () => {
    const s = readUrlState(`${base}?q=ramadan&page=3&sort=date:asc&date.from=1990&date.to=1999`);
    expect(s.q).toBe('ramadan');
    expect(s.page).toBe(3);
    expect(s.sort).toBe('date:asc');
    expect(s.yearRange).toEqual({ from: 1990, to: 1999 });
  });

  it('decodes repeated and comma-joined facet params into one list', () => {
    const s = readUrlState(`${base}?f.country_ss=Niger&f.country_ss=T%C3%B3go,B%C3%A9nin`);
    expect(s.filters).toEqual({ country_ss: ['Niger', 'Tógo', 'Bénin'] });
  });

  it('passes a retired sentiment facet name through rather than remapping it', () => {
    // v6 dropped the generation-1 sentiment fields and emptied the alias map:
    // no generation-2 model is an honest stand-in, so a link predating the
    // change must NOT come back filtered on a different model's judgement.
    // The unknown field survives decoding (the codec is a codec, not a
    // validator) and Typesense's filter is built from the live facet list.
    const s = readUrlState(`${base}?f.gemini_polarite_ss=Neutre&f.gpt_5_6_luna_subjectivite=4`);
    expect(s.filters).toEqual({
      gemini_polarite_ss: ['Neutre'],
      gpt_5_6_luna_subjectivite: ['4'],
    });
  });

  it('round-trips a current sentiment facet name unchanged', () => {
    const s = readUrlState(`${base}?f.gpt_5_6_luna_polarite_ss=Neutre`);
    expect(writeUrlState(s)).toBe('?f.gpt_5_6_luna_polarite_ss=Neutre');
  });

  it('does not resolve a facet name through Object.prototype', () => {
    // `constructor` passes the schema-shaped name check, so the alias lookup
    // must be an own-property test — otherwise the field becomes a function
    // and stringifies into a garbage filter_by clause.
    const s = readUrlState(`${base}?f.constructor=x&f.country_ss=Niger`);
    expect(Object.keys(s.filters).sort()).toEqual(['constructor', 'country_ss']);
    expect(s.filters.constructor).toEqual(['x']);
  });

  it('drops facet fields whose names are not schema-shaped', () => {
    // The token goes straight into a Typesense filter_by, so a smuggled
    // `is_public:=false`-style key must never survive decoding.
    const s = readUrlState(`${base}?f.is_public:=false=x&f.Country=x&f.country_ss=Niger`);
    expect(Object.keys(s.filters)).toEqual(['country_ss']);
  });

  it('clamps the page to a sanity range without capping deep links', () => {
    expect(readUrlState(`${base}?page=0`).page).toBe(1);
    expect(readUrlState(`${base}?page=-4`).page).toBe(1);
    expect(readUrlState(`${base}?page=nonsense`).page).toBe(1);
    expect(readUrlState(`${base}?page=9999`).page).toBe(9999);
    expect(readUrlState(`${base}?page=99999`).page).toBe(10000);
  });

  it('rejects out-of-range and malformed years', () => {
    expect(readUrlState(`${base}?date.from=999999`).yearRange).toBeNull();
    expect(readUrlState(`${base}?date.from=abc`).yearRange).toBeNull();
    expect(readUrlState(`${base}?date.from=1799`).yearRange).toBeNull();
    expect(readUrlState(`${base}?date.from=1800`).yearRange).toEqual({ from: 1800 });
  });

  it('distinguishes a chosen view from no choice at all', () => {
    // The distinction the image-heavy auto-suggest turns on: `list` means the
    // reader chose List, null means they said nothing. Collapsing the two is
    // what let a copied link re-flip a recipient into the gallery the sharer
    // had explicitly rejected.
    expect(readUrlState(`${base}?view=gallery`).view).toBe('gallery');
    expect(readUrlState(`${base}?view=map`).view).toBe('map');
    expect(readUrlState(`${base}?view=list`).view).toBe('list');
    expect(readUrlState(base).view).toBeNull();
  });

  it('degrades an unrecognised view to list rather than to "no choice"', () => {
    // A mangled ?view= is still someone asking for a presentation; treating it
    // as absent would re-arm the auto-suggest on top of the mangling.
    expect(readUrlState(`${base}?view=carousel`).view).toBe('list');
    expect(readUrlState(`${base}?view=`).view).toBe('list');
  });

  describe('surface default sort', () => {
    it('falls back to the surface default when ?sort is absent', () => {
      expect(readUrlState(base, '', 'frequency:desc').sort).toBe('frequency:desc');
      expect(readUrlState(base, '', 'date:desc').sort).toBe('date:desc');
    });

    it('lets an explicit ?sort override the surface default', () => {
      expect(readUrlState(`${base}?sort=title:asc`, '', 'frequency:desc').sort).toBe('title:asc');
    });

    it('falls back for an empty ?sort= rather than yielding an empty sort', () => {
      expect(readUrlState(`${base}?sort=`, '', 'date:desc').sort).toBe('date:desc');
    });

    it('uses the global fallback when no surface default is given', () => {
      expect(readUrlState(base).sort).toBe(FALLBACK_SORT);
    });

    /**
     * `sort` was the one URL param that reached Typesense unvalidated, while
     * `page` was clamped and `view` allowlisted. An unknown sort field is not
     * a bad ordering but a 422, which the client surfaces as the full-page
     * "Search unavailable" error — so a mangled share link took the whole
     * surface down (Phase-1 critique P2).
     */
    it('falls back to the surface default for a sort value no surface offers', () => {
      expect(readUrlState(`${base}?sort=junk`, '', 'date:desc').sort).toBe('date:desc');
      expect(readUrlState(`${base}?sort=is_public:desc`, '', 'date:desc').sort).toBe('date:desc');
      expect(readUrlState(`${base}?sort=date:sideways`, '', 'date:desc').sort).toBe('date:desc');
      expect(readUrlState(`${base}?sort=junk`).sort).toBe(FALLBACK_SORT);
    });

    it('accepts every value the surfaces actually offer, in both vocabularies', () => {
      for (const value of [
        '_text_match:desc',
        'date:desc',
        'date:asc',
        'creator_sort:asc',
        'frequency:desc',
        'frequency:asc',
        'title:asc',
      ]) {
        expect(readUrlState(`${base}?sort=${value}`, '', 'date:desc').sort).toBe(value);
      }
    });
  });

  describe('page size', () => {
    it('decodes an offered size', () => {
      expect(readUrlState(`${base}?per=50`).perPage).toBe(50);
    });

    it('falls back to the surface default for a size nobody offers', () => {
      // Straight into a Typesense per_page, where 0 and 9999 are 422s rather
      // than odd page sizes — so an unoffered value must not reach the wire.
      for (const raw of ['0', '9999', '15', '-10', 'banana', '']) {
        expect(readUrlState(`${base}?per=${raw}`).perPage).toBeNull();
      }
    });

    it('is absent from a pristine URL', () => {
      expect(readUrlState(`${base}`).perPage).toBeNull();
      expect(writeUrlState(state(), '', '', FALLBACK_SORT)).toBe('');
    });

    it('is namespaced under a block prefix', () => {
      expect(writeUrlState(state({ perPage: 20 }), 'b42.', '', FALLBACK_SORT)).toBe('?b42.per=20');
      expect(readUrlState(`${base}?per=50&b42.per=20`, 'b42.').perPage).toBe(20);
    });
  });

  describe('block prefixes', () => {
    it('reads only its own prefix', () => {
      const url = `${base}?b42.q=ramadan&b42.page=2&b7.q=autre&q=global`;
      expect(readUrlState(url, 'b42.')).toMatchObject({ q: 'ramadan', page: 2 });
      expect(readUrlState(url, 'b7.').q).toBe('autre');
      expect(readUrlState(url, '').q).toBe('global');
    });

    it('reads only its own prefixed facets', () => {
      const url = `${base}?b42.f.country_ss=Niger&f.country_ss=T%C3%B3go`;
      expect(readUrlState(url, 'b42.').filters).toEqual({ country_ss: ['Niger'] });
      expect(readUrlState(url, '').filters).toEqual({ country_ss: ['Tógo'] });
    });
  });
});

describe('writeUrlState', () => {
  it('omits every default so a pristine surface has a clean URL', () => {
    expect(writeUrlState(state())).toBe('');
  });

  it('omits the sort when it equals the SURFACE default, not a global one', () => {
    // An entity block defaults to frequency:desc — writing it would litter
    // every link, and (worse) an explicit _text_match:desc pick would be the
    // one silently dropped.
    expect(writeUrlState(state({ sort: 'frequency:desc' }), '', '', 'frequency:desc')).toBe('');
    expect(writeUrlState(state({ sort: 'date:desc' }), '', '', 'frequency:desc')).toBe(
      '?sort=date%3Adesc',
    );
  });

  it('preserves params it does not own, including sibling blocks', () => {
    const qs = writeUrlState(state({ q: 'ramadan' }), 'b42.', 'utm_source=x&b7.q=autre');
    const params = new URLSearchParams(qs);
    expect(params.get('utm_source')).toBe('x');
    expect(params.get('b7.q')).toBe('autre');
    expect(params.get('b42.q')).toBe('ramadan');
  });

  it('clears its own stale keys before writing', () => {
    const qs = writeUrlState(state(), '', 'q=old&page=4&f.country_ss=Niger&view=map');
    expect(qs).toBe('');
  });

  it('does not clear a sibling block whose prefix shares a stem', () => {
    // `page` must not delete `b42.page` — exact-match scalars, prefixed facets.
    const qs = writeUrlState(state(), '', 'b42.page=3');
    expect(new URLSearchParams(qs).get('b42.page')).toBe('3');
  });
});

describe('round trip', () => {
  const cases: Array<[string, SearchState]> = [
    ['query only', state({ q: 'ramadan' })],
    ['paged', state({ q: 'a', page: 7 })],
    ['sorted', state({ sort: 'creator_sort:asc' })],
    ['multi-value facets', state({ filters: { country_ss: ['Bénin', "Côte d'Ivoire"] } })],
    ['year range', state({ yearRange: { from: 1990, to: 1999 } })],
    ['open-ended year range', state({ yearRange: { from: 1990 } })],
    ['explicit view', state({ view: 'gallery' })],
    // The case the old encoder dropped on the floor: choosing List wrote
    // nothing, so the link could not carry the choice.
    ['explicit list view', state({ view: 'list' })],
    ['explicit page size', state({ perPage: 50 })],
    [
      'everything at once',
      state({
        q: 'islam & état',
        page: 2,
        sort: 'date:asc',
        filters: { country_ss: ['Niger'], topics_ss: ['Éducation'] },
        yearRange: { from: 1980, to: 2000 },
        perPage: 20,
        view: 'map',
      }),
    ],
  ];

  it.each(cases)('%s survives write → read', (_label, original) => {
    const qs = writeUrlState(original, '', '', FALLBACK_SORT);
    expect(readUrlState(`${base}${qs}`, '', FALLBACK_SORT)).toEqual(original);
  });

  // Same states through a page block's namespaced prefix and a DIFFERENT
  // surface default — the combination that was broken before A1.
  it.each(cases)('%s survives write → read under a block prefix', (_label, original) => {
    const qs = writeUrlState(original, 'b42.', '', 'date:desc');
    expect(readUrlState(`${base}${qs}`, 'b42.', 'date:desc')).toEqual(original);
  });
});
