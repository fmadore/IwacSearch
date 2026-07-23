/**
 * HTML sanitising helpers — ONE implementation for every surface that
 * {@html}-renders text (result snippets, the two suggest dropdowns, map
 * popups). Three hand-rolled copies existed before, with two DIFFERENT
 * strategies: strip-non-mark-tags (weaker — `<mark onmouseover=…>` slipped
 * through the tag allowlist) and escape-then-restore (ResultItem). Everything
 * now uses escape-then-restore.
 */

/** Escape every HTML-significant character. */
export function escapeHtml(s: string): string {
  return s.replace(
    /[&<>"']/g,
    (c) =>
      ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
      })[c] ?? c,
  );
}

/**
 * Make a Typesense highlight snippet safe for {@html}: escape everything,
 * then re-introduce only the literal, attribute-less <mark>/</mark> tags
 * Typesense uses to surround matches. Defense in depth — a marked-up tag
 * with attributes stays escaped (visible as text) rather than parsed.
 */
export function sanitizeHighlight(html: string | undefined): string {
  if (!html) return '';
  return escapeHtml(html)
    .replace(/&lt;mark&gt;/g, '<mark>')
    .replace(/&lt;\/mark&gt;/g, '</mark>');
}
