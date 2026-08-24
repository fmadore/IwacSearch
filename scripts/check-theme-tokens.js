#!/usr/bin/env node
/**
 * Theme-token contract guard (IwacSearch).
 *
 * IwacSearch consumes the IWAC theme's design tokens (see
 * IWAC-theme/docs/DESIGN-SYSTEM.md) and must never redefine them or drift
 * from their canonical values. This linter fails the build when a source
 * file's `var(--token, #fallback)` drifts from the theme's resolved token,
 * keeping the discipline automatic as new components land.
 *
 * Scans EVERY hand-written stylesheet the module ships: `src/` (*.svelte,
 * *.css, *.ts) and `asset/css/` (the hand-edited sheets that are not produced
 * by Vite). The canonical values come from `tokens.json`, synced from the
 * theme by IWAC-theme/scripts/build-tokens.js — the SINGLE SOURCE OF TRUTH.
 *
 * `asset/css/` was outside the walk until 2026-08 and it showed: `src/` was
 * spotless while every single colour fallback in `asset/css/iwac-search.css`
 * was still the pre-v2.6 cool-blue palette, including a
 * `var(--ink-light, var(--ink, #2c2f37))` chain asserting that a secondary
 * ink degrades to a primary one. A guard that skips a file is not a guard;
 * it is a comment about the files it does read.
 *
 * Rules:
 *   1. No removed tokens — `--primary-hue` / `--primary-sat` (theme v2.0.0).
 *   2. No `color-mix(in srgb …)` — the contract is `in oklab`.
 *   3. Every `var(--token, #hex)` fallback must EQUAL the token's canonical
 *      light value (tokens.json). A stale fallback is a competing variable
 *      even if it only paints when the theme is absent.
 *   3b. …and so must every NON-COLOUR fallback: type steps, spacing, radii,
 *      control sizes, line-heights, font stacks, shadows, transitions, all
 *      compared against `tokens.json`'s generated `values.light`. Rule 3 was
 *      a hex-only regex, so it enforced the fallback contract for colour and
 *      nothing else — which is precisely where this module's fallbacks had
 *      drifted a generation (`--panel-radius` 0.75rem vs 0.5rem,
 *      `--panel-shadow: none`, `--transition-base: 200ms ease`,
 *      `--ring-focus` as a neutral black ring).
 *   4. No bare hex outside a var() fallback slot, in any CSS context — `.css`
 *      files AND the `<style>` block of a `.svelte` SFC (the module's CSS).
 *      Svelte TEMPLATE markup (SVG fills, inline attrs) is exempt — only the
 *      `<style>` region is scanned. So is any line touching a sanctioned
 *      `--iwac-vis-*` data colour.
 *   5. Every `var(--…)` must NAME a token that exists: one published in
 *      `tokens.json`'s `names` (the theme's full vocabulary), one this module
 *      declares itself, or one in the module-owned `--iwac-` namespace.
 *      Rule 3 only ever checked hex *values*, so a reference to a token the
 *      theme never defined passed cleanly while rendering from its fallback
 *      forever, silently decoupled from the scale it appeared to track.
 *      `--space-2xs` sat here undetected that way until the theme published
 *      `names` (IWAC-theme 2.9.1).
 *   6. Every `@media (min|max-width: …)` must be written in px AND sit on one
 *      of the theme's six published breakpoints (`min-width` ON it,
 *      `max-width` at it − 1, so the halves of a pair never both match).
 *      Media queries cannot read custom properties, so the widths are
 *      necessarily restated as literals — which makes them the one part of
 *      the contract held together by a comment rather than a check. The px
 *      half of the rule is not stylistic: tokens.json publishes px, and a
 *      rem/em width resolves against a root font-size no linter can know, so
 *      requiring px is what makes the breakpoint check possible at all.
 * Lines carrying an `allow-hex` marker comment are exempt from 3 and 4.
 *
 * Usage: node scripts/check-theme-tokens.js   (npm run lint:theme)
 * Exit 1 on any violation, else 0. If tokens.json is missing, value checks
 * are skipped (with a warning) so a fresh checkout still builds.
 */
