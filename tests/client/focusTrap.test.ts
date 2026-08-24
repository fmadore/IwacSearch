import { afterEach, describe, expect, it } from 'vitest';
import {
  focusFirstWithin,
  focusableWithin,
  handleTrapKeydown,
  restoreFocus,
} from '../../src/svelte-shared/lib/focusTrap';

/**
 * The modal focus contract, tested at the seam where it actually lives.
 *
 * jsdom has no layout, so `getClientRects()` returns [] for everything and
 * the reachability filter would reject the whole document. Each test stubs it
 * per-element to model visibility explicitly — which is the honest shape
 * anyway: "is this element visible" is the one question these helpers ask the
 * environment, so a test that didn't state the answer would be asserting
 * jsdom's defaults rather than the trap's rules.
 */

const RECT = [{ x: 0, y: 0, width: 10, height: 10, top: 0, left: 0, right: 10, bottom: 10 }];

function visible(el: Element, isVisible = true): void {
  Object.defineProperty(el, 'getClientRects', {
    configurable: true,
    value: () => (isVisible ? RECT : []),
  });
}

/** Build a panel with the given inner HTML; everything is visible by default. */
function mount(html: string): HTMLElement {
  document.body.innerHTML = `<div id="panel" tabindex="-1">${html}</div>`;
  const panel = document.getElementById('panel') as HTMLElement;
  visible(panel);
  for (const el of panel.querySelectorAll('*')) visible(el);
  return panel;
}

function tab(panel: HTMLElement, shiftKey = false): boolean {
  const e = new KeyboardEvent('keydown', { key: 'Tab', shiftKey, cancelable: true });
  return handleTrapKeydown(panel, e);
}

afterEach(() => {
  document.body.innerHTML = '';
});

describe('focusableWithin', () => {
  it('collects tabbable descendants in document order', () => {
    const panel = mount(`
      <button id="a">a</button>
      <a id="b" href="#x">b</a>
      <input id="c" />
      <select id="d"></select>
    `);
    expect(focusableWithin(panel).map((el) => el.id)).toEqual(['a', 'b', 'c', 'd']);
  });

  it('skips disabled, aria-hidden, inert and zero-area elements', () => {
    const panel = mount(`
      <button id="ok">ok</button>
      <button id="disabled" disabled>no</button>
      <button id="ariaHidden" aria-hidden="true">no</button>
      <div inert><button id="inert">no</button></div>
      <button id="hidden">no</button>
    `);
    visible(panel.querySelector('#hidden') as Element, false);
    expect(focusableWithin(panel).map((el) => el.id)).toEqual(['ok']);
  });

  it('rejects every negative tabindex spelling, not just "-1"', () => {
    // The obvious selector for this is [tabindex]:not([tabindex^="-"]), which
    // silently admits "-01" — a focusable element treated as unfocusable is a
    // hole in the trap, so the value is parsed rather than pattern-matched.
    const panel = mount(`
      <div id="keep" tabindex="0">keep</div>
      <div id="minusOne" tabindex="-1">no</div>
      <div id="minusZeroOne" tabindex="-01">no</div>
      <div id="minusTwo" tabindex="-2">no</div>
    `);
    expect(focusableWithin(panel).map((el) => el.id)).toEqual(['keep']);
  });

  it('does not reject the fixed-position panel itself for having no offsetParent', () => {
    // The drawer panel is position:fixed, whose offsetParent is null — the
    // cheaper offsetParent visibility test would call it invisible and
    // restoreFocus/focusFirstWithin would refuse to focus it.
    const panel = mount('');
    Object.defineProperty(panel, 'offsetParent', { configurable: true, value: null });
    focusFirstWithin(panel);
    expect(document.activeElement).toBe(panel);
  });
});

describe('focusFirstWithin', () => {
  it('focuses the first tabbable child', () => {
    const panel = mount('<button id="first">1</button><button id="second">2</button>');
    focusFirstWithin(panel);
    expect((document.activeElement as HTMLElement).id).toBe('first');
  });

  it('falls back to the container when the dialog has nothing focusable', () => {
    const panel = mount('<p>Loading…</p>');
    focusFirstWithin(panel);
    expect(document.activeElement).toBe(panel);
  });
});

