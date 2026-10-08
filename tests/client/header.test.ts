import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { SuggestResult } from '../../src/svelte/lib/types';

/**
 * header.ts — the masthead typeahead, on every public page of the site, and
 * the one piece of the client that had no tests at all.
 *
 * What is pinned: the ARIA combobox contract (the input keeps focus, rows are
 * options that are never Tab stops, and aria-activedescendant names the
 * highlighted row — the attribute whose absence made arrowing silent to a
 * screen reader, S-04), the listbox owning only options, the debounce and its
 * stale-response guard, and that nothing navigates without an explicit act.
 */

const runSuggest = vi.fn<(...args: unknown[]) => Promise<SuggestResult>>();
vi.mock('../../src/svelte/lib/suggestQuery', () => ({
  runSuggest: (...args: unknown[]) => runSuggest(...args),
}));

const { HeaderSearch, init } = await import('../../src/svelte/header');

const RESULT: SuggestResult = {
  articles: [
    {
      document: {
        id: '7',
        title: 'Le Ramadan à Ouagadougou',
        omeka_url: 'https://islam.zmo.de/s/afrique_ouest/item/7',
      },
    },
  ],
  entities: [{ field: 'places_ss', value: 'Ouagadougou', count: 12 }],
};

function mount(): {
  form: HTMLFormElement;
  input: HTMLInputElement;
  navigate: ReturnType<typeof vi.fn>;
} {
  document.body.innerHTML = `
    <div class="main-header__search-form">
      <form data-iwac-header-search method="get" action="/s/afrique_ouest/recherche">
        <input name="q" type="search">
        <button type="submit">Search</button>
      </form>
    </div>`;
  const form = document.querySelector<HTMLFormElement>('form')!;
  const input = form.querySelector<HTMLInputElement>('input')!;
  const navigate = vi.fn();
  new HeaderSearch(
    form,
    input,
    { endpoints: { token: '/t', search: '/s' }, locale: 'fr' },
    navigate,
  );
  return { form, input, navigate };
}

function type(input: HTMLInputElement, text: string): void {
  input.value = text;
  input.dispatchEvent(new Event('input'));
}

function key(input: HTMLInputElement, k: string): KeyboardEvent {
  const e = new KeyboardEvent('keydown', { key: k, cancelable: true });
  input.dispatchEvent(e);
  return e;
}

const listbox = () => document.querySelector<HTMLElement>('[role="listbox"]')!;
const options = () => [...listbox().querySelectorAll<HTMLElement>('[role="option"]')];

beforeEach(() => {
  vi.useFakeTimers();
  runSuggest.mockReset();
  runSuggest.mockResolvedValue(RESULT);
});

afterEach(() => {
  vi.useRealTimers();
  document.body.innerHTML = '';
});

/** Type, let the debounce fire and the (mocked) request settle. */
async function typeAndSettle(input: HTMLInputElement, text: string): Promise<void> {
  type(input, text);
  await vi.advanceTimersByTimeAsync(200);
}