import { readdirSync, readFileSync, statSync, existsSync } from 'node:fs';
import { join, relative, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const SRC_DIRS = [join(ROOT, 'src'), join(ROOT, 'asset', 'css')];
const TOKENS_PATH = join(ROOT, 'tokens.json');
const EXTS = ['.svelte', '.css', '.ts'];

function walk(dir, out = []) {
  if (!existsSync(dir)) return out;
  for (const entry of readdirSync(dir)) {
    const p = join(dir, entry);
    if (statSync(p).isDirectory()) walk(p, out);
    else if (EXTS.some((e) => p.endsWith(e)) && !p.endsWith('.d.ts')) out.push(p);
  }
  return out;
}

function normHex(hex) {
  let h = hex.replace('#', '').toLowerCase();
  if (h.length === 3 || h.length === 4)
    h = h
      .slice(0, 3)
      .split('')
      .map((c) => c + c)
      .join('');
  return '#' + h.slice(0, 6);
}

let TOKENS = null;
if (existsSync(TOKENS_PATH)) {
  try {
    TOKENS = JSON.parse(readFileSync(TOKENS_PATH, 'utf8'));
  } catch {
    console.warn('  ! tokens.json present but unparseable — value checks skipped\n');
  }
} else {
  console.warn(
    '  ! tokens.json not found — value checks skipped (run `npm run build:tokens` in IWAC-theme)\n',
  );
}

const REMOVED_TOKEN = /--primary-(hue|sat)\b/;
const SRGB_MIX = /color-mix\(\s*in\s+srgb\b/i;
const HEX = /#[0-9a-fA-F]{3}(?:[0-9a-fA-F]{3}(?:[0-9a-fA-F]{2})?)?\b/g;
const VAR_FALLBACK = /var\(\s*(--[\w-]+)\s*,\s*(#[0-9a-fA-F]{3,8})\b/g;
const VAR_USE = /var\(\s*(--[\w-]+)/g;
const DECL = /(--[\w-]+)\s*:/g;
const MEDIA_WIDTH = /\((min|max)-width\s*:\s*([\d.]+)px\)/g;
// The same thing in any OTHER unit. The px form above is the only one the
// contract can check, because tokens.json publishes px and a rem/em width
// resolves against a root font-size the guard cannot know. Until 2026-08 the
// px requirement silently doubled as an exemption: every one of this module's
// eleven width queries was written in rem/em, so MEDIA_WIDTH matched nothing
// and rule 6 exited green over eleven violations — two of them sitting exactly
// ON 768px, taking the narrow branch at the 768 viewport where the min-width
// half of the pair also matched.
const MEDIA_WIDTH_NON_PX = /\((min|max)-width\s*:\s*[\d.]+(?!px)([a-z%]+)\)/gi;
// Absolute font-size literals only: em / % / unitless scale WITH whatever token
// the cascade already set, so they don't fork the scale. This module is already
// clean — >75 declarations all reading var(--text-*, …), the seven literals all
// relative — and the rule is here to keep it that way rather than to fix it.
const ABS_FONT_SIZE = /font-size:\s*(-?[\d.]+(?:px|rem|pt))\b/i;

/**
 * Every `var(--token, <fallback>)` on a line, with the fallback text extracted
 * by balancing parens — so a nested `var()` inside the fallback slot is
 * captured whole instead of truncated at the first `)`.
 */
function varFallbacks(line) {
  const out = [];
  for (let i = 0; (i = line.indexOf('var(', i)) !== -1;) {
    let depth = 0,
      j = i + 3;
    for (; j < line.length; j++) {
      if (line[j] === '(') depth++;
      else if (line[j] === ')') {
        depth--;
        if (depth === 0) break;
      }
    }
    if (j >= line.length) break; // fallback spans lines — not our business
    const inner = line.slice(i + 4, j);
    const comma = inner.indexOf(',');
    if (comma !== -1) {
      out.push({ name: inner.slice(0, comma).trim(), fallback: inner.slice(comma + 1).trim() });
    }
    i = j + 1;
  }
  return out;
}

/**
 * Is the position after `before` inside the fallback slot of an open `var()`?
 *
 * Not "does a comma immediately precede it": a fallback is a whole CSS value,
 * so `var(--panel-border, 1px solid #ced1d6)` puts the hex three tokens past
 * the comma. Requiring adjacency reported every composite fallback as bare
 * chrome — which is a standing incentive to write the nested-var() chains
 * rule 3c exists to forbid.
 */
function isInVarFallback(before) {
  let depth = 0,
    varDepth = -1,
    sawComma = false;
  for (let i = 0; i < before.length; i++) {
    if (before[i] === '(') {
      if (before.slice(Math.max(0, i - 3), i) === 'var' && varDepth === -1) {
        varDepth = depth;
        sawComma = false;
      }
      depth++;
    } else if (before[i] === ')') {
      depth--;
      if (varDepth !== -1 && depth <= varDepth) varDepth = -1;
    } else if (before[i] === ',' && varDepth !== -1 && depth === varDepth + 1) {
      sawComma = true;
    }
  }
  return varDepth !== -1 && sawComma;
}

/** Compare CSS values ignoring case, spacing, quote style and leading zeros. */
function normValue(s) {
  return s
    .trim()
    .toLowerCase()
    .replace(/'/g, '"')
    .replace(/\s+/g, ' ')
    .replace(/\s*,\s*/g, ',')
    .replace(/(^|[\s,(])\.(\d)/g, '$10.$2');
}
// Module-owned namespace: data colours, and properties set at runtime (e.g.
// Svelte's `style:--iwac-drawer-width={width}` directive).
const MODULE_PREFIX = /^--iwac-/;

const violations = [];
function flag(file, line, msg, snippet) {
  violations.push({ file: relative(ROOT, file), line, msg, snippet: snippet.trim() });
}

/**
 * Every custom property this module declares itself — collected before any
 * file is scanned, since a property declared in one file is legitimately
 * consumed from another.
 */
const moduleOwned = new Set();
function collectModuleOwned(files) {
  for (const file of files) {
    const src = readFileSync(file, 'utf8');
    let m;
    DECL.lastIndex = 0;
    while ((m = DECL.exec(src)) !== null) moduleOwned.add(m[1]);
  }
}

/** The canonical value of a token, colour or otherwise, or undefined. */
function canonicalOf(name) {
  if (!TOKENS) return undefined;
  return (
    (TOKENS.light && TOKENS.light[name]) ||
    (TOKENS.values && TOKENS.values.light && TOKENS.values.light[name])
  );
}

/** Index of the first comma at paren depth 0, or -1. */
function splitTopLevelComma(s) {
  let depth = 0;
  for (let i = 0; i < s.length; i++) {
    if (s[i] === '(') depth++;
    else if (s[i] === ')') depth--;
    else if (s[i] === ',' && depth === 0) return i;
  }
  return -1;
}

/**
 * Resolve a fallback expression the way CSS would if only the COARSER tokens
 * in it were defined: substitute each `var(--X, Y)` with X's canonical value,
 * or with Y when X is one the theme doesn't publish (module-owned).
 */
function resolveFallbackExpr(expr) {
  let out = '',
    i = 0;
  while (i < expr.length) {
    const at = expr.indexOf('var(', i);
    if (at === -1) {
      out += expr.slice(i);
      break;
    }
    out += expr.slice(i, at);
    let depth = 0,
      j = at + 3;
    for (; j < expr.length; j++) {
      if (expr[j] === '(') depth++;
      else if (expr[j] === ')') {
        depth--;
        if (depth === 0) break;
      }
    }
    if (j >= expr.length) {
      out += expr.slice(at);
      break;
    }
    const inner = expr.slice(at + 4, j);
    const comma = splitTopLevelComma(inner);
    const tok = comma === -1 ? inner.trim() : inner.slice(0, comma).trim();
    const rest = comma === -1 ? '' : inner.slice(comma + 1).trim();
    const canon = canonicalOf(tok);
    out += canon !== undefined ? canon : resolveFallbackExpr(rest);
    i = j + 1;
  }
  return out;
}

/**
 * Rule 3c — a nested fallback chain must RESOLVE to the outer token's value.
 *
 * `var(--A, var(--B, lit))` is legitimate when B is a coarser token resolving
 * to the same thing as A: a consumer holding only a partial token set — a
 * third-party Omeka theme defining `--surface` but not `--panel-bg` — still
 * lands on the right value instead of a frozen literal.
 *
 * It is a LIE when the chain resolves to something else. `var(--ink-strong,
 * var(--ink, …))` claims a headline ink degrades to a body ink;
 * `var(--panel-radius, var(--radius-lg, …))` claims an 8px panel degrades to a
 * 12px one. Resolving rather than comparing token-by-token also keeps COMPONENT
 * substitution legal, as in `var(--focus-outline, 2px solid var(--focus-color,
 * #ce4115))`.
 */
function checkFallbackChain(file, raw, n, name, fallback) {
  const want = canonicalOf(name);
  if (want === undefined) return; // module-owned, or a token we can't judge
  const got = resolveFallbackExpr(fallback);
  if (got.includes('var(')) return; // unresolvable — nothing to assert
  if (normValue(got) !== normValue(want)) {
    flag(
      file,
      n,
      `fallback chain for ${name} resolves to "${got.trim()}" ≠ canonical ${want} (tokens.json)`,
      raw,
    );
  }
}

/**
 * Rule 3b — the non-colour half of the fallback contract: a flat fallback must
 * equal `values.light[token]`, the theme's generated literal for that token.
 */
function checkNonColourFallbacks(file, raw, n) {
  if (!TOKENS || !TOKENS.values || !TOKENS.values.light) return;
  for (const { name, fallback } of varFallbacks(raw)) {
    if (fallback.includes('var(')) {
      checkFallbackChain(file, raw, n, name, fallback);
      continue;
    }
    if (fallback.startsWith('#')) continue; // rule 3 owns hex
    const canon = TOKENS.values.light[name];
    if (canon && normValue(fallback) !== normValue(canon)) {
      flag(
        file,
        n,
        `fallback "${fallback}" for ${name} ≠ canonical ${canon} (tokens.json values.light)`,
        raw,
      );
    }
  }
}

/**
 * Rule 6 — media-query widths must be one of the theme's breakpoints.
 *
 * `@media` only: a `@container` query measures its own container rather than
 * the viewport, so the viewport scale does not apply to it.
 */
function checkBreakpoints(file, raw, n) {
  if (!TOKENS || !TOKENS.breakpoints || !/@media\b/.test(raw)) return;
  const bps = Object.values(TOKENS.breakpoints).map(parseFloat);
  // `min-width` sits ON the breakpoint; `max-width` sits JUST BELOW it, so the
  // two halves of a pair never both match at the boundary pixel.
  const minOk = new Set(bps);
  const maxOk = new Set(bps.flatMap((v) => [v - 1, v - 0.02]));
  const names = Object.entries(TOKENS.breakpoints)
    .map(([k, v]) => `${k} ${v}`)
    .join(', ');
  MEDIA_WIDTH_NON_PX.lastIndex = 0;
  let u;
  while ((u = MEDIA_WIDTH_NON_PX.exec(raw)) !== null) {
    flag(
      file,
      n,
      `${u[1]}-width in ${u[2]}, not px — the breakpoint contract is published in px (${names}), and a non-px width is invisible to this rule`,
      raw,
    );
  }
  MEDIA_WIDTH.lastIndex = 0;
  let m;
  while ((m = MEDIA_WIDTH.exec(raw)) !== null) {
    const [, kind, px] = m;
    const v = parseFloat(px);
    if (kind === 'min' ? !minOk.has(v) : !maxOk.has(v)) {
      const hint =
        kind === 'max' && minOk.has(v)
          ? ` — use ${v - 1}px so it doesn't overlap min-width: ${v}px`
          : '';
      flag(
        file,
        n,
        `${kind}-width: ${px}px is not one of the theme's breakpoints (${names})${hint}`,
        raw,
      );
    }
  }
}

/** Rule 5 — every var(--…) must name a token that actually exists. */
function checkVarNames(file, raw, n) {
  if (!TOKENS || !Array.isArray(TOKENS.names)) return;
  let m;
  VAR_USE.lastIndex = 0;
  while ((m = VAR_USE.exec(raw)) !== null) {
    const name = m[1];
    if (MODULE_PREFIX.test(name) || moduleOwned.has(name) || TOKENS.names.includes(name)) {
      continue;
    }
    flag(
      file,
      n,
      `unknown token ${name} — not a theme token (tokens.json names), not module-owned (--iwac-*)`,
      raw,
    );
  }
}

function scan(file) {
  const isCss = file.endsWith('.css');
  const isSvelte = file.endsWith('.svelte');
  // Track the <style> region of a Svelte SFC so the bare-hex rule scans the
  // module's CSS but not its template markup (SVG fills, inline attrs).
  let inStyle = false;
  readFileSync(file, 'utf8')
    .split('\n')
    .forEach((raw, i) => {
      const n = i + 1;
      if (isSvelte && /<style\b/.test(raw)) inStyle = true;
      const cssContext = isCss || (isSvelte && inStyle);

      if (REMOVED_TOKEN.test(raw)) {
        flag(
          file,
          n,
          'removed token --primary-hue/--primary-sat (derive via color-mix from --primary)',
          raw,
        );
      }
      if (SRGB_MIX.test(raw)) {
        flag(file, n, 'color-mix(in srgb …) — use `in oklab`', raw);
      }
      checkVarNames(file, raw, n);
      checkBreakpoints(file, raw, n);
      if (cssContext) {
        const fs = ABS_FONT_SIZE.exec(raw);
        if (fs) {
          flag(
            file,
            n,
            `font-size: ${fs[1]} — use a --text-* token (--text-2xs is the floor)`,
            raw,
          );
        }
      }
      if (!/allow-hex/.test(raw)) {
        checkNonColourFallbacks(file, raw, n);
        // Rule 3 — fallback value must equal canonical light token.
        if (TOKENS) {
          let m;
          VAR_FALLBACK.lastIndex = 0;
          while ((m = VAR_FALLBACK.exec(raw)) !== null) {
            const canon = TOKENS.light[m[1]];
            if (canon && normHex(m[2]) !== canon.toLowerCase()) {
              flag(
                file,
                n,
                `fallback ${m[2]} for ${m[1]} ≠ canonical light ${canon} (tokens.json)`,
                raw,
              );
            }
          }
        }
        // Rule 4 — bare hex in any CSS context (a .css file, or a Svelte
        // <style> block). A var() fallback slot is fine (Rule 3 vets it);
        // a sanctioned --iwac-vis-* data colour is exempt.
        if (cssContext && !/--iwac-vis-/.test(raw)) {
          let m;
          HEX.lastIndex = 0;
          while ((m = HEX.exec(raw)) !== null) {
            const before = raw.slice(0, m.index);
            if (!isInVarFallback(before)) {
              flag(
                file,
                n,
                'bare hex outside a var() fallback (use a theme token, or mark /* allow-hex */)',
                raw,
              );
              break;
            }
          }
        }
      }

      if (isSvelte && /<\/style>/.test(raw)) inStyle = false;
    });
}

const sources = SRC_DIRS.flatMap((d) => walk(d));
collectModuleOwned(sources);
sources.forEach(scan);

if (violations.length) {
  console.error(`\n✗ theme-token guard: ${violations.length} violation(s)\n`);
  for (const v of violations) {
    console.error(`  ${v.file}:${v.line}  ${v.msg}`);
    console.error(`      ${v.snippet}`);
  }
  console.error('\nSee IWAC-theme/docs/DESIGN-SYSTEM.md. Canonical values: tokens.json');
  console.error('(regenerate with `npm run build:tokens` in IWAC-theme).\n');
  process.exit(1);
}

console.log('✓ theme-token guard: no violations');
