import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type SuggestDropdown from '../../src/svelte/components/SuggestDropdown.svelte';
import { createTypeahead, dismissOnOutsidePointer } from '../../src/svelte/lib/typeahead.svelte';

/**
 * The suggestion panel's dismissal contract.
 *
 * This exists because the contract has now been wrong in both directions, and
 * neither failure was visible in any test:
 *
 *   - before 3.16.0 the panel was RE-ARMED from the debounced handler, so it
 *     re-opened over the results it had just helped find and only Escape got
 *     it back off;
 *   - 3.16.0 replaced that with a close on the same event, so pausing to read
 *     the suggestions — the one thing the panel is for — dismissed them about
 *     a second after the reader stopped typing (the owner's report against
 *     the released build).
 *
 * Both bugs are the same mistake: inferring intent from the search commit. So
 * the central assertion here is not "it stays open after a commit" (the
 * composable no longer has a commit-shaped input at all) but the stronger
 * enumeration in `only the named dismissals close an open panel` — every
 * public member is called against an open panel and the set that closes it is
 * pinned. Re-introducing a `dismissForResults()` by any name fails that test.
 */

/** A SuggestDropdown stand-in: the composable only ever calls handleKeydown. */
function fakeDropdown(): { ref: SuggestDropdown; keys: string[] } {
  const keys: string[] = [];
  const ref = {
    handleKeydown(e: KeyboardEvent): boolean {
      keys.push(e.key);
      return true;
    },
  } as unknown as SuggestDropdown;
  return { ref, keys };
}

function make() {
  const commits: string[] = [];
  const entities: Array<[string, string]> = [];
  const suggest = createTypeahead('7', {
    onCommitQuery: (text) => commits.push(text),
    onPickEntity: (field, value) => entities.push([field, value]),
  });
  const { ref, keys } = fakeDropdown();
  suggest.ref = ref;
  return { suggest, commits, entities, keys };
}

/** Put the panel in the state a reader is in mid-search: focused, text typed. */
function opened() {
  const h = make();
  h.suggest.handleFocus();
  h.suggest.handleInput('Cham');
  expect(h.suggest.open).toBe(true);
  return h;
}

const key = (k: string): KeyboardEvent => new KeyboardEvent('keydown', { key: k });

describe('opening', () => {
  it('starts closed', () => {
    expect(make().suggest.open).toBe(false);
  });

  it('opens on focus, even with an empty box (that press asks for the history)', () => {
    const { suggest } = make();
    suggest.handleFocus();
    expect(suggest.open).toBe(true);
  });

  it('opens on a keystroke that leaves text in the box', () => {
    const { suggest } = make();
    suggest.handleInput('C');
    expect(suggest.open).toBe(true);
  });

  it('re-opens on ArrowDown once dismissed, without reaching the dropdown', () => {
    const { suggest, keys } = opened();
    suggest.close();
    suggest.handleKeydown(key('ArrowDown'));
    expect(suggest.open).toBe(true);
    // The keystroke that re-opens is consumed here, not forwarded as a move.
    expect(keys).toEqual([]);
  });

  it('forwards every other keystroke to the dropdown while open', () => {
    const { suggest, keys } = opened();
    suggest.handleKeydown(key('ArrowDown'));
    suggest.handleKeydown(key('Enter'));
    expect(keys).toEqual(['ArrowDown', 'Enter']);
  });
});

describe('the panel survives a committed search', () => {
  /**
   * The composable is the whole of the panel's open/closed state, so "App
   * committed a search and its results landed" is expressible as: everything
   * the surface does around a commit, with the commit itself contributing
   * nothing. If that ever stops being true, the enumeration below catches it.
   */
  it('stays open while the reader pauses over the suggestions', () => {
    const { suggest } = opened();
    // 250 ms of stillness later the debounce fires, the search runs, the
    // results replace the list underneath. Nothing here touches the panel.
    expect(suggest.open).toBe(true);
  });

  it('keeps the rows aimed at the raw text: activeId survives too', () => {
    const { suggest } = opened();
    suggest.setActiveId('iwac-suggest-7-opt-2');
    expect(suggest.expanded).toBe(true);
    expect(suggest.activeId).toBe('iwac-suggest-7-opt-2');
  });

  it('does not re-open a panel the reader dismissed', () => {
    const { suggest } = opened();
    suggest.close(); // Escape, or a press on the toolbar
    // Results land. Re-arming here is the pre-3.16.0 bug.
    expect(suggest.open).toBe(false);
    // Only a fresh act of the reader's brings it back.
    suggest.handleInput('Chams');
    expect(suggest.open).toBe(true);
  });

  it('only the named dismissals close an open panel', () => {
    vi.useFakeTimers();
    try {
      /**
       * One plausible call per public member, and the set that is ALLOWED to
       * close. A new member with no entry here fails loudly rather than
       * silently joining the untested surface.
       */
      const args: Record<string, unknown[]> = {
        setActiveId: [null],
        close: [],
        handleInput: ['Cham'], // typing, not emptying — see the test below
        handleFocus: [],
        handleBlur: [],
        handleKeydown: [key('ArrowDown')],
        pickQuery: ['ramadan'],
        runSearch: ['ramadan'],
        pickEntity: ['topic_ss', 'Ramadan'],
      };
      const mayClose = new Set(['close', 'handleBlur', 'pickQuery', 'runSearch', 'pickEntity']);

      const members = Object.entries(make().suggest)
        .filter(([, v]) => typeof v === 'function')
        .map(([k]) => k);
      expect(members.sort()).toEqual(Object.keys(args).sort());

      const closers: string[] = [];
      for (const name of members) {
        const { suggest } = opened();
        (suggest[name as keyof typeof suggest] as (...a: unknown[]) => void)(...args[name]);
        vi.runAllTimers(); // let the blur grace period elapse
        if (!suggest.open) closers.push(name);
      }
      expect(closers.sort()).toEqual([...mayClose].sort());
    } finally {
      vi.useRealTimers();
    }
  });
});

