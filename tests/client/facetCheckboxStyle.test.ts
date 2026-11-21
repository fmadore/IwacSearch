import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { compile } from 'svelte/compiler';
import { afterEach, describe, expect, it } from 'vitest';

afterEach(() => {
  document.body.innerHTML = '';
  document.head.querySelector('[data-test-checkbox-cascade]')?.remove();
});

describe('facet checkbox theme isolation', () => {
  it('cancels the global theme transform and restores a native control', () => {
    const componentPath = resolve('src/svelte/components/FacetGroup.svelte');
    const compiled = compile(readFileSync(componentPath, 'utf8'), {
      filename: componentPath,
      generate: 'client',
      css: 'external',
    });
    const css = compiled.css?.code ?? '';
    const scopedClass = css.match(/\.iwac-facet__checkbox\.(svelte-[\w-]+)/)?.[1];
    expect(scopedClass).toBeTruthy();

    const styles = document.createElement('style');
    styles.dataset.testCheckboxCascade = '';
    styles.textContent = `
      input[type='checkbox'] {
        appearance: none;
        display: grid;
        place-content: center;
        transform: translateY(0.2em);
      }
      ${css}
    `;
    document.head.appendChild(styles);

    const option = document.createElement('label');
    option.className = `iwac-facet__option ${scopedClass}`;
    const checkbox = document.createElement('input');
    checkbox.type = 'checkbox';
    checkbox.className = `iwac-facet__checkbox ${scopedClass}`;
    option.appendChild(checkbox);
    document.body.appendChild(option);

    const checkboxStyle = getComputedStyle(checkbox);
    expect(checkboxStyle.transform).toBe('none');
    // The rule writes `appearance: auto` then `-webkit-appearance: checkbox`.
    // jsdom 31, like browsers, treats the prefixed name as an alias, so the
    // later `checkbox` wins; either value is the native control, which is
    // what matters: the theme's `none` is gone.
    expect(['auto', 'checkbox']).toContain(checkboxStyle.appearance);
    // `inline-block` on a grid item computes to `block` (CSS blockifies grid
    // items; jsdom 31 does too). The theme's leaked `grid` would stay `grid`.
    expect(checkboxStyle.display).toBe('block');
    expect(getComputedStyle(option).alignItems).toBe('center');
  });
});
