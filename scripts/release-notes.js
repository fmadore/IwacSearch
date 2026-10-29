#!/usr/bin/env node
/**
 * Prints a release's GitHub notes: its CHANGELOG.md section, then how to
 * install and verify the zip.
 *
 *     node scripts/release-notes.js 3.27.0 > RELEASE_NOTES.md
 *
 * release.yml used to publish with `--generate-notes`, and GitHub generates
 * notes from merged pull requests. This project commits straight to main, so
 * most releases got one line, "Full Changelog: v3.26.1...v3.26.2", and the
 * reader had to open the diff to learn what changed. The changelog is the
 * record now, written before the tag (`npm run bump` starts the section and
 * `npm run check:versions` fails on every push while it is missing or empty),
 * so the notes can only be as stale as the changelog.
 *
 * Relative links in the section (`docs/operations-3.20.md`) point at the
 * repository from CHANGELOG.md, but a release page would resolve them against
 * its own URL, so they are rewritten onto the tagged tree.
 *
 * Exit 1, printing nothing, when the version has no section or an empty one.
 */

import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { CHANGELOG, changelogSection, readChangelog } from './lib/versions.js';

/** Fallback for a local preview; Actions sets GITHUB_REPOSITORY. */
const DEFAULT_REPOSITORY = 'fmadore/IwacSearch';

/**
 * Rewrite `[text](relative/path)` onto `https://github.com/<repo>/blob/<tag>/`.
 * Absolute URLs (any scheme), in-page anchors and site-absolute paths are left
 * alone.
 */
export function absoluteLinks(markdown, repository, tag) {
  return markdown.replace(
    /\]\((?![a-z][a-z\d+.-]*:|#|\/)([^)\s]+)\)/gi,
    (_, path) => `](https://github.com/${repository}/blob/${tag}/${path.replace(/^\.\//, '')})`,
  );
}

/** The notes for one version, or null when its section is missing or empty. */
export function releaseNotes(changelog, version, repository = DEFAULT_REPOSITORY) {
  const section = changelogSection(changelog, version);
  if (!section?.body) return null;
  const tag = `v${version}`;
  const zip = `IwacSearch-${tag}.zip`;
  const blob = `https://github.com/${repository}/blob/${tag}`;
  return `${absoluteLinks(section.body, repository, tag)}

## Install

Download \`${zip}\`, unzip it into Omeka S's \`modules/\` directory, then activate
**IwacSearch** (or run its **Upgrade**) under _Admin → Modules_. The zip already
carries \`vendor/\` and the compiled client bundles, so the server needs no
Composer or Node toolchain. The steps and acceptance checks for each release are
in the [deployment checklist](${blob}/docs/deploy-checklist.md).

Verify the download, and that this repository's release workflow built it:

\`\`\`sh
sha256sum -c ${zip}.sha256
gh attestation verify ${zip} --repo ${repository}
\`\`\`

**Full changelog**: ${blob}/${CHANGELOG}
`;
}

if (process.argv[1] && fileURLToPath(import.meta.url) === resolve(process.argv[1])) {
  const version = (process.argv[2] ?? '').replace(/^v/, '');
  if (!version) {
    console.error('usage: node scripts/release-notes.js <version>');
    process.exit(1);
  }
  const notes = releaseNotes(
    readChangelog(),
    version,
    process.env.GITHUB_REPOSITORY || DEFAULT_REPOSITORY,
  );
  if (notes === null) {
    console.error(
      `✗ ${CHANGELOG} has no "## [${version}]" section with entries to build the release notes from`,
    );
    process.exit(1);
  }
  process.stdout.write(notes);
}
