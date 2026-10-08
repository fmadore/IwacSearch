#!/usr/bin/env node
/**
 * MapLibre lockstep: are this module's pins IwacVisualizations' pins?
 *
 *     node scripts/check-maplibre-pins.js                     # vs IwacVisualizations main
 *     node scripts/check-maplibre-pins.js --against <file>    # vs a local iwac-assets.phtml
 *
 * Both modules load MapLibre GL from jsDelivr with Subresource Integrity, and
 * the whole point of pinning the SAME files is that a visitor moving between a
 * search map and a dashboard map downloads ~800 KB once and both modules share
 * one `window.maplibregl` (see src/svelte/lib/maplibreLoader.ts). That
 * lockstep used to be a comment — "identical to IwacVisualizations'
 * `$cdnIntegrity`" — and it was false for a release: IwacVisualizations moved
 * to 6.11 while this module still loaded 5.24, two copies of the library on
 * one site, the older one without SRI. tests/client/maplibreLoader.test.ts
 * checks the hashes' FORMAT; only a comparison with the other repository can
 * check that they are the other repository's.
 *
 * Verdicts, modelled on the theme-contract freshness check:
 *   - same version, same URLs, same hashes          → pass
 *   - this module AHEAD (a pin waiting on an unmerged
 *     IwacVisualizations upgrade)                    → reported, pass
 *   - this module BEHIND, or the same version with
 *     different files or hashes                      → fail, with the fix
 *
 * Zero dependencies (global fetch + fs), so the scheduled job needs no
 * `npm ci`. Never upgrades anything: the upgrade is a deliberate two-repo
 * change (`npm run update:sri` in IwacVisualizations, then copy the three
 * hashes here).
 */

import { readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const LOADER = 'src/svelte/lib/maplibreLoader.ts';
const VIS_RAW =
  'https://raw.githubusercontent.com/fmadore/IwacVisualizations/main/view/common/iwac-assets.phtml';
const URL_RE = /https:\/\/cdn\.jsdelivr\.net\/npm\/maplibre-gl@([^/]+)\/dist\/([^'"`\s]+)/;

/** -1 / 0 / 1 for dotted numeric versions ("6.12.0"). */
function compareVersions(a, b) {
  const pa = String(a).split('.').map(Number);
  const pb = String(b).split('.').map(Number);
  for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
    const d = (pa[i] || 0) - (pb[i] || 0);
    if (d) return d < 0 ? -1 : 1;
  }
  return 0;
}

/**
 * This module's pins, read from the loader's source: the version constant,
 * the file names under MAPLIBRE_FILES and the hash keyed to each.
 */
export function readSearchPins(source) {
  const version = source.match(/MAPLIBRE_VERSION\s*=\s*'([^']+)'/)?.[1];
  const filesBlock = source.match(/MAPLIBRE_FILES\s*=\s*\{([\s\S]*?)\}\s*as const/)?.[1] ?? '';
  const files = {};
  for (const m of filesBlock.matchAll(/(\w+):\s*`\$\{CDN\}([^`]+)`/g)) files[m[1]] = m[2];
  const pins = {};
  for (const m of source.matchAll(/\[MAPLIBRE_FILES\.(\w+)\]:\s*'(sha384-[^']+)'/g)) {
    const file = files[m[1]];
    if (file) pins[file] = m[2];
  }
  if (!version || Object.keys(files).length === 0) {
    throw new Error(`${LOADER}: could not find MAPLIBRE_VERSION / MAPLIBRE_FILES`);
  }
  return { version, pins };
}

/** IwacVisualizations' pins: every maplibre-gl entry of `$cdnIntegrity`. */
export function readVisPins(source) {
  const versions = new Set();
  const pins = {};
  for (const m of source.matchAll(
    /'(https:\/\/cdn\.jsdelivr\.net\/[^']+)'\s*=>\s*'(sha384-[^']+)'/g,
  )) {
    const parsed = m[1].match(URL_RE);
    if (!parsed) continue;
    versions.add(parsed[1]);
    pins[parsed[2]] = m[2];
  }
  if (versions.size !== 1) {
    throw new Error(
      `iwac-assets.phtml: expected one MapLibre version in $cdnIntegrity, found ${versions.size}`,
    );
  }
  return { version: [...versions][0], pins };
}

/** Pure verdict: { ok, lines }. */
export function compare(search, vis) {
  const order = compareVersions(search.version, vis.version);
  if (order > 0) {
    return {
      ok: true,
      lines: [
        `ℹ MapLibre ${search.version} here is AHEAD of IwacVisualizations main (${vis.version}) —`,
        '  expected while the matching IwacVisualizations upgrade is unmerged; this check',
        '  turns red if it is never merged and main moves elsewhere.',
      ],
    };
  }
  if (order < 0) {
    return {
      ok: false,
      lines: [
        `✗ MapLibre ${search.version} here is BEHIND IwacVisualizations main (${vis.version}).`,
        '  Two copies of the library now load on one site, and they no longer share a cache.',
        `  Fix: set MAPLIBRE_VERSION = '${vis.version}' in ${LOADER} and copy these hashes:`,
        ...Object.entries(vis.pins).map(([file, hash]) => `    ${file}  ${hash}`),
      ],
    };
  }
  const problems = [];
  const files = new Set([...Object.keys(search.pins), ...Object.keys(vis.pins)]);
  for (const file of files) {
    if (!(file in search.pins)) problems.push(`  ${file}: pinned by IwacVisualizations, not here`);
    else if (!(file in vis.pins))
      problems.push(`  ${file}: pinned here, not by IwacVisualizations`);
    else if (search.pins[file] !== vis.pins[file]) {
      problems.push(`  ${file}: ${search.pins[file]} here, ${vis.pins[file]} there`);
    }
  }
  if (problems.length > 0) {
    return {
      ok: false,
      lines: [
        `✗ MapLibre ${search.version}: same version, different pins — one of the two modules`,
        '  carries a hash the browser will refuse (or a file the other never loads):',
        ...problems,
      ],
    };
  }
  return {
    ok: true,
    lines: [
      `✓ MapLibre ${search.version}: ${files.size} files, hashes identical to IwacVisualizations main`,
    ],
  };
}

async function main() {
  const search = readSearchPins(readFileSync(join(ROOT, LOADER), 'utf8'));
  const againstIndex = process.argv.indexOf('--against');
  let visSource;
  if (againstIndex !== -1) {
    visSource = readFileSync(process.argv[againstIndex + 1], 'utf8');
  } else {
    const res = await fetch(VIS_RAW, { signal: AbortSignal.timeout(20000) });
    if (!res.ok) throw new Error(`${VIS_RAW}: HTTP ${res.status}`);
    visSource = await res.text();
  }
  const verdict = compare(search, readVisPins(visSource));
  for (const line of verdict.lines) (verdict.ok ? console.log : console.error)(line);
  return verdict.ok ? 0 : 1;
}

// exitCode, not exit(): an exit() while fetch's sockets are still closing
// aborts Node on Windows (a libuv assertion) instead of returning the verdict.
if (process.argv[1] && fileURLToPath(import.meta.url) === resolve(process.argv[1])) {
  main().then(
    (code) => {
      process.exitCode = code;
    },
    (e) => {
      console.error(`✗ could not compare MapLibre pins: ${e.message}`);
      process.exitCode = 1;
    },
  );
}
