import { describe, expect, it } from 'vitest';
// @ts-expect-error — a plain-JS Node script with no type declarations.
import * as buildPot from '../../scripts/build-pot.js';

// One import line, so the directive above covers it (a multi-line import
// reports the missing declaration on its `from` line, out of the directive's reach).
const { checkCatalogue, extract, placeholders, renderPot, unquotePhp } = buildPot;

/**
 * scripts/build-pot.js — the PHP-side msgid extractor behind `npm run lint:pot`.
 *
 * It reads PHP with regexes, so what matters is that it neither misses a
 * string a visitor or editor sees nor invents one: a marker or call it cannot
 * pair with a literal must fail the lint, not drop the string silently.
 */

type Extracted = { msgids: string[]; problems: string[] };
const run = (source: string): Extracted => extract(source, 'x.php') as Extracted;

describe('extract', () => {
  it('reads the literal argument of every translate call form', () => {
    const { msgids, problems } = run(
      [
        "<?= $esc($t('Scope')) ?>",
        "<?= $this->translate('Search requires JavaScript.') ?>",
        "<?= $escAttr($t('Entity index')) ?>",
        '<?php echo $translate("Index status"); ?>',
        "sprintf($t('Showing the %d most common values.'), $n)",
      ].join('\n'),
    );
    expect(problems).toEqual([]);
    expect(msgids).toEqual([
      'Scope',
      'Search requires JavaScript.',
      'Entity index',
      'Index status',
      'Showing the %d most common values.',
    ]);
  });

  it('reads a marked literal, as a value or a return or an argument', () => {
    const { msgids, problems } = run(
      [
        "        'type_s' => 'Type', // @translate",
        "        return 'IWAC Search'; // @translate",
        "        ['alias' => $a, 'label' => 'Content (articles)'], // @translate",
        "        $this->messenger()->addError('Invalid form submission.'); // @translate",
      ].join('\n'),
    );
    expect(problems).toEqual([]);
    expect(msgids).toEqual([
      'Type',
      'IWAC Search',
      'Content (articles)',
      'Invalid form submission.',
    ]);
  });

  it('ignores a translate call on a variable: its strings are marked where defined', () => {
    expect(run('<?= $esc($t($label)) ?>')).toEqual({ msgids: [], problems: [] });
  });

  it('refuses a marker on a variable rather than taking its array key', () => {
    const { msgids, problems } = run("    label: $country['name'], // @translate");
    expect(msgids).toEqual([]);
    expect(problems).toEqual(['x.php:1: // @translate with no string literal before it']);
  });

  it('refuses a concatenated msgid', () => {
    const { problems } = run("\n$t('Found ' . $n)");
    expect(problems).toEqual(['x.php:2: concatenated msgid — translate a whole literal']);
  });

  it('lists each msgid once, in order of appearance', () => {
    expect(run("$t('B'); $t('A'); $t('B');").msgids).toEqual(['B', 'A']);
  });
});

describe('unquotePhp', () => {
  it('unescapes single-quoted literals the way PHP does', () => {
    expect(unquotePhp(String.raw`'Côte d\'Ivoire \\ \n'`)).toBe(String.raw`Côte d'Ivoire \ \n`);
  });

  it('unescapes double-quoted literals the way PHP does', () => {
    expect(unquotePhp(String.raw`"say \"hi\"\n\u{2014}"`)).toBe('say "hi"\n—');
  });
});

describe('placeholders', () => {
  it('finds sprintf conversions, positional ones included, and skips %%', () => {
    expect(placeholders('%1$s of %2$s, %d%% done')).toEqual(['%1$s', '%2$s', '%d']);
  });
});

describe('checkCatalogue', () => {
  it('reports a missing, an empty and a placeholder-breaking translation', () => {
    const catalogue = new Map([
      ['', 'header'],
      ['Empty', ' '],
      ['%d queued', 'en attente'],
      ['Gone', 'Parti'],
    ]);
    const { errors, obsolete } = checkCatalogue(['Missing', 'Empty', '%d queued'], catalogue) as {
      errors: string[];
      obsolete: string[];
    };
    expect(errors).toEqual([
      'missing: "Missing"',
      'untranslated (empty msgstr): "Empty"',
      'placeholders differ (%d vs none): "%d queued"',
    ]);
    expect(obsolete).toEqual(['Gone']);
  });

  it('accepts a translation that reorders nothing but the words', () => {
    const catalogue = new Map([['%1$s of %2$s', '%2$s : %1$s']]);
    expect(checkCatalogue(['%1$s of %2$s'], catalogue)).toEqual({ errors: [], obsolete: [] });
  });
});

describe('renderPot', () => {
  it('escapes msgids, flags php-format and references files without lines', () => {
    const pot = renderPot(
      new Map([
        ['Say "hi"', ['view/a.phtml']],
        ['%d items', ['src/B.php', 'view/c.phtml']],
      ]),
    ) as string;
    expect(pot).toContain('#: view/a.phtml\nmsgid "Say \\"hi\\""\nmsgstr ""\n');
    expect(pot).toContain('#: src/B.php view/c.phtml\n#, php-format\nmsgid "%d items"\n');
  });
});
