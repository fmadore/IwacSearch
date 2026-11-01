import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushSync, mount, unmount } from 'svelte';
import SearchInput from '../../src/svelte/components/SearchInput.svelte';
import { defined } from './defined';

/**
 * The debounced search box. A suggestion picked within 250 ms of the last
 * keystroke used to lose to the commit still pending for the typed prefix:
 * the pick cleared a query that had not been committed yet, so the box's
 * `value` did not change, and the timer then searched for "Tabas" on top of
 * the picked filter (caught by the browser suite, one run in a few).
 */

let app: ReturnType<typeof mount> | null = null;

afterEach(() => {
  if (app) void unmount(app);
  app = null;
  document.body.innerHTML = '';
  vi.useRealTimers();
});

function setup() {
  vi.useFakeTimers();
  const onChange = vi.fn();
  const box = mount(SearchInput, { target: document.body, props: { value: '', onChange } });
  app = box;
  const input = defined(document.querySelector('input'), 'the search input');
  const type = (text: string): void => {
    input.value = text;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    flushSync();
  };
  return { box, input, onChange, type };
}

describe('SearchInput', () => {
  it('commits what was typed once the reader pauses', () => {
    const { onChange, type } = setup();
    type('Tabas');
    vi.advanceTimersByTime(249);
    expect(onChange).not.toHaveBeenCalled();
    vi.advanceTimersByTime(1);
    expect(onChange).toHaveBeenCalledExactlyOnceWith('Tabas');
  });

  it('drops the pending commit when the parent resets the query', () => {
    const { box, input, onChange, type } = setup();
    type('Tabas');
    box.reset('');
    flushSync();
    vi.advanceTimersByTime(1000);
    expect(onChange).not.toHaveBeenCalled();
    expect(input.value).toBe('');
  });
});
