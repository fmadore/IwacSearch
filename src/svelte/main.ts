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
import { translate, type Locale } from './lib/i18n';
import { parseHeadingLevel } from './lib/headings';
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

/**
 * Swap the server-rendered skeleton for a surface. A component that throws
 * while mounting must neither leave its container empty (or shimmering
 * forever) nor stop the roots after it from mounting: the container gets a
 * notice in the reader's language, and the error goes to the console.
 */
function mountRoot(rootEl: HTMLElement, locale: Locale | undefined, render: () => void): void {
  rootEl.innerHTML = ''; // clear server-rendered skeleton
  rootEl.dataset.iwacMounted = '1';
  try {
    render();
  } catch (err) {
    console.error('[iwac-search] mount failed', err);
    const notice = document.createElement('p');
    notice.setAttribute('role', 'alert');
    notice.textContent = `${translate(locale ?? 'fr', 'search_unavailable')} ${translate(locale ?? 'fr', 'search_failed_hint')}`;
    rootEl.replaceChildren(notice);
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
    // Under a heading the page already prints, the surface's own step down.
    const headingLevel = parseHeadingLevel(rootEl.dataset.iwacHeadingLevel);
    mountRoot(rootEl, bootstrap.locale, () =>
      mount(App, { target: rootEl, props: { bootstrap, headingLevel } }),
    );
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
    const headingLevel = parseHeadingLevel(rootEl.dataset.iwacHeadingLevel);
    mountRoot(rootEl, bootstrap.locale, () =>
      mount(FederatedApp, { target: rootEl, props: { bootstrap, headingLevel } }),
    );
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
