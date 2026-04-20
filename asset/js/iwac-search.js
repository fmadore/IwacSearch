/*
 * IwacSearch — Svelte bundle entry point (placeholder).
 *
 * M0: stub that walks every [data-iwac-search-root] element on the page,
 * reads its sibling state script, and replaces the skeleton with a
 * "module loaded" notice. The real Svelte client lands in M1.
 *
 * The mount contract is intentionally minimal:
 *   <div data-iwac-search-root data-iwac-block-id="N"></div>
 *   <script type="application/json" id="iwac-search-state-N">{...}</script>
 *
 * The same contract works for:
 *   - block instances on Site pages (one per block, multiple per page OK)
 *   - the standalone /search route (block_id is omitted; state script id
 *     is "iwac-search-state-standalone")
 *   - curated browse pages (state has locked_filters baked in)
 */

(function () {
  'use strict';

  function mount(rootEl) {
    var blockId = rootEl.getAttribute('data-iwac-block-id') || 'standalone';
    var stateEl = document.getElementById('iwac-search-state-' + blockId);

    var state = null;
    if (stateEl) {
      try {
        state = JSON.parse(stateEl.textContent);
      } catch (e) {
        console.error('[iwac-search] bad state JSON for block', blockId, e);
        return;
      }
    }

    rootEl.innerHTML =
      '<div style="padding:1rem;color:var(--muted,#666);font-size:var(--text-sm,0.9rem)">' +
        '<strong>IwacSearch</strong> — bundle loaded (M0 placeholder).' +
        '<br>Mode: <code>' + (state && state.mode || 'unknown') + '</code>' +
        ', endpoint: <code>' + (state && state.endpoints && state.endpoints.search || '?') + '</code>.' +
        '<br>Real Svelte client lands in M1.' +
      '</div>';
  }

  function init() {
    var roots = document.querySelectorAll('[data-iwac-search-root]');
    for (var i = 0; i < roots.length; i++) {
      mount(roots[i]);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
})();
