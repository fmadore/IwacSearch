import { afterEach, describe, expect, it } from 'vitest';
import { refocus } from '../../src/svelte/lib/refocus';

/**
 * S-07: a control that removes itself must not drop focus to <body>.
 */
describe('refocus', () => {
  afterEach(() => {
    document.body.innerHTML = '';
  });

  function setup(): { gone: HTMLButtonElement; a: HTMLButtonElement; b: HTMLButtonElement } {
    document.body.innerHTML =
      '<button id="gone">×</button><button id="a">a</button><button id="b">b</button>';
    const [gone, a, b] = ['gone', 'a', 'b'].map(
      (id) => document.getElementById(id) as HTMLButtonElement,
    );
    return { gone, a, b };
  }

  it('focuses the first candidate still in the document once focus was lost', async () => {
    const { gone, a, b } = setup();
    gone.focus();
    gone.remove(); // what a removed chip does
    a.remove();
    await refocus(a, () => b);
    expect(document.activeElement).toBe(b);
  });

  it('leaves focus alone when the update put it somewhere real', async () => {
    const { a, b } = setup();
    a.focus();
    await refocus(b);
    expect(document.activeElement).toBe(a);
  });

  it('evaluates thunks after the update, so they can find the replacement', async () => {
    const { gone } = setup();
    gone.focus();
    gone.remove();
    const pending = refocus(() => document.getElementById('late'));
    document.body.insertAdjacentHTML('beforeend', '<button id="late">late</button>');
    await pending;
    expect(document.activeElement?.id).toBe('late');
  });
});
