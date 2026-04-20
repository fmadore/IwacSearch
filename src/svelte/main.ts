/**
 * Auto-mount entry point.
 *
 * Walks every [data-iwac-search-root] element on the page, reads the
 * sibling JSON state script, and mounts an App instance into it.
 * Multiple mounts per page (e.g. one block + one /search header
 * promotion) work because each mount has its own bootstrap state and
 * its own TypesenseClient instance.
 */

import { mount } from 'svelte';
import App from './App.svelte';
import type { IwacBootstrap } from './lib/types';

function readBootstrap(rootEl: HTMLElement): IwacBootstrap | null {
  const blockId = rootEl.getAttribute('data-iwac-block-id') ?? 'standalone';
  const stateEl = document.getElementById(`iwac-search-state-${blockId}`);
  if (!stateEl?.textContent) {
    console.error('[iwac-search] no state script for block', blockId);
    return null;
  }
  try {
    return JSON.parse(stateEl.textContent) as IwacBootstrap;
  } catch (err) {
    console.error('[iwac-search] malformed state JSON for block', blockId, err);
    return null;
  }
}

function mountAll(): void {
  const roots = document.querySelectorAll<HTMLElement>('[data-iwac-search-root]');
  roots.forEach((rootEl) => {
    if (rootEl.dataset.iwacMounted === '1') {
      return; // idempotent against double-load (e.g. defer + manual call)
    }
    const bootstrap = readBootstrap(rootEl);
    if (!bootstrap) {
      return;
    }
    rootEl.innerHTML = ''; // clear server-rendered skeleton
    rootEl.dataset.iwacMounted = '1';
    mount(App, { target: rootEl, props: { bootstrap } });
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', mountAll, { once: true });
} else {
  mountAll();
}
