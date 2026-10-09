import { afterEach, describe, expect, it, vi } from 'vitest';
import { createCopyState } from '../../src/svelte/lib/clipboard.svelte';

/**
 * S-13: a failed copy used to be silence — the button did nothing. The state
 * now says which way it went, for as long as the label shows it.
 */
describe('createCopyState', () => {
  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  it('reports a copy, then returns to idle', async () => {
    vi.useFakeTimers();
    vi.stubGlobal('navigator', { clipboard: { writeText: vi.fn(async () => {}) } });
    const state = createCopyState(2000);
    await state.copy('https://example.org/');
    expect(state.status).toBe('copied');
    expect(state.copied).toBe(true);
    vi.advanceTimersByTime(2000);
    expect(state.status).toBe('idle');
  });

  it('reports a failure when neither the API nor the fallback copied', async () => {
    vi.stubGlobal('navigator', {
      clipboard: {
        writeText: vi.fn(async () => {
          throw new Error('denied');
        }),
      },
    });
    document.execCommand = vi.fn(() => false);
    const state = createCopyState();
    await state.copy('x');
    expect(state.status).toBe('failed');
    expect(state.copied).toBe(false);
  });
});
