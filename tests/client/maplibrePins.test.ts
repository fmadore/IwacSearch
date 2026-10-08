import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
// @ts-expect-error — a plain-JS Node script with no type declarations.
import { compare, readSearchPins, readVisPins } from '../../scripts/check-maplibre-pins.js';
import { MAPLIBRE_FILES, MAPLIBRE_INTEGRITY } from '../../src/svelte/lib/maplibreLoader';

/**
 * scripts/check-maplibre-pins.js — the weekly MapLibre lockstep check.
 *
 * It reads the loader's SOURCE with regexes, so the first case is the one that
 * matters day to day: a reformat of maplibreLoader.ts that the parser no longer
 * understands must fail here, on the PR, not silently in a scheduled job.
 */

type Pins = { version: string; pins: Record<string, string> };

const LOADER = readFileSync('src/svelte/lib/maplibreLoader.ts', 'utf8');

function phtml(version: string, pins: Record<string, string>): string {
  const rows = Object.entries(pins)
    .map(
      ([file, hash]) =>
        `  'https://cdn.jsdelivr.net/npm/maplibre-gl@${version}/dist/${file}' => '${hash}',`,
    )
    .join('\n');
  return `$cdnIntegrity = [\n  'https://cdn.jsdelivr.net/npm/echarts@6.1.0/dist/echarts.min.js' => 'sha384-e',\n${rows}\n];`;
}

describe('readSearchPins', () => {
  it('reads exactly what the loader exports', () => {
    const search = readSearchPins(LOADER) as Pins;
    const fromModule = Object.fromEntries(
      Object.values(MAPLIBRE_FILES).map((url) => [url.split('/').pop(), MAPLIBRE_INTEGRITY[url]]),
    );
    expect(search.pins).toEqual(fromModule);
    expect(MAPLIBRE_FILES.module).toContain(`maplibre-gl@${search.version}/`);
  });
});

describe('compare', () => {
  const search = readSearchPins(LOADER) as Pins;

  it('passes when IwacVisualizations pins the same files with the same hashes', () => {
    const vis = readVisPins(phtml(search.version, search.pins)) as Pins;
    expect(compare(search, vis).ok).toBe(true);
  });

  it('fails when this module is behind, naming the hashes to copy', () => {
    const vis = readVisPins(phtml('99.0.0', { 'maplibre-gl.mjs': 'sha384-new' })) as Pins;
    const verdict = compare(search, vis);
    expect(verdict.ok).toBe(false);
    expect(verdict.lines.join('\n')).toContain('sha384-new');
  });

  it('reports but passes when this module is ahead of an unmerged upgrade', () => {
    const vis = readVisPins(phtml('0.1.0', search.pins)) as Pins;
    expect(compare(search, vis).ok).toBe(true);
  });

  it('fails on the same version with a different hash', () => {
    const tampered = { ...search.pins, 'maplibre-gl.css': 'sha384-other' };
    const vis = readVisPins(phtml(search.version, tampered)) as Pins;
    expect(compare(search, vis).ok).toBe(false);
  });
});