describe('closing', () => {
  it('closes when the box is emptied', () => {
    const { suggest } = opened();
    suggest.handleInput('');
    expect(suggest.open).toBe(false);
  });

  it('treats a whitespace-only box as empty', () => {
    const { suggest } = opened();
    suggest.handleInput('   ');
    expect(suggest.open).toBe(false);
  });

  it('closes on blur, but only after the row-click grace period', () => {
    vi.useFakeTimers();
    try {
      const { suggest } = opened();
      suggest.handleBlur();
      vi.advanceTimersByTime(100);
      expect(suggest.open).toBe(true); // a row click still has time to land
      vi.advanceTimersByTime(50);
      expect(suggest.open).toBe(false);
    } finally {
      vi.useRealTimers();
    }
  });

  it('closes on every kind of row pick, and hands the pick to App', () => {
    const picked = opened();
    picked.suggest.pickQuery('ramadan');
    expect(picked.suggest.open).toBe(false);
    expect(picked.commits).toEqual(['ramadan']);

    const ran = opened();
    ran.suggest.runSearch('hajj');
    expect(ran.suggest.open).toBe(false);
    expect(ran.commits).toEqual(['hajj']);

    const entity = opened();
    entity.suggest.pickEntity('topic_ss', 'Ramadan');
    expect(entity.suggest.open).toBe(false);
    expect(entity.entities).toEqual([['topic_ss', 'Ramadan']]);
  });

  it('drops aria-expanded when there is no active option to point at', () => {
    const { suggest } = opened();
    expect(suggest.expanded).toBe(false); // open, but the listbox is still empty
    suggest.setActiveId('iwac-suggest-7-opt-0');
    expect(suggest.expanded).toBe(true);
    suggest.close();
    expect(suggest.expanded).toBe(false);
  });

  it('gives each mount its own listbox id', () => {
    expect(
      createTypeahead('7', { onCommitQuery: () => {}, onPickEntity: () => {} }).listboxId,
    ).toBe('iwac-suggest-7');
    expect(
      createTypeahead('standalone', { onCommitQuery: () => {}, onPickEntity: () => {} }).listboxId,
    ).toBe('iwac-suggest-standalone');
  });
});

describe('dismissOnOutsidePointer', () => {
  let teardown: (() => void) | null = null;
  let closed = 0;
  let isOpen = true;

  /** A search form with a panel inside it, plus a toolbar button outside. */
  function mount(): { root: HTMLElement; toolbar: HTMLButtonElement } {
    document.body.innerHTML = `
      <form id="root">
        <div class="iwac-input"><input id="field" /></div>
        <div class="iwac-suggest" id="panel">
          <div class="iwac-suggest__heading">
            <button class="iwac-suggest__clear" id="clear">Clear</button>
          </div>
          <button class="iwac-suggest__item" id="row">Search for «Cham»</button>
        </div>
      </form>
      <div class="toolbar"><button id="copylink">Copy link</button></div>
      <form id="other-root"><div class="iwac-input"><input id="other-field" /></div></form>
    `;
    const root = document.getElementById('root') as HTMLElement;
    const toolbar = document.getElementById('copylink') as HTMLButtonElement;
    teardown = dismissOnOutsidePointer(
      () => isOpen,
      () => root,
      () => {
        closed += 1;
      },
    );
    return { root, toolbar };
  }

  const press = (el: Element): void => {
    el.dispatchEvent(new Event('pointerdown', { bubbles: true }));
  };

  beforeEach(() => {
    closed = 0;
    isOpen = true;
  });
  afterEach(() => {
    teardown?.();
    teardown = null;
    document.body.innerHTML = '';
  });

  it('keeps the panel open for a press on the input', () => {
    mount();
    press(document.getElementById('field') as Element);
    expect(closed).toBe(0);
  });

  it('keeps it open for a press on a row, so the click can land', () => {
    mount();
    press(document.getElementById('row') as Element);
    expect(closed).toBe(0);
  });

  it('keeps it open for the clear-history control', () => {
    mount();
    press(document.getElementById('clear') as Element);
    expect(closed).toBe(0);
  });

  it("closes on a press in the panel's own dead space", () => {
    // The Wave-2 bug: the panel floats over the toolbar, so a press aimed at an
    // occluded control lands on the panel's padding. Suppressing the blur there
    // (the old container-level handler) made the panel un-dismissable by
    // pointer — the reader could click the same control forever.
    mount();
    press(document.getElementById('panel') as Element);
    expect(closed).toBe(1);
  });

  it('closes on a press on a toolbar control outside the form', () => {
    const { toolbar } = mount();
    press(toolbar);
    expect(closed).toBe(1);
  });

  it("closes when the press is in ANOTHER search block's input", () => {
    // The exemption is scoped to this mount's root, so two search blocks on one
    // page cannot hold each other open.
    mount();
    press(document.getElementById('other-field') as Element);
    expect(closed).toBe(1);
  });

  it('does nothing while the panel is already closed', () => {
    const { toolbar } = mount();
    isOpen = false;
    press(toolbar);
    expect(closed).toBe(0);
  });

  it('stops listening once torn down', () => {
    const { toolbar } = mount();
    teardown?.();
    teardown = null;
    press(toolbar);
    expect(closed).toBe(0);
  });
});
