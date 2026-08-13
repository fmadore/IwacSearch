import type { IwacDoc } from './types';
import { typeLabel, type Locale } from './i18n';
import { formatDuration } from './resultCard';

/**
 * Client-side serializers for the result-export menu: plain text, JSON,
 * RIS (Zotero / EndNote) and BibTeX, built from the citation metadata the
 * export fetch ships (see EXPORT_INCLUDE_FIELDS in typesense.ts).
 *
 * Field conventions mirror the IWAC-SEO module's CitationMeta service —
 * the same logic that drives the Zotero Connector capture on item pages:
 *   - the container (journal / newspaper / publication) lives in
 *     publisher_s (references) or newspaper_ss (press subsets);
 *   - a chapter's containing book lives in book_title_s;
 *   - press articles / publication issues are typed newspaperArticle /
 *     magazineArticle, never journalArticle.
 */

export type ExportFormat = 'txt' | 'json' | 'ris' | 'bibtex';

export interface ExportMeta {
  /** The free-text query the export was run with ('' = browse). */
  query: string;
  /** Total matches in the result set (may exceed the exported count). */
  found: number;
}

export const EXPORT_FORMATS: ReadonlyArray<{
  format: ExportFormat;
  extension: string;
  mime: string;
}> = [
  { format: 'txt', extension: 'txt', mime: 'text/plain;charset=utf-8' },
  { format: 'json', extension: 'json', mime: 'application/json;charset=utf-8' },
  { format: 'ris', extension: 'ris', mime: 'application/x-research-info-systems;charset=utf-8' },
  { format: 'bibtex', extension: 'bib', mime: 'application/x-bibtex;charset=utf-8' },
];

export function serialize(
  format: ExportFormat,
  docs: IwacDoc[],
  meta: ExportMeta,
  locale: Locale,
): string {
  switch (format) {
    case 'json':
      return toJson(docs, meta);
    case 'ris':
      return toRis(docs);
    case 'bibtex':
      return toBibtex(docs);
    default:
      return toTxt(docs, meta, locale);
  }
}

/** Trigger a browser download of `content` as `filename`. */
export function download(filename: string, mime: string, content: string): void {
  const blob = new Blob([content], { type: mime });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}

export function exportFilename(extension: string): string {
  const stamp = new Date().toISOString().slice(0, 10);
  return `iwac-search-${stamp}.${extension}`;
}

// ─── Shared field helpers ────────────────────────────────────────────────

/**
 * The container title: journal/publisher for references, newspaper for the
 * press subsets, and the producing channel for audiovisual — which is where
 * `dcterms:publisher` lands on those records (channel_ss, not newspaper_ss),
 * so without the second read every exported video row lost its provenance.
 */
function container(d: IwacDoc): string {
  if (d.type_s === 'reference') {
    const rt = d.reference_type_ss?.[0] ?? '';
    if (rt === 'Chapitre') return (d.book_title_s ?? '').trim();
    return (d.publisher_s ?? '').trim();
  }
  return (d.newspaper_ss?.[0] ?? d.channel_ss?.[0] ?? '').trim();
}

/** An http(s) source URL, or '' — never a `javascript:` value from the index. */
function externalUrl(d: IwacDoc): string {
  const url = (d.source_url ?? '').trim();
  return /^https?:\/\//i.test(url) ? url : '';
}

/** "185–209" → [start, end]; single page → [page, '']. */
function pageRange(d: IwacDoc): [string, string] {
  const pages = (d.pages_s ?? '').trim();
  if (!pages) return ['', ''];
  const m = pages.split(/[–—-]/).map((s) => s.trim());
  return [m[0] ?? '', m[1] ?? ''];
}

/** Epoch seconds → [YYYY, MM, DD] (UTC), or null. */
function dateParts(d: IwacDoc): [string, string, string] | null {
  if (!d.date || d.date <= 0) return null;
  const dt = new Date(d.date * 1000);
  const pad = (n: number): string => String(n).padStart(2, '0');
  return [String(dt.getUTCFullYear()), pad(dt.getUTCMonth() + 1), pad(dt.getUTCDate())];
}

function year(d: IwacDoc): string {
  return d.pub_year ? String(d.pub_year) : (dateParts(d)?.[0] ?? '');
}

