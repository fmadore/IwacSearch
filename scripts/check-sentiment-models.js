#!/usr/bin/env node
/**
 * Sentiment lockstep: does search index the models IwacVisualizations shows?
 *
 *     node scripts/check-sentiment-models.js                     # vs IwacVisualizations main
 *     node scripts/check-sentiment-models.js --against <file>    # vs a local sentiment-models.json
 *
 * IwacVisualizations' `config/sentiment-models.json` is the registry of the
 * sentiment annotators the site shows: its `active[]` entries name each one's
 * Omeka term stem (`claudeHaiku55`) and its Hugging Face id
 * (`claude_haiku_5_5`). This module indexes the same models from
 * `AbstractMapper::SENTIMENT_MODELS`, stem → id, and check-schema-drift.js
 * holds the schema and the client to that map. Nothing held the map to the
 * registry, so when a sixth model went active there, article pages and the
 * Sentiment Atlas showed it for weeks while search could not filter on it.
 *
 * Verdicts, modelled on check-maplibre-pins.js:
 *   - same stems, same ids                                → pass
 *   - a model active there and not indexed here, or one
 *     stem mapped to a different id                       → fail, with the fix
 *   - a model indexed here that is no longer active
 *     there (a rotation search has not followed yet)      → reported, pass:
 *     dropping fields is a schema bump the owner decides,
 *     and indexing a model the dashboards hide harms nothing
 *
 * Zero dependencies (global fetch + fs), so the scheduled job needs no
 * `npm ci`. Never edits anything: adding a model is the change described in
 * AbstractMapper's docblock (a row, three schema fields and the bump, labels).
 */

import { readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const MAPPER = 'src/Indexer/Mapper/AbstractMapper.php';
const VIS_RAW =
  'https://raw.githubusercontent.com/fmadore/IwacVisualizations/main/config/sentiment-models.json';

/** This module's models, read from the mapper's SENTIMENT_MODELS constant. */
export function readSearchModels(source) {
  const block = source.match(/SENTIMENT_MODELS\s*=\s*\[([\s\S]*?)\];/)?.[1];
  if (!block) throw new Error(`${MAPPER}: could not find SENTIMENT_MODELS`);
  const models = {};
  for (const m of block.matchAll(/'(\w+)'\s*=>\s*'(\w+)'/g)) models[m[1]] = m[2];
  if (Object.keys(models).length === 0) {
    throw new Error(`${MAPPER}: SENTIMENT_MODELS has no 'stem' => 'id' rows`);
  }
  return models;
}

/** IwacVisualizations' active models, stem → id, from the registry JSON. */
export function readVisModels(json) {
  const registry = typeof json === 'string' ? JSON.parse(json) : json;
  if (!Array.isArray(registry?.active)) {
    throw new Error('sentiment-models.json: no active[] list');
  }
  const models = {};
  for (const entry of registry.active) {
    if (typeof entry?.stem !== 'string' || typeof entry?.id !== 'string') {
      throw new Error(
        `sentiment-models.json: active entry without stem/id: ${JSON.stringify(entry)}`,
      );
    }
    models[entry.stem] = entry.id;
  }
  return models;
}

/** Pure verdict: { ok, lines }. */
export function compare(search, vis) {
  const missing = Object.keys(vis).filter((stem) => !(stem in search));
  const remapped = Object.keys(vis).filter((stem) => stem in search && search[stem] !== vis[stem]);
  const retired = Object.keys(search).filter((stem) => !(stem in vis));
  const lines = [];
  for (const stem of missing) {
    lines.push(
      `✗ ${vis[stem]} (iwac:${stem}*) is active in IwacVisualizations but not indexed here.`,
      `  Fix: add '${stem}' => '${vis[stem]}' to SENTIMENT_MODELS in ${MAPPER}, its three`,
      '  fields to data/schema.yaml with a collection bump, and its labels to i18n.ts',
      '  (npm run lint:drift lists what is still missing); then reindex.',
    );
  }
  for (const stem of remapped) {
    lines.push(
      `✗ iwac:${stem}* is ${search[stem]} here but ${vis[stem]} in IwacVisualizations: one of`,
      '  the two names the wrong model. Never repoint a published field prefix; fix the registry',
      '  or index the model under its own new prefix.',
    );
  }
  for (const stem of retired) {
    lines.push(
      `ℹ ${search[stem]} (iwac:${stem}*) is indexed here but no longer active in IwacVisualizations.`,
      '  Harmless; drop it with the next schema bump if the retirement is final.',
    );
  }
  const ok = missing.length === 0 && remapped.length === 0;
  if (ok) {
    const active = Object.keys(vis).length;
    lines.unshift(
      `✓ sentiment models: all ${active} active in IwacVisualizations are indexed here`,
    );
  }
  return { ok, lines };
}

async function main() {
  const search = readSearchModels(readFileSync(join(ROOT, MAPPER), 'utf8'));
  const againstIndex = process.argv.indexOf('--against');
  let visSource;
  if (againstIndex !== -1) {
    visSource = readFileSync(process.argv[againstIndex + 1], 'utf8');
  } else {
    const res = await fetch(VIS_RAW, { signal: AbortSignal.timeout(20000) });
    if (!res.ok) throw new Error(`${VIS_RAW}: HTTP ${res.status}`);
    visSource = await res.text();
  }
  const verdict = compare(search, readVisModels(visSource));
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
      console.error(`✗ could not compare sentiment models: ${e.message}`);
      process.exitCode = 1;
    },
  );
}
