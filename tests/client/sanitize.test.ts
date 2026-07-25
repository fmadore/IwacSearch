import { describe, expect, it } from 'vitest';
import { escapeHtml, sanitizeHighlight } from '../../src/svelte/lib/sanitize';

/**
 * Every {@html}-rendered string in the client goes through
 * `sanitizeHighlight` — result snippets, both suggest dropdowns, map popups.
 * The values come from Typesense, which echoes back indexed OCR, so the
 * escape-then-restore strategy is the only thing between a crafted document
 * and script execution in a visitor's browser.
 *
 * The predecessor was a tag ALLOWLIST that let `<mark onmouseover=…>`
 * through; these cases pin the replacement.
 */

describe('escapeHtml', () => {
  it('escapes every HTML-significant character', () => {
    expect(escapeHtml(`&<>"'`)).toBe('&amp;&lt;&gt;&quot;&#39;');
  });

  it('escapes the ampersand first, so entities are not double-decoded', () => {
    expect(escapeHtml('&lt;')).toBe('&amp;lt;');
  });

  it('leaves ordinary text (including diacritics and Arabic) alone', () => {
    expect(escapeHtml('Côte d')).toBe('Côte d');
    expect(escapeHtml('الإسلام')).toBe('الإسلام');
  });
});

describe('sanitizeHighlight', () => {
  it('restores the bare <mark> tags Typesense emits', () => {
    expect(sanitizeHighlight('le <mark>ramadan</mark> à Cotonou')).toBe(
      'le <mark>ramadan</mark> à Cotonou',
    );
  });

  it('keeps an OPENING <mark> that carries attributes escaped', () => {
    // The exact hole the old tag-allowlist left open. Only the opening tag
    // matters: the bare `</mark>` is restored (it is attribute-less by
    // definition), and an unmatched closing tag is inert in every browser.
    expect(sanitizeHighlight('<mark onmouseover="alert(1)">x</mark>')).toBe(
      '&lt;mark onmouseover=&quot;alert(1)&quot;&gt;x</mark>',
    );
  });

  it.each([
    '<script>alert(1)</script>',
    '<img src=x onerror=alert(1)>',
    '<svg/onload=alert(1)>',
    '<iframe src="javascript:alert(1)"></iframe>',
    '<MARK>x</MARK>',
  ])('neutralises %s', (payload) => {
    const out = sanitizeHighlight(payload);
    expect(out).not.toContain('<script');
    expect(out).not.toContain('<img');
    expect(out).not.toContain('<svg');
    expect(out).not.toContain('<iframe');
    // The ONLY tag that may survive is a bare <mark>/</mark>.
    expect(out.replace(/<\/?mark>/g, '')).not.toContain('<');
  });

  it('returns an empty string for empty or missing input', () => {
    expect(sanitizeHighlight(undefined)).toBe('');
    expect(sanitizeHighlight('')).toBe('');
  });
});