// ─── Plain text ──────────────────────────────────────────────────────────

function toTxt(docs: IwacDoc[], meta: ExportMeta, locale: Locale): string {
  const lines: string[] = [
    'Islam West Africa Collection — https://islam.zmo.de/',
    meta.query
      ? `${locale === 'fr' ? 'Recherche' : 'Search'}: ${meta.query}`
      : locale === 'fr'
        ? 'Parcours (sans requête)'
        : 'Browse (no query)',
    `${locale === 'fr' ? 'Résultats exportés' : 'Exported results'}: ${docs.length} / ${meta.found}`,
    '',
  ];
  for (const d of docs) {
    const parts: string[] = [];
    const authors = (d.creator_ss ?? []).join(', ');
    const y = year(d);
    parts.push(`${authors ? authors + ' ' : ''}${y ? `(${y})` : ''}`.trim());
    parts.push(d.title ?? '');
    const cont = container(d);
    if (cont) {
      const vol = (d.volume_s ?? '').trim();
      const iss = (d.issue_s ?? '').trim();
      const volIss = vol && iss ? `${vol}(${iss})` : vol || (iss ? `(${iss})` : '');
      const pages = (d.pages_s ?? '').trim();
      parts.push([cont, volIss, pages].filter(Boolean).join(', '));
    }
    if (d.type_s && d.type_s !== 'reference') {
      // Running time rides inside the type token — "[Audiovisuel, 5:32]" —
      // rather than as a bare number nobody could interpret.
      const dur = formatDuration(d.duration_seconds);
      parts.push(`[${[typeLabel(d.type_s, locale) || d.type_s, dur].filter(Boolean).join(', ')}]`);
    }
    if (d.omeka_url) parts.push(d.omeka_url);
    // The canonical source (a YouTube watch URL, an original article page)
    // AFTER the IWAC record: provenance first, third party second.
    const external = externalUrl(d);
    if (external) parts.push(external);
    lines.push('- ' + parts.filter(Boolean).join('. '));
  }
  return lines.join('\n') + '\n';
}

// ─── JSON ────────────────────────────────────────────────────────────────

function toJson(docs: IwacDoc[], meta: ExportMeta): string {
  return JSON.stringify(
    {
      source: 'Islam West Africa Collection (https://islam.zmo.de/)',
      exported_at: new Date().toISOString(),
      query: meta.query || null,
      total_found: meta.found,
      exported: docs.length,
      results: docs,
    },
    null,
    2,
  );
}

// ─── RIS ─────────────────────────────────────────────────────────────────

/** reference_type_ss → RIS TY for the references subset. */
const RIS_REFERENCE_TYPES: Record<string, string> = {
  'Article de revue': 'JOUR',
  'Compte rendu': 'JOUR',
  Chapitre: 'CHAP',
  Livre: 'BOOK',
  'Ouvrage collectif': 'EDBOOK',
  Thèse: 'THES',
  Rapport: 'RPRT',
  Communication: 'CONF',
  'Article de blog': 'BLOG',
};

/** type_s → RIS TY for the primary-source subsets. */
const RIS_CONTENT_TYPES: Record<string, string> = {
  article: 'NEWS',
  publication: 'MGZN',
  document: 'GEN',
  audiovisual: 'VIDEO',
  photograph: 'FIGURE',
};

function risType(d: IwacDoc): string {
  if (d.type_s === 'reference') {
    return RIS_REFERENCE_TYPES[d.reference_type_ss?.[0] ?? ''] ?? 'GEN';
  }
  return RIS_CONTENT_TYPES[d.type_s ?? ''] ?? 'GEN';
}

