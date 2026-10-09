/**
 * Build a compact page-number window around the current page.
 *
 *   1 … 4 [5] 6 … 12
 *
 * Always shows the first and last pages so users can jump to either end. The
 * window around `current` is symmetric (current ± 1) and never overlaps the
 * bookend pages — gaps collapse into a single "…" marker so the bar never
 * grows wider than 7 cells. Lived in Pagination.svelte's module script, where
 * no test reached it; tests/client/pageWindow.test.ts pins it now.
 */
export function pageWindow(current: number, total: number): Array<number | 'gap'> {
  if (total <= 7) {
    return Array.from({ length: total }, (_, i) => i + 1);
  }
  const out: Array<number | 'gap'> = [1];
  const start = Math.max(2, current - 1);
  const end = Math.min(total - 1, current + 1);
  if (start > 2) out.push('gap');
  for (let i = start; i <= end; i++) out.push(i);
  if (end < total - 1) out.push('gap');
  out.push(total);
  return out;
}