describe('combobox wiring', () => {
  it('marks the input as a combobox controlling the listbox', () => {
    const { input } = mount();
    expect(input.getAttribute('role')).toBe('combobox');
    expect(input.getAttribute('aria-autocomplete')).toBe('list');
    expect(input.getAttribute('aria-controls')).toBe(listbox().id);
    expect(input.getAttribute('aria-expanded')).toBe('false');
  });

  it('names the highlighted row with aria-activedescendant, and follows the arrows', async () => {
    const { input } = mount();
    await typeAndSettle(input, 'Ouaga');
    expect(input.getAttribute('aria-expanded')).toBe('true');

    const rows = options();
    expect(rows).toHaveLength(3); // "Search for…", one article, one entity
    expect(new Set(rows.map((r) => r.id)).size).toBe(3);
    expect(input.getAttribute('aria-activedescendant')).toBe(rows[0].id);
    expect(rows[0].getAttribute('aria-selected')).toBe('true');

    key(input, 'ArrowDown');
    expect(input.getAttribute('aria-activedescendant')).toBe(rows[1].id);
    expect(rows[1].getAttribute('aria-selected')).toBe('true');
    expect(rows[0].getAttribute('aria-selected')).toBe('false');

    key(input, 'ArrowUp');
    key(input, 'ArrowUp'); // wraps to the last row
    expect(input.getAttribute('aria-activedescendant')).toBe(rows[2].id);
  });

  it('clears aria-activedescendant when the panel closes', async () => {
    const { input } = mount();
    await typeAndSettle(input, 'Ouaga');
    expect(input.hasAttribute('aria-activedescendant')).toBe(true);
    key(input, 'Escape');
    expect(input.getAttribute('aria-expanded')).toBe('false');
    expect(input.hasAttribute('aria-activedescendant')).toBe(false);
  });

  it('keeps every row out of the Tab order', async () => {
    const { input } = mount();
    await typeAndSettle(input, 'Ouaga');
    for (const row of options()) expect(row.tabIndex).toBe(-1);
  });

  it('lets the listbox own options only; "No matches" lives outside it', async () => {
    runSuggest.mockResolvedValue({ articles: [], entities: [] });
    const { input } = mount();
    await typeAndSettle(input, 'zzzz');
    for (const child of listbox().children) expect(child.getAttribute('role')).toBe('option');
    const status = document.querySelector('[role="status"]')!;
    expect(listbox().contains(status)).toBe(false);
    expect(status.textContent).toBe('Aucune correspondance.');
  });
});

describe('fetching', () => {
  it('waits for the debounce, and ignores a response a newer keystroke superseded', async () => {
    let resolveFirst!: (r: SuggestResult) => void;
    runSuggest.mockImplementationOnce(
      () =>
        new Promise((resolve) => {
          resolveFirst = resolve;
        }),
    );
    const { input } = mount();
    type(input, 'Ou');
    expect(runSuggest).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(200);
    expect(runSuggest).toHaveBeenCalledTimes(1);

    await typeAndSettle(input, 'Ouaga'); // second request resolves with RESULT
    resolveFirst({ articles: [], entities: [] }); // the stale one lands last
    await vi.advanceTimersByTimeAsync(0);
    expect(options()).toHaveLength(3);
  });

  it('stays closed below two characters', async () => {
    const { input } = mount();
    await typeAndSettle(input, 'O');
    expect(input.getAttribute('aria-expanded')).toBe('false');
    expect(runSuggest).not.toHaveBeenCalled();
  });
});

describe('navigation', () => {
  it('never navigates on a pause — only on an explicit act', async () => {
    const { input, navigate } = mount();
    await typeAndSettle(input, 'Ouaga');
    await vi.advanceTimersByTimeAsync(5000);
    expect(navigate).not.toHaveBeenCalled();
  });

  it('runs the typed search from the highlighted "Search for" row on Enter', async () => {
    const { input, navigate } = mount();
    await typeAndSettle(input, 'Ouaga');
    const e = key(input, 'Enter');
    expect(e.defaultPrevented).toBe(true);
    expect(navigate).toHaveBeenCalledWith(
      expect.stringContaining('/s/afrique_ouest/recherche?q=Ouaga'),
    );
  });

  it('follows an article row to its item page', async () => {
    const { input, navigate } = mount();
    await typeAndSettle(input, 'Ouaga');
    key(input, 'ArrowDown');
    key(input, 'Enter');
    expect(navigate).toHaveBeenCalledWith('https://islam.zmo.de/s/afrique_ouest/item/7');
  });
});

describe('init', () => {
  it('enhances each header form once', () => {
    document.body.innerHTML = `
      <form data-iwac-header-search action="/search/everything"><input name="q"></form>`;
    init();
    init();
    expect(document.querySelectorAll('[role="listbox"]')).toHaveLength(1);
    expect(document.querySelector('form')!.dataset.iwacHeaderEnhanced).toBe('1');
  });
});
