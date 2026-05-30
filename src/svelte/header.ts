/**
 * Header search autocomplete — a tiny, framework-free enhancer for the
 * IWAC-theme site header search box.
 *
 * The theme renders a plain GET form:
 *
 *   <form data-iwac-header-search method="get" action="…/recherche">
 *     <input name="q"> <button type="submit">⌕</button>
 *   </form>
 *
 * Without JS that already works — submitting navigates to the module's
 * faceted /search (or /recherche) landing page, whose Svelte client
 * hydrates `?q=` straight from the URL (see lib/urlState.ts). This script
 * progressively enhances it with a Typesense typeahead, reusing the
 * module's own `TypesenseClient` so the suggest contract (scoped-key mint,
 * facet_query, locked-filter scope) lives in exactly one place.
 *
 * Rows mirror the in-app SuggestDropdown:
 *   1. "Search for «q»"  → landing?q=<q>
 *   2. article hits      → the item's omeka_url (fallback landing?q=<title>)
 *   3. entity values     → landing?f.<field>=<value> (a pre-applied facet,
 *                          matching the urlState.ts facet encoding)
 *
 * It ships as its own IIFE bundle (vite --mode header) injected by
 * Module.php on every public site page, NOT the full ~90 KB search app.
 * Endpoints + locale come from an inline `window.IWAC_HEADER_SEARCH` blob
 * the module injects; the landing URL is read from the form's own action.
 */

import { TypesenseClient } from './lib/typesense';
import { facetLabel, normalizeLocale, translate, type Locale } from './lib/i18n';
import type { EntitySuggestion, IwacBootstrap, IwacHit, SuggestResult } from './lib/types';

import './header.css';

const DEFAULT_TOKEN_ENDPOINT = '/discovery/token';
const DEFAULT_SEARCH_ENDPOINT = '/search-api/multi_search';
const DEBOUNCE_MS = 140;
const MIN_CHARS = 2;
const BLUR_CLOSE_MS = 140;

declare global {
  interface Window {
    IWAC_HEADER_SEARCH?: {
      endpoints?: { token?: string; search?: string };
      locale?: string;
    };
  }
}

type Row =
  | { kind: 'search' }
  | { kind: 'article'; hit: IwacHit }
  | { kind: 'entity'; entity: EntitySuggestion };

interface HeaderConfig {
  endpoints: { token: string; search: string };
  locale: Locale;
}

let uid = 0;

function readConfig(): HeaderConfig {
  const raw = window.IWAC_HEADER_SEARCH ?? {};
  const token = raw.endpoints?.token || DEFAULT_TOKEN_ENDPOINT;
  const search = raw.endpoints?.search || DEFAULT_SEARCH_ENDPOINT;
  // Prefer the server-stamped locale; fall back to <html lang>; default fr.
  const langAttr = document.documentElement.getAttribute('lang') ?? '';
  const locale = normalizeLocale(raw.locale ?? langAttr.slice(0, 2));
  return { endpoints: { token, search }, locale };
}

/** Minimal bootstrap — `suggest()` only touches endpoints + (optional) scope. */
function buildBootstrap(cfg: HeaderConfig): IwacBootstrap {
  return {
    block_id: 'header',
    mode: 'compact',
    locale: cfg.locale,
    locked_filters: '',
    prominent_facets: [],
    default_sort: '_text_match:desc',
    results_per_page: 10,
    endpoints: cfg.endpoints,
  };
}

/** Strip every tag except <mark> so Typesense highlight snippets render safely. */
function safeMarkup(html: string | undefined): string {
  if (!html) return '';
  return html.replace(/<(?!\/?mark\b)[^>]*>/gi, '');
}