describe('handleTrapKeydown', () => {
  it('ignores keys other than Tab', () => {
    const panel = mount('<button id="a">a</button>');
    const e = new KeyboardEvent('keydown', { key: 'ArrowDown', cancelable: true });
    expect(handleTrapKeydown(panel, e)).toBe(false);
    expect(e.defaultPrevented).toBe(false);
  });

  it('lets Tab through in the middle of the list (the browser moves focus)', () => {
    const panel = mount('<button id="a">a</button><button id="b">b</button>');
    (panel.querySelector('#a') as HTMLElement).focus();
    expect(tab(panel)).toBe(false);
  });

  it('wraps forward from the last element to the first', () => {
    const panel = mount('<button id="a">a</button><button id="b">b</button>');
    (panel.querySelector('#b') as HTMLElement).focus();
    expect(tab(panel)).toBe(true);
    expect((document.activeElement as HTMLElement).id).toBe('a');
  });

  it('wraps backward from the first element to the last', () => {
    const panel = mount('<button id="a">a</button><button id="b">b</button>');
    (panel.querySelector('#a') as HTMLElement).focus();
    expect(tab(panel, true)).toBe(true);
    expect((document.activeElement as HTMLElement).id).toBe('b');
  });

  it('pulls focus back in when it is on the container', () => {
    const panel = mount('<button id="a">a</button><button id="b">b</button>');
    panel.focus();
    expect(tab(panel)).toBe(true);
    expect((document.activeElement as HTMLElement).id).toBe('a');
    panel.focus();
    expect(tab(panel, true)).toBe(true);
    expect((document.activeElement as HTMLElement).id).toBe('b');
  });

  it('pulls focus back in when it has escaped to the page behind the backdrop', () => {
    // This is the reported failure verbatim: six of six Tab presses landed in
    // the results column while the drawer was open.
    const panel = mount('<button id="a">a</button>');
    const outside = document.createElement('button');
    outside.id = 'outside';
    visible(outside);
    document.body.appendChild(outside);
    outside.focus();
    expect(tab(panel)).toBe(true);
    expect((document.activeElement as HTMLElement).id).toBe('a');
  });

  it('keeps focus on the container when there is nothing to move to', () => {
    const panel = mount('<p>Loading…</p>');
    panel.focus();
    expect(tab(panel)).toBe(true);
    expect(document.activeElement).toBe(panel);
  });

  it('re-reads the focusable list on every Tab, so a grown panel wraps correctly', () => {
    // The facet panel adds rows as groups expand and "Show N more" is
    // pressed. A list captured at open time would wrap at the wrong element.
    const panel = mount('<button id="a">a</button><button id="b">b</button>');
    (panel.querySelector('#b') as HTMLElement).focus();
    const added = document.createElement('button');
    added.id = 'c';
    visible(added);
    panel.appendChild(added);
    // b is no longer last, so Tab must NOT wrap.
    expect(tab(panel)).toBe(false);
    (added as HTMLElement).focus();
    expect(tab(panel)).toBe(true);
    expect((document.activeElement as HTMLElement).id).toBe('a');
  });
});

describe('restoreFocus', () => {
  it('returns focus to the invoker', () => {
    document.body.innerHTML = '<button id="trigger">Filters</button>';
    const trigger = document.getElementById('trigger') as HTMLElement;
    visible(trigger);
    expect(restoreFocus(trigger)).toBe(true);
    expect(document.activeElement).toBe(trigger);
  });

  it('refuses an invoker that has left the document', () => {
    const trigger = document.createElement('button');
    visible(trigger);
    expect(restoreFocus(trigger)).toBe(false);
  });

  it('refuses an invoker that is present but hidden', () => {
    // .focus() on a hidden element silently no-ops and leaves focus on
    // <body>, so the caller has to know it failed rather than assume it took.
    document.body.innerHTML = '<button id="trigger">Filters</button>';
    const trigger = document.getElementById('trigger') as HTMLElement;
    visible(trigger, false);
    expect(restoreFocus(trigger)).toBe(false);
  });

  it('tolerates a null invoker', () => {
    expect(restoreFocus(null)).toBe(false);
  });
});
