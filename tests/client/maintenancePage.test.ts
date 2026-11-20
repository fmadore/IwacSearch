import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
// @ts-expect-error — a plain-JS Node script with no type declarations.
import * as buildPot from '../../scripts/build-pot.js';

const { extract } = buildPot;

/**
 * The admin Maintenance page (view/iwac-search/admin/maintenance/index.phtml)
 * and the flash messages its controller sends. Strings reached the page
 * through arrays and job descriptions with no `// @translate` marker and no
 * literal translate call, so build-pot never saw them and the French admin
 * read them in English. Its section headings also started at h3 under the
 * page's h1, with the page's only h2 at the bottom.
 */

const VIEW = 'view/iwac-search/admin/maintenance/index.phtml';
const CONTROLLER = 'src/Controller/Admin/MaintenanceController.php';

type Extracted = { msgids: string[]; problems: string[] };
const msgidsOf = (file: string): string[] => {
  const { msgids, problems } = extract(readFileSync(file, 'utf8'), file) as Extracted;
  expect(problems).toEqual([]);
  return msgids;
};

describe('the maintenance page', () => {
  it('marks every label it translates through a variable', () => {
    expect(msgidsOf(VIEW)).toEqual(
      expect.arrayContaining([
        'live',
        'previous (rollback target)',
        'retired (removed by the next cleanup)',
        'building, or left by an interrupted rebuild',
        'not recorded (built before 3.27)',
        'Retry pending changes',
        'Clean up old index generations',
      ]),
    );
  });

  it('translates every job description and the flash message around it', () => {
    expect(msgidsOf(CONTROLLER)).toEqual(
      expect.arrayContaining([
        expect.stringMatching(/^Bulk reindex queued — /),
        'The smaller-corpus check is off for this run.',
        expect.stringMatching(/^Stopwords sync queued — /),
        expect.stringMatching(/^Synonyms sync queued — /),
        expect.stringMatching(/^Analytics provisioning queued — /),
        'Pending index changes queued for retry.',
        expect.stringMatching(/^Old index generations queued for cleanup;/),
        '%1$s Track progress: %2$sjob #%3$d%4$s',
      ]),
    );
  });

  it('nests its headings under the page title without skipping a level', () => {
    // pageTitle() prints the h1; every heading after it may go at most one
    // level deeper than the one before.
    const levels = [...readFileSync(VIEW, 'utf8').matchAll(/<h([1-6])\b/g)].map((m) =>
      Number(m[1]),
    );
    expect(levels[0]).toBe(2);
    let previous = 1;
    for (const level of levels) {
      expect(level).toBeLessThanOrEqual(previous + 1);
      previous = level;
    }
  });
});
