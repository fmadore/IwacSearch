#!/usr/bin/env node
/**
 * Stacked-docblock guard for the PHP sources.
 *
 * PHP attaches only the LAST docblock before a symbol to it. Refactors had
 * repeatedly left a docblock stranded above another one — a method's real
 * documentation sitting on its neighbour, or a superseded copy above the
 * current one — and nothing flagged it (see docs/module-review-2026-09-27.md
 * §E7). Two docblocks separated only by blank lines are always one of those
 * mistakes in this codebase, so this check fails on them.
 *
 * Run: node scripts/check-docblocks.js  (wired into `npm run lint`)
 */

import { readdirSync, readFileSync, statSync } from 'node:fs';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');

function phpFiles(dir) {
  const out = [];
  for (const name of readdirSync(dir)) {
    const path = join(dir, name);
    if (statSync(path).isDirectory()) {
      if (name !== 'svelte' && name !== 'svelte-shared') out.push(...phpFiles(path));
    } else if (name.endsWith('.php')) {
      out.push(path);
    }
  }
  return out;
}

const files = [
  ...phpFiles(join(root, 'src')),
  ...phpFiles(join(root, 'cli')),
  ...phpFiles(join(root, 'config')),
  join(root, 'Module.php'),
];

const problems = [];
for (const file of files) {
  const lines = readFileSync(file, 'utf8').split(/\r?\n/);
  lines.forEach((line, i) => {
    const trimmed = line.trim();
    const endsDocblock = trimmed === '*/' || (trimmed.startsWith('/**') && trimmed.endsWith('*/'));
    if (!endsDocblock) return;
    let next = i + 1;
    while (next < lines.length && lines[next].trim() === '') next += 1;
    if (next < lines.length && lines[next].trim().startsWith('/**')) {
      problems.push(`${relative(root, file)}:${next + 1}`);
    }
  });
}

if (problems.length > 0) {
  console.error('❌ stacked docblocks (only the last one attaches to the symbol):');
  for (const p of problems) console.error(`   ${p}`);
  process.exit(1);
}
console.log(`✅ docblocks: no stacked docblocks in ${files.length} PHP files`);
