/**
 * The version declarations, in one place.
 *
 * Five places carry this module's version, and a release that ships them out
 * of step ships a module that reports the wrong version to Omeka, to npm or to
 * a citation manager. package-lock.json is the one that actually drifted: it
 * sat at 3.13.1 across the 3.14.0, 3.15.0, 3.16.0 and 3.16.1 releases, each of
 * whose commits claimed to bump every declaration, because the only check was
 * an inline list in release.yml that ran at tag time and asserted what a human
 * remembered to edit.
 *
 * So the list is data, here, read by both sides: the writer
 * (scripts/bump-version.js) and the guard (scripts/check-versions.js, run on
 * every push by ci.yml and against the tag by release.yml). Adding a sixth
 * site means one entry in DECLARATIONS, and both pick it up. Ported from
 * IWAC-theme's scripts/lib/versions.js, which retired the same inline-list
 * pattern after two drift incidents of its own.
 */

import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

export const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');

function read(file) {
  return readFileSync(join(ROOT, file), 'utf8');
}

/**
 * Replace exactly one regex match's first capture group, failing loudly when
 * the pattern no longer matches. A silent no-op here is the whole failure mode
 * this module exists to prevent.
 */
function substitute(file, pattern, value) {
  const before = read(file);
  let hits = 0;
  const after = before.replace(pattern, (match, captured) => {
    hits += 1;
    return match.replace(captured, value);
  });
  if (hits !== 1) {
    throw new Error(`${file}: expected 1 match for ${pattern}, found ${hits}`);
  }
  writeFileSync(join(ROOT, file), after);
}

/** Read one JSON path, e.g. ['packages', '', 'version']. */
function readJsonPath(file, keys) {
  let node = JSON.parse(read(file));
  for (const key of keys) node = node?.[key];
  return typeof node === 'string' ? node : null;
}

const MODULE_INI = /^version\s*=\s*"([^"]+)"/m;
// CITATION.cff writes the version and the date unquoted here; the patterns
// accept either spelling so a hand-quoted value still reads and writes.
const CFF_VERSION = /^version:\s*"?([^"\r\n]+?)"?\s*$/m;
const CFF_DATE = /^date-released:\s*"?([^"\r\n]+?)"?\s*$/m;

/**
 * `write: null` marks a declaration the writer does not own. package.json and
 * package-lock.json are npm's to edit — `npm version` keeps the lockfile's two
 * copies in step with the manifest, and reformatting a lockfile by hand to move
 * two strings is how you lose a review.
 */
export const DECLARATIONS = [
  {
    label: 'config/module.ini',
    get: () => read('config/module.ini').match(MODULE_INI)?.[1] ?? null,
    write: (version) => substitute('config/module.ini', MODULE_INI, version),
  },
  {
    label: 'package.json',
    get: () => readJsonPath('package.json', ['version']),
    write: null,
  },
  {
    label: 'package-lock.json (root)',
    get: () => readJsonPath('package-lock.json', ['version']),
    write: null,
  },
  {
    label: 'package-lock.json (packages."")',
    get: () => readJsonPath('package-lock.json', ['packages', '', 'version']),
    write: null,
  },
  {
    label: 'CITATION.cff',
    get: () => read('CITATION.cff').match(CFF_VERSION)?.[1] ?? null,
    write: (version) => substitute('CITATION.cff', CFF_VERSION, version),
  },
];

/** Every declaration's current value, in declaration order. */
export function readAll() {
  return DECLARATIONS.map((declaration) => ({
    label: declaration.label,
    version: declaration.get(),
  }));
}

/**
 * Write the declarations this module owns. package.json and package-lock.json
 * are left to `npm version` — see the note on DECLARATIONS.
 */
export function writeOwned(version) {
  const written = [];
  for (const declaration of DECLARATIONS) {
    if (!declaration.write) continue;
    declaration.write(version);
    written.push(declaration.label);
  }
  return written;
}

/**
 * Set CITATION.cff's release date. GitHub renders it in the citation strings,
 * and it should move with `version`.
 */
export function writeReleaseDate(date) {
  substitute('CITATION.cff', CFF_DATE, date);
  return date;
}

export function readReleaseDate() {
  return read('CITATION.cff').match(CFF_DATE)?.[1] ?? null;
}
