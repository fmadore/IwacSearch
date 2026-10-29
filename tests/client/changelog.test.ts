import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
// @ts-expect-error — a plain-JS Node module with no type declarations.
import { changelogSection, promoteUnreleased } from '../../scripts/lib/versions.js';
// @ts-expect-error — a plain-JS Node script with no type declarations.
import { absoluteLinks, releaseNotes } from '../../scripts/release-notes.js';

/**
 * CHANGELOG.md as data: the section `npm run check:versions` requires for the
 * current version, the one `npm run bump` starts from [Unreleased], and the
 * one release.yml publishes as the GitHub release notes
 * (scripts/release-notes.js). All three read it through these functions, so a
 * heading they disagree about can only be a bug here.
 */

const CHANGELOG = `# Changelog

Intro.

## [Unreleased]

### Added

- A new thing.

## [3.2.0] - 2026-10-02

### Fixed

- See [the guide](docs/operations.md) and [IWAC](https://islam.zmo.de/).

## [3.2.0.1] - 2026-10-01

- Never part of 3.2.0.

## [3.1.0]

## [3.0.0] - 2026-09-01 [YANKED]

- Pulled.
`;

describe('changelogSection', () => {
  it('reads a dated section up to the next heading', () => {
    expect(changelogSection(CHANGELOG, '3.2.0')).toEqual({
      date: '2026-10-02',
      body: '### Fixed\n\n- See [the guide](docs/operations.md) and [IWAC](https://islam.zmo.de/).',
    });
  });

  it('does not let a version claim a longer one that starts with it', () => {
    expect(changelogSection(CHANGELOG, '3.2.0.1')?.body).toBe('- Never part of 3.2.0.');
    expect(changelogSection(CHANGELOG, '3.2')).toBeNull();
  });

  it('reads an undated, empty section as such', () => {
    expect(changelogSection(CHANGELOG, '3.1.0')).toEqual({ date: null, body: '' });
  });

  it('keeps the date of a heading with a marker after it', () => {
    expect(changelogSection(CHANGELOG, '3.0.0')).toEqual({ date: '2026-09-01', body: '- Pulled.' });
  });

  it('is null for a version with no heading', () => {
    expect(changelogSection(CHANGELOG, '9.9.9')).toBeNull();
  });

  it('treats the dots of a version literally', () => {
    expect(changelogSection('## [3x2y0] - 2026-01-01\n\n- No.\n', '3.2.0')).toBeNull();
  });
});

describe('promoteUnreleased', () => {
  it('moves the unreleased entries under a new dated heading and empties Unreleased', () => {
    const after = promoteUnreleased(CHANGELOG, '3.3.0', '2026-10-29');
    expect(changelogSection(after, 'Unreleased')).toEqual({ date: null, body: '' });
    expect(changelogSection(after, '3.3.0')).toEqual({
      date: '2026-10-29',
      body: '### Added\n\n- A new thing.',
    });
    // Everything else is untouched.
    expect(after.replace('## [3.3.0] - 2026-10-29\n\n', '')).toBe(CHANGELOG);
  });

  it('only re-stamps the date of a version that already has a section', () => {
    const once = promoteUnreleased(CHANGELOG, '3.3.0', '2026-10-29');
    const again = promoteUnreleased(once, '3.3.0', '2026-10-30');
    expect(changelogSection(again, '3.3.0')?.date).toBe('2026-10-30');
    expect(again.replace('2026-10-30', '2026-10-29')).toBe(once);
  });

  it('dates an undated heading, and keeps what follows a dated one', () => {
    expect(changelogSection(promoteUnreleased(CHANGELOG, '3.1.0', '2026-09-15'), '3.1.0')).toEqual({
      date: '2026-09-15',
      body: '',
    });
    expect(promoteUnreleased(CHANGELOG, '3.0.0', '2026-09-02')).toContain(
      '## [3.0.0] - 2026-09-02 [YANKED]\n',
    );
  });

  it('refuses a changelog with no Unreleased heading', () => {
    expect(() => promoteUnreleased('# Changelog\n', '1.0.0', '2026-10-29')).toThrow(/Unreleased/);
  });
});

describe('releaseNotes', () => {
  it('is the section, with its relative links on the tagged tree, then install steps', () => {
    const notes = releaseNotes(CHANGELOG, '3.2.0', 'owner/repo') as string;
    expect(notes.startsWith('### Fixed\n\n- See [the guide](')).toBe(true);
    expect(notes).toContain(
      '[the guide](https://github.com/owner/repo/blob/v3.2.0/docs/operations.md)',
    );
    expect(notes).toContain('[IWAC](https://islam.zmo.de/)');
    expect(notes).toContain('sha256sum -c IwacSearch-v3.2.0.zip.sha256');
    expect(notes).toContain('gh attestation verify IwacSearch-v3.2.0.zip --repo owner/repo');
    expect(notes).toContain('https://github.com/owner/repo/blob/v3.2.0/CHANGELOG.md');
  });

  it('is null for a missing or empty section', () => {
    expect(releaseNotes(CHANGELOG, '9.9.9')).toBeNull();
    expect(releaseNotes(CHANGELOG, '3.1.0')).toBeNull();
  });
});

describe('absoluteLinks', () => {
  it('leaves absolute, anchor and site-absolute links alone', () => {
    const text = '[a](https://x.org/) [b](mailto:a@b.c) [c](#top) [d](/abs) [e](./docs/x.md)';
    expect(absoluteLinks(text, 'o/r', 'v1.0.0')).toBe(
      '[a](https://x.org/) [b](mailto:a@b.c) [c](#top) [d](/abs) [e](https://github.com/o/r/blob/v1.0.0/docs/x.md)',
    );
  });
});

describe('CHANGELOG.md', () => {
  const text = readFileSync('CHANGELOG.md', 'utf8');
  const headings = [...text.matchAll(/^## \[([^\]]+)\](.*)$/gm)];

  it('opens with Unreleased, then dated releases, newest first', () => {
    expect(headings[0]?.[1]).toBe('Unreleased');
    const releases = headings.slice(1);
    expect(releases.length).toBeGreaterThan(0);
    for (const [line, , rest] of releases) {
      expect(rest, line).toMatch(/^ - \d{4}-\d{2}-\d{2}$/);
    }
    const versions = releases.map(([, version]) => (version as string).split('.').map(Number));
    for (let i = 1; i < versions.length; i++) {
      const [a = [], b = []] = [versions[i - 1], versions[i]];
      const order = a.findIndex((part, j) => part !== b[j]);
      expect(order === -1 ? 0 : Math.sign((a[order] ?? 0) - (b[order] ?? 0)), `${a} > ${b}`).toBe(
        1,
      );
    }
  });

  it('has notes for the current version', () => {
    const { version } = JSON.parse(readFileSync('package.json', 'utf8')) as { version: string };
    expect(releaseNotes(text, version)).not.toBeNull();
  });
});
