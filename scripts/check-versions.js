#!/usr/bin/env node
/**
 * Asserts that every version declaration agrees — and, given an argument, that
 * they agree with it (the release workflow passes the tag) — and that
 * CHANGELOG.md has a section for that version, dated as CITATION.cff is.
 *
 *     node scripts/check-versions.js          # the declarations agree
 *     node scripts/check-versions.js 3.22.0   # ...and are this version
 *
 * Run on every push by .github/workflows/ci.yml, so drift is caught on the
 * commit that introduces it rather than at the next release — or, as with
 * package-lock.json, four releases later. The list of sites is
 * scripts/lib/versions.js, shared with the writer (`npm run bump`).
 *
 * The changelog is checked here rather than only when the release builds its
 * notes from it (scripts/release-notes.js): a missing or empty section would
 * otherwise surface at tag time, after the version was bumped, committed and
 * pushed. DRESearch's check-version-consistency.mjs does the same.
 */

import {
  CHANGELOG,
  changelogSection,
  readAll,
  readChangelog,
  readReleaseDate,
} from './lib/versions.js';

const expected = process.argv[2] ? process.argv[2].replace(/^v/, '') : null;
const declarations = readAll();
const problems = [];

for (const declaration of declarations.filter((d) => d.version === null)) {
  problems.push(
    `${declaration.label}: no version declaration found — the pattern in scripts/lib/versions.js no longer matches`,
  );
}

const found = declarations.filter((d) => d.version !== null);
const reference = expected ?? found[0]?.version;

for (const declaration of found) {
  if (declaration.version !== reference) {
    problems.push(`${declaration.label}: ${declaration.version} (expected ${reference})`);
  }
}

const date = readReleaseDate();
if (!/^\d{4}-\d{2}-\d{2}$/.test(date ?? '')) {
  problems.push(`CITATION.cff: date-released is "${date}", not an ISO date`);
}

const section = reference ? changelogSection(readChangelog(), reference) : null;
if (reference && !section) {
  problems.push(
    `${CHANGELOG}: no "## [${reference}]" section — the release notes are built from it`,
  );
} else if (section) {
  if (!section.body) {
    problems.push(
      `${CHANGELOG}: the [${reference}] section is empty — write the release's changes under it`,
    );
  }
  if (section.date !== date) {
    problems.push(
      `${CHANGELOG}: [${reference}] is dated ${section.date ?? '(no date)'}, CITATION.cff date-released ${date}`,
    );
  }
}

if (problems.length > 0) {
  console.error(`✗ version declarations disagree${expected ? ` with tag ${expected}` : ''}:\n`);
  for (const problem of problems) console.error(`  - ${problem}`);
  console.error(
    '\n  Fix with:  npm run bump -- <version>' +
      `\n  It moves ${CHANGELOG}'s [Unreleased] entries under the version's heading and dates it;` +
      '\n  an empty section is filled by hand, under that heading.',
  );
  process.exit(1);
}

const suffix = expected ? ` (matches tag v${expected})` : '';
console.log(
  `✓ version declarations agree: ${reference}${suffix} — ${declarations.length} sites + ${CHANGELOG}, released ${date}`,
);
