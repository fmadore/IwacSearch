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
 * Scans hand-written sources under src/ (*.svelte, *.css, *.ts). The
 * canonical values come from `tokens.json`, synced from the theme by
 * IWAC-theme/scripts/build-tokens.js — the SINGLE SOURCE OF TRUTH.
 *
 * Rules:
 *   1. No removed tokens — `--primary-hue` / `--primary-sat` (theme v2.0.0).
 *   2. No `color-mix(in srgb …)` — the contract is `in oklab`.
 *   3. Every `var(--token, #hex)` fallback must EQUAL the token's canonical
 *      light value (tokens.json). A stale fallback is a competing variable
 *      even if it only paints when the theme is absent.
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
const SRC_DIR = join(ROOT, 'src');
const TOKENS_PATH = join(ROOT, 'tokens.json');
const EXTS = ['.svelte', '.css', '.ts'];

function walk(dir, out = []) {
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
      if (!/allow-hex/.test(raw)) {
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
            const isFallback =
              /,\s*$/.test(before) &&
              (before.match(/var\(/g) || []).length > (before.match(/\)/g) || []).length;
            if (!isFallback) {
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

const sources = walk(SRC_DIR);
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
