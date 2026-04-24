/**
 * Entry point for the IwacSearch admin CRUD bundle.
 *
 * Walks every [data-iwac-admin-root] element on the page, reads the
 * sibling `iwac-admin-state-<surface>` JSON script (where `surface`
 * is the data-iwac-admin-surface attribute), and mounts an App into
 * the container.
 *
 * Right now there's only one surface — `browse-config` — but the
 * mount contract is generic so later admin surfaces (search metrics,
 * reindex status) can reuse the same entry point with a different
 * data-iwac-admin-surface value.
 */

import { mount } from 'svelte';
import App from './App.svelte';
import type { AdminBootstrap } from './lib/types';

function readBootstrap(rootEl: HTMLElement): AdminBootstrap | null {
  const surface = rootEl.getAttribute('data-iwac-admin-surface') ?? 'default';
  const stateEl = document.getElementById(`iwac-admin-state-${surface}`);
  if (!stateEl?.textContent) {
    console.error('[iwac-admin] no state script for surface', surface);
    return null;
  }
  try {
    return JSON.parse(stateEl.textContent) as AdminBootstrap;
  } catch (err) {
    console.error('[iwac-admin] malformed state JSON for surface', surface, err);
    return null;
  }
}

function mountAll(): void {
  const roots = document.querySelectorAll<HTMLElement>('[data-iwac-admin-root]');
  roots.forEach((rootEl) => {
    if (rootEl.dataset.iwacMounted === '1') {
      return;
    }
    const bootstrap = readBootstrap(rootEl);
    if (!bootstrap) {
      return;
    }
    rootEl.innerHTML = '';
    rootEl.dataset.iwacMounted = '1';
    mount(App, { target: rootEl, props: { bootstrap } });
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', mountAll, { once: true });
} else {
  mountAll();
}