function escapeHtml(value: string): string {
  return value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function titleMarkupOf(hit: IwacHit): string {
  const titleHl = hit.highlights.find((h) => h.field === 'title_txt');
  if (titleHl?.snippet) {
    return safeMarkup(titleHl.snippet);
  }
  return escapeHtml(hit.document.title ?? '');
}

function urlOf(hit: IwacHit): string | null {
  return hit.document.omeka_url ?? hit.document.source_url ?? null;
}

/** Append/override query params on a (possibly relative) base URL. */
function withParams(base: string, params: Record<string, string>): string {
  const url = new URL(base, window.location.origin);
  for (const [key, value] of Object.entries(params)) {
    url.searchParams.set(key, value);
  }
  return url.toString();
}

class HeaderSearch {
  private readonly client: TypesenseClient;
  private readonly locale: Locale;
  private readonly landing: string;
  private readonly listbox: HTMLDivElement;

  private rows: Row[] = [];
  private highlighted = 0;
  private isOpen = false;
  private debounceTimer: number | null = null;
  private inflight = 0;

  constructor(
    private readonly form: HTMLFormElement,
    private readonly input: HTMLInputElement,
    cfg: HeaderConfig,
  ) {
    this.client = new TypesenseClient(buildBootstrap(cfg));
    this.locale = cfg.locale;
    // The landing page lives on the form's action — set by the theme via the
    // iwacSearchUrl helper (locale-correct). Fall back to the global /search.
    this.landing = form.getAttribute('action') || '/search';

    const host = this.resolveHost();
    this.listbox = document.createElement('div');
    this.listbox.className = 'iwac-header-suggest';
    this.listbox.id = `iwac-header-suggest-${++uid}`;
    this.listbox.setAttribute('role', 'listbox');
    this.listbox.setAttribute('aria-label', translate(this.locale, 'suggestions'));
    host.appendChild(this.listbox);

    // ARIA combobox wiring on the existing input.
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', this.listbox.id);
    input.setAttribute('autocomplete', 'off');

    this.wire();
  }

  /**
   * Anchor the dropdown to the `.main-header__search-form` wrapper (a block
   * box) rather than the inner flex pill, and guarantee it's a positioned
   * ancestor even if the theme CSS hasn't been rebuilt yet.
   */
  private resolveHost(): HTMLElement {
    const host =
      this.form.closest<HTMLElement>('.main-header__search-form') ??
      this.form.parentElement ??
      this.form;
    if (getComputedStyle(host).position === 'static') {
      host.style.position = 'relative';
    }
    return host;
  }

  private wire(): void {
    this.input.addEventListener('input', () => this.onInput());
    this.input.addEventListener('keydown', (e) => this.onKeydown(e));
    this.input.addEventListener('focus', () => {
      if (this.input.value.trim().length >= MIN_CHARS) this.open();
    });
    this.input.addEventListener('blur', () => {
      // Defer so a click on a row lands before the dropdown is hidden.
      window.setTimeout(() => this.close(), BLUR_CLOSE_MS);
    });
    // Submit (button click, or Enter while the dropdown is closed) runs a
    // full-text search for exactly what was typed.
    this.form.addEventListener('submit', (e) => {
      const q = this.input.value.trim();
      if (q.length === 0) return; // let the empty form no-op
      e.preventDefault();
      window.location.assign(withParams(this.landing, { q }));
    });
  }

  private onInput(): void {
    const q = this.input.value.trim();
    if (this.debounceTimer !== null) {
      clearTimeout(this.debounceTimer);
      this.debounceTimer = null;
    }
    if (q.length < MIN_CHARS) {
      this.rows = [];
      this.close();
      return;
    }
    this.setRows({ articles: [], entities: [] }); // show the "Search for…" row immediately
    this.open();
    const token = ++this.inflight;
    this.debounceTimer = window.setTimeout(() => {
      this.debounceTimer = null;
      this.client
        .suggest(q)
        .then((r) => {
          if (token !== this.inflight) return; // superseded by a newer keystroke
          this.setRows(r);
        })
        .catch(() => {
          // Typeahead failures stay silent — the plain form submit still works.
          if (token !== this.inflight) return;
          this.setRows({ articles: [], entities: [] });
        });
    }, DEBOUNCE_MS);
  }

  private setRows(result: SuggestResult): void {
    const rows: Row[] = [{ kind: 'search' }];
    for (const hit of result.articles) rows.push({ kind: 'article', hit });
    for (const entity of result.entities) rows.push({ kind: 'entity', entity });
    this.rows = rows;
    this.highlighted = 0; // re-arm on the "Search for…" action
    this.render();
  }

  private render(): void {
    const q = this.input.value.trim();
    this.listbox.textContent = '';
    this.rows.forEach((row, i) => this.listbox.appendChild(this.renderRow(row, i, q)));
    const hasSuggestions = this.rows.some((r) => r.kind !== 'search');
    if (!hasSuggestions) {
      const empty = document.createElement('div');
      empty.className = 'iwac-header-suggest__empty';
      empty.setAttribute('role', 'status');
      empty.textContent = translate(this.locale, 'no_matches');
      this.listbox.appendChild(empty);
    }
    this.renderHighlight();
  }

  private renderRow(row: Row, index: number, q: string): HTMLElement {
    const el: HTMLElement =
      row.kind === 'article' ? document.createElement('a') : document.createElement('button');
    el.className = 'iwac-header-suggest__item';
    el.setAttribute('role', 'option');
    el.dataset.index = String(index);
    if (el instanceof HTMLButtonElement) el.type = 'button';

    if (row.kind === 'search') {
      el.classList.add('iwac-header-suggest__item--search');
      // No leading magnifying-glass here: the header input already has its
      // own search-submit icon right above the panel, so a second ⌕ on this
      // row read as a duplicated search icon. The bold "Search for «q»" label
      // carries the affordance on its own (entity rows are text + tag too).
      const title = document.createElement('span');
      title.className = 'iwac-header-suggest__title';
      title.textContent = translate(this.locale, 'search_for', { q });
      el.append(title);
    } else if (row.kind === 'entity') {
      el.classList.add('iwac-header-suggest__item--entity');
      const title = document.createElement('span');
      title.className = 'iwac-header-suggest__title';
      title.textContent = row.entity.value;
      const tag = document.createElement('span');
      tag.className = 'iwac-header-suggest__tag';
      tag.textContent = facetLabel(row.entity.field, this.locale);
      el.append(title, tag);
    } else {
      (el as HTMLAnchorElement).href = urlOf(row.hit) ?? '#';
      const title = document.createElement('span');
      title.className = 'iwac-header-suggest__title';
      title.innerHTML = titleMarkupOf(row.hit); // sanitized: <mark> only
      el.appendChild(title);
    }

    // Keep input focus so the dropdown survives the click long enough to act.
    el.addEventListener('mousedown', (e) => e.preventDefault());
    el.addEventListener('mouseenter', () => {
      this.highlighted = index;
      this.renderHighlight();
    });
    el.addEventListener('click', (e) => {
      // Let cmd/ctrl/middle-click open an article in a new tab.
      if (row.kind === 'article' && urlOf(row.hit) && (e.metaKey || e.ctrlKey || e.button === 1)) {
        return;
      }
      e.preventDefault();
      this.activate(row);
    });
    return el;
  }

  private renderHighlight(): void {
    const items = this.listbox.querySelectorAll<HTMLElement>('.iwac-header-suggest__item');
    items.forEach((el) => {
      const active = Number(el.dataset.index) === this.highlighted;
      el.classList.toggle('iwac-header-suggest__item--active', active);
      el.setAttribute('aria-selected', active ? 'true' : 'false');
    });
  }

  private activate(row: Row): void {
    if (row.kind === 'search') {
      const q = this.input.value.trim();
      if (q.length > 0) window.location.assign(withParams(this.landing, { q }));
      return;
    }
    if (row.kind === 'entity') {
      // Matches lib/urlState.ts: ?f.<field>=<value> pre-applies a facet.
      window.location.assign(
        withParams(this.landing, { [`f.${row.entity.field}`]: row.entity.value }),
      );
      return;
    }
    const url = urlOf(row.hit);
    if (url) {
      window.location.assign(url);
      return;
    }
    const title = (row.hit.document.title ?? '').trim();
    window.location.assign(withParams(this.landing, { q: title || this.input.value.trim() }));
  }

  private onKeydown(e: KeyboardEvent): void {
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      if (!this.isOpen && this.input.value.trim().length >= MIN_CHARS) this.open();
      this.move(1);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      this.move(-1);
    } else if (e.key === 'Enter') {
      if (this.isOpen && this.rows.length > 0) {
        e.preventDefault();
        this.activate(this.rows[this.highlighted] ?? this.rows[0]);
      }
      // else: dropdown closed → let the native form submit handler run.
    } else if (e.key === 'Escape') {
      if (this.isOpen) {
        e.preventDefault();
        this.close();
      }
    }
  }

  private move(delta: number): void {
    if (this.rows.length === 0) return;
    this.highlighted = (this.highlighted + delta + this.rows.length) % this.rows.length;
    this.renderHighlight();
  }

  private open(): void {
    if (this.isOpen) return;
    this.isOpen = true;
    this.listbox.classList.add('iwac-header-suggest--open');
    this.input.setAttribute('aria-expanded', 'true');
  }

  private close(): void {
    if (!this.isOpen) return;
    this.isOpen = false;
    this.listbox.classList.remove('iwac-header-suggest--open');
    this.input.setAttribute('aria-expanded', 'false');
  }
}

function init(): void {
  const forms = document.querySelectorAll<HTMLFormElement>('form[data-iwac-header-search]');
  if (forms.length === 0) return;
  const cfg = readConfig();
  forms.forEach((form) => {
    if (form.dataset.iwacHeaderEnhanced === '1') return;
    const input = form.querySelector<HTMLInputElement>(
      'input[name="q"], input[type="search"], input[type="text"]',
    );
    if (!input) return;
    form.dataset.iwacHeaderEnhanced = '1';
    new HeaderSearch(form, input, cfg);
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init, { once: true });
} else {
  init();
}
