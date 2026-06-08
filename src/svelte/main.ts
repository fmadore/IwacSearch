/**
 * Auto-mount entry point.
 *
 * Two surfaces share this bundle:
 *   - [data-iwac-search-root]    → an App (the /search shell, /browse pages,
 *                                  and every page block).
 *   - [data-iwac-federated-root] → a FederatedApp (the /search/everything
 *                                  page: Content + Entities tabs over both
 *                                  collections).
 *
 * Each root reads its own sibling JSON state script and mounts its own
 * component instance, so multiple mounts per page work (each has its own
 * bootstrap + TypesenseClient).
 */

import { mount } from 'svelte';
import App from './App.svelte';
import FederatedApp from './components/FederatedApp.svelte';
import type { IwacBootstrap, IwacFederatedBootstrap } from './lib/types';

function parseState<T>(scriptId: string): T | null {
  const stateEl = document.getElementById(scriptId);
  if (!stateEl?.textContent) {
    console.error('[iwac-search] no state script', scriptId);
    return null;
  }
  try {
    return JSON.parse(stateEl.textContent) as T;
  } catch (err) {
    console.error('[iwac-search] malformed state JSON', scriptId, err);
    return null;
  }
}

function mountSearchRoots(): void {
  const roots = document.querySelectorAll<HTMLElement>('[data-iwac-search-root]');
  roots.forEach((rootEl) => {
    if (rootEl.dataset.iwacMounted === '1') {
      return; // idempotent against double-load (e.g. defer + manual call)
    }
    const blockId = rootEl.getAttribute('data-iwac-block-id') ?? 'standalone';
    const bootstrap = parseState<IwacBootstrap>(`iwac-search-state-${blockId}`);
    if (!bootstrap) {
      return;
    }
    rootEl.innerHTML = ''; // clear server-rendered skeleton
    rootEl.dataset.iwacMounted = '1';
    mount(App, { target: rootEl, props: { bootstrap } });
  });
}

function mountFederatedRoots(): void {
  const roots = document.querySelectorAll<HTMLElement>('[data-iwac-federated-root]');
  roots.forEach((rootEl) => {
    if (rootEl.dataset.iwacMounted === '1') {
      return;
    }
    const blockId = rootEl.getAttribute('data-iwac-block-id') ?? 'everything';
    const bootstrap = parseState<IwacFederatedBootstrap>(`iwac-federated-state-${blockId}`);
    if (!bootstrap) {
      return;
    }
    rootEl.innerHTML = '';
    rootEl.dataset.iwacMounted = '1';
    mount(FederatedApp, { target: rootEl, props: { bootstrap } });
  });
}

function mountAll(): void {
  mountSearchRoots();
  mountFederatedRoots();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', mountAll, { once: true });
} else {
  mountAll();
}
