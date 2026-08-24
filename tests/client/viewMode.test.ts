import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { createViewMode } from '../../src/svelte/lib/viewMode.svelte';
import type { IwacSearchResponse, ViewMode } from '../../src/svelte/lib/types';

/**
 * View resolution and the image-heavy auto-suggest.
 *
 * Three separate defects met here (Phase-1 critique P1): the heuristic could
 * flip the default landing to a gallery, an explicit choice of List wrote
 * nothing to the URL so a shared link re-tripped the flip for the recipient,
 * and the heuristic ran on the response BEFORE the semantic withhold — so a
 * dead query's hidden vector-only set decided the presentation. The first is
 * by design; the other two are fixed, and these are the rules that keep them
 * fixed.
 *
 * Ordering note: the composable calls `localStorage.getItem` during
 * construction, so every test sets storage before `createViewMode`.
 */

const CONTENT: readonly ViewMode[] = ['list', 'gallery'];
const ENTITY: readonly ViewMode[] = ['list', 'map'];

/** A response of `n` hits, the first `imageBearing` of them image types. */
function response(n: number, imageBearing: number): IwacSearchResponse {
  return {
    found: n,
    page: 1,
    hits: Array.from({ length: n }, (_, i) => ({
      document: {
        id: String(i),
        title: `hit ${i}`,
        type_s: i < imageBearing ? 'photograph' : 'article',
      },
    })),
  } as unknown as IwacSearchResponse;
}

function make(opts: {
  modes?: readonly ViewMode[];
  syncUrl?: boolean;
  initialView?: ViewMode | null;
}) {
  return createViewMode({
    modes: opts.modes ?? CONTENT,
    syncUrl: opts.syncUrl ?? true,
    urlPrefix: '',
    initialView: opts.initialView ?? null,
  });
}

beforeEach(() => {
  window.localStorage.clear();
});
afterEach(() => {
  window.localStorage.clear();
});

describe('initial resolution', () => {
  it('starts on list, implicitly, with nothing in the URL or storage', () => {
    const v = make({});
    expect(v.mode).toBe('list');
    expect(v.explicit).toBe(false);
  });

  it('treats a view in the URL as explicit', () => {
    const v = make({ initialView: 'gallery' });
    expect(v.mode).toBe('gallery');
    expect(v.explicit).toBe(true);
  });

  it('treats an explicit ?view=list as a CHOICE, not as the default', () => {
    // The whole point of the null/'list' split: this must suppress the
    // auto-suggest, which "defaulted to list" never did.
    const v = make({ initialView: 'list' });
    expect(v.mode).toBe('list');
    expect(v.explicit).toBe(true);
    v.autoSuggest(response(10, 10));
    expect(v.mode).toBe('list');
  });

  it('honours a URL view this surface cannot offer as a choice for list', () => {
    // A `map` link opened against a content surface: the mode is unsupported,
    // but the reader still expressed a preference, so the heuristic stays out.
    const v = make({ modes: CONTENT, initialView: 'map' });
    expect(v.mode).toBe('list');
    expect(v.explicit).toBe(true);
  });

  it('falls back to the sticky localStorage preference', () => {
    window.localStorage.setItem('iwac-view-mode', 'gallery');
    const v = make({});
    expect(v.mode).toBe('gallery');
    expect(v.explicit).toBe(true);
  });

  it('rejects a stored mode this surface does not offer', () => {
    window.localStorage.setItem('iwac-view-mode', 'gallery');
    const v = make({ modes: ENTITY });
    expect(v.mode).toBe('list');
    expect(v.explicit).toBe(false);
  });

  it('ignores the URL on a surface that does not sync it', () => {
    const v = make({ syncUrl: false, initialView: 'gallery' });
    expect(v.mode).toBe('list');
    expect(v.explicit).toBe(false);
  });

  it('is always explicit-list on a single-mode surface', () => {
    const v = make({ modes: ['list'] });
    expect(v.supportsToggle).toBe(false);
    expect(v.explicit).toBe(true);
    v.autoSuggest(response(10, 10));
    expect(v.mode).toBe('list');
  });
});

describe('set', () => {
  it('records the choice and persists it', () => {
    const v = make({});
    v.set('gallery');
    expect(v.mode).toBe('gallery');
    expect(v.explicit).toBe(true);
    expect(window.localStorage.getItem('iwac-view-mode')).toBe('gallery');
  });

  it('makes a later auto-suggest a no-op — the reader wins', () => {
    const v = make({});
    v.set('list');
    v.autoSuggest(response(10, 10));
    expect(v.mode).toBe('list');
    expect(v.explicit).toBe(true);
  });

  it('refuses a mode this surface does not offer', () => {
    const v = make({ modes: CONTENT });
    v.set('map');
    expect(v.mode).toBe('list');
    expect(v.explicit).toBe(false);
  });
});

describe('autoSuggest', () => {
  it('flips to gallery when more than 60% of the shown hits are image-bearing', () => {
    const v = make({});
    v.autoSuggest(response(10, 7));
    expect(v.mode).toBe('gallery');
    // A suggestion is not a choice: it must never reach the URL.
    expect(v.explicit).toBe(false);
  });

  it('leaves the ledger alone at exactly the threshold', () => {
    const v = make({});
    v.autoSuggest(response(10, 6));
    expect(v.mode).toBe('list');
  });

  it('declines to judge a set of fewer than four hits — and stays armed', () => {
    // A one-hit first page must not burn the single shot; the decision is
    // deferred to the first set big enough to mean something.
    const v = make({});
    v.autoSuggest(response(3, 3));
    expect(v.mode).toBe('list');
    v.autoSuggest(response(10, 10));
    expect(v.mode).toBe('gallery');
  });

  it('runs at most once on a set it could judge', () => {
    const v = make({});
    v.autoSuggest(response(10, 1));
    expect(v.mode).toBe('list');
    v.autoSuggest(response(10, 10));
    expect(v.mode).toBe('list');
  });

  it('does nothing on a surface without a gallery', () => {
    const v = make({ modes: ENTITY });
    v.autoSuggest(response(10, 10));
    expect(v.mode).toBe('list');
  });
});

describe('applyPop', () => {
  it('takes a view from the popped URL as an explicit choice', () => {
    const v = make({});
    v.applyPop('gallery');
    expect(v.mode).toBe('gallery');
    expect(v.explicit).toBe(true);
  });

  it('reads an explicit list from history as a choice', () => {
    const v = make({ initialView: 'gallery' });
    v.applyPop('list');
    expect(v.mode).toBe('list');
    expect(v.explicit).toBe(true);
  });

  it('reverts to the implicit default when the popped URL carries no view', () => {
    const v = make({ initialView: 'gallery' });
    v.applyPop(null);
    expect(v.mode).toBe('list');
    expect(v.explicit).toBe(false);
  });
});
