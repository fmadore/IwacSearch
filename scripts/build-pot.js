#!/usr/bin/env node
/**
 * Extract the PHP side's translatable strings into language/template.pot, and
 * check that language/fr.po translates every one of them.
 *
 *     node scripts/build-pot.js           # write language/template.pot
 *     node scripts/build-pot.js --check   # fail if template.pot is stale, or
 *                                         # fr.po misses or empties a msgid
 *                                         # (part of `npm run lint`)
 *
 * Sources: Module.php, config/, src/ (minus the Svelte client, which carries
 * its own FR/EN tables in src/svelte/lib/i18n.ts) and view/. A msgid is
 *
 *   - the literal first argument of translate() / ->translate() / $t() /
 *     $translate() — `$escAttr($t('…'))` included, since $t( is the call;
 *   - a string literal followed, on the same line, by a `// @translate`
 *     marker: catalog labels that reach translate() through a variable
 *     (`'label' => 'Country', // @translate`), Omeka's convention.
 *
 * Both rules are enforced, not hoped for: a marker with no literal before it,
 * and a translate call whose literal is concatenated (`$t('a' . $b)`), are
 * errors, because either way the string a visitor sees is not the one the
 * catalogue holds. A translate call on a variable is fine and is not
 * reported: its strings are marked where they are defined.
 *
 * References carry the file but not the line (`#: view/…/form.phtml`), so
 * moving code does not make the template stale; only adding, removing or
 * rewording a string does. Order is by file path, then first appearance.
 *
 * The French check also compares sprintf placeholders: a msgstr that drops
 * a `%d` silently loses a number, and one that adds a placeholder throws
 * an ArgumentCountError at render time.
 */

import { readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs';
import { dirname, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { parsePo } from './build-mo.js';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const POT = join(ROOT, 'language', 'template.pot');
const PO = join(ROOT, 'language', 'fr.po');

/** A PHP string literal: single-quoted, or double-quoted without interpolation. */
const LITERAL = String.raw`('(?:[^'\\]|\\.)*'|"(?:[^"\\$]|\\.)*")`;
const CALL = String.raw`(?:->translate|\btranslate|\$t|\$translate)\(\s*`;
const CALL_RE = new RegExp(`${CALL}${LITERAL}\\s*([,)]|\\.)?`, 'g');
const MARKED_RE = new RegExp(`${LITERAL}[\\s,;)\\]]*//\\s*@translate\\b`, 'g');
const MARKER_RE = /\/\/\s*@translate\b/g;

/** The value of a PHP string literal, quotes included in `quoted`. */
export function unquotePhp(quoted) {
  const body = quoted.slice(1, -1);
  if (quoted[0] === "'") {
    return body.replace(/\\([\\'])/g, '$1');
  }
  const simple = { n: '\n', t: '\t', r: '\r', v: '\v', e: '\x1b', f: '\f', '\\': '\\', '"': '"' };
  return body.replace(/\\(u\{[0-9a-fA-F]+\}|x[0-9a-fA-F]{1,2}|[0-7]{1,3}|.)/g, (all, esc) => {
    if (esc in simple) return simple[esc];
    if (esc[0] === 'u') return String.fromCodePoint(parseInt(esc.slice(2, -1), 16));
    if (esc[0] === 'x') return String.fromCharCode(parseInt(esc.slice(1), 16));
    if (/^[0-7]/.test(esc)) return String.fromCharCode(parseInt(esc, 8));
    return all; // PHP keeps an unknown escape as written.
  });
}

const lineOf = (source, offset) => source.slice(0, offset).split('\n').length;

/**
 * The msgids of one source file, in order of appearance, plus what makes the
 * file unextractable.
 *
 * @param {string} source
 * @param {string} file path for messages
 * @returns {{ msgids: string[], problems: string[] }}
 */
export function extract(source, file) {
  const found = [];
  const problems = [];
  for (const m of source.matchAll(CALL_RE)) {
    if (m[2] === '.') {
      problems.push(
        `${file}:${lineOf(source, m.index)}: concatenated msgid — translate a whole literal`,
      );
      continue;
    }
    found.push({ at: m.index, msgid: unquotePhp(m[1]) });
  }
  const marked = new Set();
  for (const m of source.matchAll(MARKED_RE)) {
    // `$country['name'], // @translate` marks a variable, not the index
    // literal inside it: a literal opened by `[` is a key, never a label.
    if (/\[\s*$/.test(source.slice(Math.max(0, m.index - 16), m.index))) continue;
    found.push({ at: m.index, msgid: unquotePhp(m[1]) });
    marked.add(m.index + m[0].lastIndexOf('//'));
  }
  for (const m of source.matchAll(MARKER_RE)) {
    if (!marked.has(m.index)) {
      problems.push(
        `${file}:${lineOf(source, m.index)}: // @translate with no string literal before it`,
      );
    }
  }
  const msgids = found
    .sort((a, b) => a.at - b.at)
    .map((f) => f.msgid)
    .filter((id) => id.trim() !== '');
  return { msgids: [...new Set(msgids)], problems };
}

/** sprintf conversions in a string, sorted (`%%` is not one). */
export function placeholders(text) {
  return [...text.matchAll(/%(?:\d+\$)?[-+ 0#']*\d*(?:\.\d+)?([bcdeEfFgGosuxX%])/g)]
    .filter((m) => m[1] !== '%')
    .map((m) => m[0])
    .sort();
}

const escapePo = (s) =>
  s.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\n/g, '\\n').replace(/\t/g, '\\t');

/**
 * The template for msgid → the files it appears in.
 *
 * @param {Map<string, string[]>} messages in output order
 */
export function renderPot(messages) {
  let pot =
    '# Translation template for the IwacSearch Omeka S module (PHP side).\n' +
    '# Generated by scripts/build-pot.js (npm run build:pot); do not edit by hand.\n' +
    '# The Svelte client translates itself: see src/svelte/lib/i18n.ts.\n' +
    'msgid ""\nmsgstr ""\n' +
    '"Content-Type: text/plain; charset=UTF-8\\n"\n';
  for (const [msgid, files] of messages) {
    pot += `\n#: ${files.join(' ')}\n`;
    if (placeholders(msgid).length > 0) pot += '#, php-format\n';
    pot += `msgid "${escapePo(msgid)}"\nmsgstr ""\n`;
  }
  return pot;
}

/**
 * What is wrong with a catalogue against the template's msgids.
 *
 * @param {Iterable<string>} msgids
 * @param {Map<string, string>} catalogue parsePo() output
 * @returns {{ errors: string[], obsolete: string[] }}
 */
export function checkCatalogue(msgids, catalogue) {
  const errors = [];
  const wanted = new Set(msgids);
  for (const id of wanted) {
    if (!catalogue.has(id)) {
      errors.push(`missing: ${JSON.stringify(id)}`);
      continue;
    }
    const str = catalogue.get(id);
    if (str.trim() === '') {
      errors.push(`untranslated (empty msgstr): ${JSON.stringify(id)}`);
    } else if (placeholders(id).join() !== placeholders(str).join()) {
      errors.push(
        `placeholders differ (${placeholders(id).join(' ') || 'none'} vs ${placeholders(str).join(' ') || 'none'}): ${JSON.stringify(id)}`,
      );
    }
  }
  const obsolete = [...catalogue.keys()].filter((id) => id !== '' && !wanted.has(id));
  return { errors, obsolete };
}

function sourceFiles() {
  const walk = (dir, ext) =>
    readdirSync(dir).flatMap((name) => {
      const path = join(dir, name);
      if (statSync(path).isDirectory()) {
        return name === 'svelte' || name === 'svelte-shared' ? [] : walk(path, ext);
      }
      return name.endsWith(ext) ? [path] : [];
    });
  return [
    join(ROOT, 'Module.php'),
    ...walk(join(ROOT, 'config'), '.php'),
    ...walk(join(ROOT, 'src'), '.php'),
    ...walk(join(ROOT, 'view'), '.phtml'),
  ]
    .map((path) => relative(ROOT, path).split(sep).join('/'))
    .sort();
}

/** msgid → files, plus the extraction problems, for the whole module. */
export function collect(files = sourceFiles(), read = (f) => readFileSync(join(ROOT, f), 'utf8')) {
  const messages = new Map();
  const problems = [];
  for (const file of files) {
    const result = extract(read(file).replace(/\r\n/g, '\n'), file);
    problems.push(...result.problems);
    for (const msgid of result.msgids) {
      if (!messages.has(msgid)) messages.set(msgid, []);
      messages.get(msgid).push(file);
    }
  }
  return { messages, problems };
}

if (process.argv[1] && fileURLToPath(import.meta.url) === resolve(process.argv[1])) {
  const { messages, problems } = collect();
  if (problems.length > 0) {
    console.error('✗ translatable strings that cannot be extracted:');
    for (const p of problems) console.error(`   ${p}`);
    process.exit(1);
  }
  const pot = renderPot(messages);

  if (!process.argv.includes('--check')) {
    writeFileSync(POT, pot);
    console.log(`✓ build:pot: ${messages.size} strings → language/template.pot`);
    process.exit(0);
  }

  let failed = false;
  let current = null;
  try {
    current = readFileSync(POT, 'utf8').replace(/\r\n/g, '\n');
  } catch {
    // Reported below.
  }
  if (current !== pot) {
    console.error('✗ language/template.pot is out of date — run npm run build:pot');
    failed = true;
  }
  const { errors, obsolete } = checkCatalogue(messages.keys(), parsePo(readFileSync(PO, 'utf8')));
  if (errors.length > 0) {
    console.error('✗ language/fr.po does not translate every PHP string:');
    for (const e of errors) console.error(`   ${e}`);
    failed = true;
  }
  for (const id of obsolete) {
    console.warn(`! language/fr.po translates a string no source uses: ${JSON.stringify(id)}`);
  }
  if (failed) process.exit(1);
  console.log(
    `✓ gettext: template.pot is current and fr.po translates its ${messages.size} strings`,
  );
}
