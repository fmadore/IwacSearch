#!/usr/bin/env node
/**
 * Writes every version declaration at once, and stamps CITATION.cff's release
 * date. This is the only supported way to bump the module.
 *
 *     npm run bump -- patch          # 3.21.1 -> 3.21.2
 *     npm run bump -- minor          # 3.21.1 -> 3.22.0
 *     npm run bump -- 3.22.0         # explicit
 *     npm run bump -- patch --date 2026-10-09
 *
 * package.json and package-lock.json are handed to `npm version`, which does
 * the semver arithmetic and keeps the lockfile's two copies in step; whatever
 * it resolves is then propagated to the declarations this repository owns
 * (scripts/lib/versions.js). So the increment keywords work without this
 * script parsing semver at all.
 *
 * A bump is not a release: the live site installs the zip release.yml builds
 * on a pushed `vX.Y.Z` tag.
 */

import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { join } from 'node:path';

import { ROOT, readAll, writeOwned, writeReleaseDate } from './lib/versions.js';

/**
 * Today, local time, as YYYY-MM-DD. Assembled from the parts rather than
 * through toLocaleDateString, whose output depends on the host's locale data.
 */
function today() {
  const now = new Date();
  const pad = (value) => String(value).padStart(2, '0');
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

const args = process.argv.slice(2);
const dateIndex = args.indexOf('--date');
const date = dateIndex === -1 ? today() : args[dateIndex + 1];
const spec = (
  dateIndex === -1
    ? args
    : args.filter((_, index) => index !== dateIndex && index !== dateIndex + 1)
)[0];

if (!spec) {
  console.error('usage: npm run bump -- <version|patch|minor|major> [--date YYYY-MM-DD]');
  process.exit(1);
}
if (!/^\d{4}-\d{2}-\d{2}$/.test(date ?? '')) {
  console.error(`--date must be an ISO date, got "${date}"`);
  process.exit(1);
}

/**
 * Run npm from this process's own npm when there is one (an `npm run bump`
 * parent sets npm_execpath), so the bump can't silently use a different npm
 * than the one that wrote the lockfile.
 */
function npm(argv) {
  const execpath = process.env.npm_execpath;
  const viaNode = execpath && execpath.endsWith('.js');
  const result = viaNode
    ? spawnSync(process.execPath, [execpath, ...argv], { cwd: ROOT, stdio: 'inherit' })
    : spawnSync(process.platform === 'win32' ? 'npm.cmd' : 'npm', argv, {
        cwd: ROOT,
        stdio: 'inherit',
        shell: process.platform === 'win32',
      });
  if (result.status !== 0) process.exit(result.status || 1);
}

// npm resolves the spec and writes package.json + both lockfile copies.
npm(['version', spec, '--no-git-tag-version', '--allow-same-version']);

const version = JSON.parse(readFileSync(join(ROOT, 'package.json'), 'utf8')).version;
const written = writeOwned(version);
writeReleaseDate(date);

console.log(`\n✓ bumped to ${version}, released ${date}`);
for (const { label, version: value } of readAll()) {
  console.log(`  ${value === version ? '✓' : '✗'} ${label.padEnd(32)} ${value}`);
}
console.log(
  `\n  written here: ${written.join(', ')}; package.json and package-lock.json by npm version.`,
);
console.log(`  Next: npm run build && git commit && git tag v${version}`);
