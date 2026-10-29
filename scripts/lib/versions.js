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
 *
 * CHANGELOG.md restates the version too, as the heading of the section the
 * release notes are built from (scripts/release-notes.js). It is not a
 * DECLARATION — it holds every version, not one — so it has its own reader
 * and writer at the end of this file, shared the same way: the bump moves
 * `## [Unreleased]` under the new heading, and the guard fails when the
 * current version has no section, or an empty one.
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

// ── CHANGELOG.md ────────────────────────────────────────────────────────────

export const CHANGELOG = 'CHANGELOG.md';

/** `## [Unreleased]`, alone on its line. */
const UNRELEASED = /^## \[Unreleased\][^\S\n]*$/m;

function escapeRegExp(text) {
  return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * A version's heading line: `## [3.27.0]`, or `## [3.27.0] - 2026-10-29` as
 * the bump writes it. Groups: the bracketed part, the date when there is one,
 * and whatever follows on the line (a `[YANKED]` marker, say). The closing
 * bracket is part of the match, so 3.2.0 can never claim 3.2.0.1's section.
 */
function headingPattern(version) {
  return new RegExp(
    `^(## \\[${escapeRegExp(version)}\\])(?: - (\\d{4}-\\d{2}-\\d{2}))?([^\\n]*)$`,
    'm',
  );
}

/**
 * One version's section: its heading's date (null without one) and its body,
 * everything up to the next level-2 heading, trimmed, so a heading with
 * nothing under it reads as ''. Null when the version has no heading.
 *
 * The body ends at ANY `## ` line, not only a bracketed one, so prose placed
 * between two releases can never leak into the notes of the one above it.
 */
export function changelogSection(text, version) {
  const heading = headingPattern(version).exec(text);
  if (!heading) return null;
  const rest = text.slice(heading.index + heading[0].length);
  const next = /^## /m.exec(rest);
  return {
    date: heading[2] ?? null,
    body: (next ? rest.slice(0, next.index) : rest).trim(),
  };
}

/**
 * What `npm run bump` does to the changelog, as a pure function of its text.
 *
 * A version with no section yet gets a `## [version] - date` heading directly
 * under `## [Unreleased]`: everything that was unreleased now sits under the
 * new version, and Unreleased starts again empty. A version that already has
 * one (a re-stamp, `npm run bump -- 3.27.0 --date …` on a version already
 * bumped, as 3.22.0 and 3.23.0 were) only has its heading's date moved, as
 * CITATION.cff's is, so the two never disagree.
 */
export function promoteUnreleased(text, version, date) {
  const existing = headingPattern(version).exec(text);
  if (existing) {
    const [line, bracketed, , tail] = existing;
    return (
      text.slice(0, existing.index) +
      `${bracketed} - ${date}${tail}` +
      text.slice(existing.index + line.length)
    );
  }
  const unreleased = UNRELEASED.exec(text);
  if (!unreleased) {
    throw new Error(`${CHANGELOG}: no "## [Unreleased]" heading to start ${version} from`);
  }
  const at = unreleased.index + unreleased[0].length;
  return `${text.slice(0, at)}\n\n## [${version}] - ${date}${text.slice(at)}`;
}

export function readChangelog() {
  return read(CHANGELOG);
}

/** Apply promoteUnreleased() to CHANGELOG.md; returns the version's section. */
export function writeChangelogSection(version, date) {
  const after = promoteUnreleased(readChangelog(), version, date);
  writeFileSync(join(ROOT, CHANGELOG), after);
  return changelogSection(after, version);
}