function toRis(docs: IwacDoc[]): string {
  const out: string[] = [];
  for (const d of docs) {
    const tag = (name: string, value: string | undefined | null): void => {
      const v = (value ?? '').trim();
      if (v !== '') out.push(`${name}  - ${v}`);
    };
    out.push(`TY  - ${risType(d)}`);
    tag('TI', d.title);
    for (const a of d.creator_ss ?? []) tag('AU', a);
    for (const e of d.editor_ss ?? []) tag('A2', e);
    if (d.type_s === 'audiovisual') {
      // On a VIDEO entry the channel is the studio/label (PB); T2 would
      // claim it is a series the recording belongs to.
      tag('PB', container(d));
    } else {
      tag('T2', container(d));
    }
    // Publisher / institution — for chapters the container() above is the
    // book, so the actual publisher still goes to PB.
    const rt = d.reference_type_ss?.[0] ?? '';
    if (d.type_s === 'reference' && rt !== 'Article de revue' && rt !== 'Compte rendu') {
      tag('PB', d.publisher_s);
    }
    tag('VL', d.volume_s);
    tag('IS', d.issue_s);
    const [sp, ep] = pageRange(d);
    tag('SP', sp);
    tag('EP', ep);
    tag('PY', year(d));
    const parts = dateParts(d);
    if (parts) tag('DA', parts.join('/'));
    tag('ET', d.edition_s);
    tag('LA', d.language_ss?.[0]);
    for (const kw of d.subjects_ss ?? []) tag('KW', kw);
    tag('AB', d.abstract);
    tag('DO', d.doi);
    tag('UR', d.omeka_url);
    // L2 ("link to full text") carries the canonical source — the YouTube
    // watch URL for a video — so UR keeps pointing at the IWAC record.
    tag('L2', externalUrl(d));
    tag('ID', d.identifier ?? d.id);
    out.push('ER  - ', '');
  }
  return out.join('\r\n');
}

// ─── BibTeX ──────────────────────────────────────────────────────────────

const BIBTEX_REFERENCE_TYPES: Record<string, string> = {
  'Article de revue': 'article',
  'Compte rendu': 'article',
  Chapitre: 'incollection',
  Livre: 'book',
  'Ouvrage collectif': 'book',
  Thèse: 'phdthesis',
  Rapport: 'techreport',
  Communication: 'inproceedings',
  'Article de blog': 'misc',
};

function bibtexType(d: IwacDoc): string {
  if (d.type_s === 'reference') {
    return BIBTEX_REFERENCE_TYPES[d.reference_type_ss?.[0] ?? ''] ?? 'misc';
  }
  // Press articles + publication issues are @article entries whose journal
  // is the newspaper/publication title.
  if (d.type_s === 'article' || d.type_s === 'publication') return 'article';
  return 'misc';
}

/** Minimal BibTeX escaping for the IWAC value space. */
function bib(value: string): string {
  return value
    .replace(/[\\{}]/g, ' ')
    .replace(/([&%#_$])/g, '\\$1')
    .replace(/\s+/g, ' ')
    .trim();
}

function toBibtex(docs: IwacDoc[]): string {
  const entries: string[] = [];
  for (const d of docs) {
    const type = bibtexType(d);
    const fields: Array<[string, string]> = [];
    const add = (name: string, value: string | undefined | null): void => {
      const v = (value ?? '').trim();
      if (v !== '') fields.push([name, bib(v)]);
    };

    add('title', d.title);
    add('author', (d.creator_ss ?? []).join(' and '));
    add('editor', (d.editor_ss ?? []).join(' and '));
    add('year', year(d));

    const cont = container(d);
    if (type === 'article') {
      add('journal', cont);
      add('volume', d.volume_s);
      add('number', d.issue_s);
    } else if (type === 'incollection') {
      add('booktitle', cont);
      add('publisher', d.publisher_s);
    } else if (type === 'phdthesis') {
      add('school', d.publisher_s);
    } else if (type === 'techreport') {
      add('institution', d.publisher_s);
    } else if (type === 'book') {
      add('publisher', d.publisher_s);
      add('edition', d.edition_s);
    } else {
      add('howpublished', cont || d.publisher_s);
    }

    const pages = (d.pages_s ?? '').trim();
    if (pages) add('pages', pages.replace(/[–—]/g, '--'));
    add('language', d.language_ss?.[0]);
    add('keywords', (d.subjects_ss ?? []).join(', '));
    add('doi', d.doi);
    add('url', d.omeka_url);
    // BibTeX has one url field, and it must stay on the IWAC record; the
    // canonical source goes in note, which is where a reader looks for it.
    add('note', externalUrl(d));

    const key = `iwac${d.id}`;
    const body = fields.map(([n, v]) => `  ${n} = {${v}}`).join(',\n');
    entries.push(`@${type}{${key},\n${body}\n}`);
  }
  return entries.join('\n\n') + '\n';
}
