#!/usr/bin/env node
/**
 * Locale-table parity guard for src/svelte/lib/i18n.ts.
 *
 * The module ships its own FR/EN string tables rather than a gettext
 * catalogue, and every one of them is typed `Record<Locale, Record<string,
 * string>>`. That inner `string` is the hole: TypeScript checks that `fr` and
 * `en` both exist and that their values are strings, and nothing at all
 * checks that they hold the SAME KEYS. Nine tables, ~150 key pairs, no
 * enforcement.
 *
 * A gap is silent in the worst way. translate() resolves
 * `table[key] ?? STRINGS.fr[key] ?? key`, so a key present in `fr` but
 * missing from `en` does not throw and does not render the key — it renders
 * the FRENCH string to English visitors on /s/westafrica. The label helpers
 * fall back to humanise() or the raw field name. Either way the failure looks
 * like a translation nobody got round to, not like a bug.
 *
 * Same class of drift as the sibling module's fr.po/fr.mo pair
 * (IwacVisualizations, scripts/check-i18n-mo.js): two hand-maintained halves
 * of one catalogue that can disagree with nothing to notice.
 *
 * Scope note: check-schema-drift.js already asserts that every
 * FacetCatalog::FACETABLE_FIELDS key is labelled in both locales of
 * FACET_LABELS. This guard is the complement — full key-set equality across
 * every locale table, including the keys no catalog mentions. Duplicate keys
 * are deliberately NOT checked here: an object literal with two identical
 * property names is a TypeScript error already, and `npm run check` runs in
 * the same CI job.
 *
 * Tables are DISCOVERED from the type annotation rather than listed, and the
 * locales come from the `Locale` union itself, so adding either does not
 * require editing this file — a hand-maintained list is the thing that drifts.
 * `Partial<Record<Locale, ...>>` marks a table as deliberately incomplete
 * (COUNTRY_LABELS is French-only); those are exempted from parity and printed
 * so the exemption stays visible.
 *
 * Run: node scripts/check-i18n.js  (wired into `npm run lint`)
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const text = readFileSync(join(root, 'src/svelte/lib/i18n.ts'), 'utf8');

// Format drift must fail loudly rather than pass on an empty set: a guard
// that silently finds nothing is worse than no guard, because it reads green.
const MIN_TABLES = 8;

let failed = false;
function fail(msg) {
  failed = true;
  console.error(`❌ ${msg}`);
}

/**
 * The locale union, read from `export type Locale = 'fr' | 'en';`.
 * Deriving it means a third locale immediately makes every table incomplete
 * until it is filled in, which is the correct default.
 */
function parseLocales() {
  const match = /export type Locale\s*=\s*([^;]+);/.exec(text);
  if (!match) throw new Error('i18n.ts: `export type Locale` not found — update this guard.');
  const locales = [...match[1].matchAll(/'([^']+)'/g)].map((m) => m[1]);
  if (!locales.length) throw new Error(`i18n.ts: could not read locales from "${match[1]}"`);
  return locales;
}

/** The balanced `{ … }` object literal starting at or after `from`. */
function objectLiteralAt(from) {
  const open = text.indexOf('{', from);
  if (open < 0) return null;
  let depth = 0;
  let quote = null;
  for (let i = open; i < text.length; i++) {
    const ch = text[i];
    if (quote) {
      if (ch === '\\') i++;
      else if (ch === quote) quote = null;
      continue;
    }
    if (ch === "'" || ch === '"' || ch === '`') {
      quote = ch;
      continue;
    }
    if (ch === '/' && text[i + 1] === '/') {
      i = text.indexOf('\n', i);
      if (i < 0) break;
      continue;
    }
    if (ch === '/' && text[i + 1] === '*') {
      i = text.indexOf('*/', i);
      if (i < 0) break;
      i++;
      continue;
    }
    if (ch === '{') depth++;
    else if (ch === '}' && --depth === 0) return text.slice(open, i + 1);
  }
  return null;
}

/** Every `const NAME: [Partial<]Record<Locale, Record<string, string>>` table. */
function parseTables() {
  const declaration =
    /const (\w+):\s*(Partial<)?Record<\s*Locale\s*,\s*Record<\s*string\s*,\s*string\s*>\s*>/g;
  const tables = [];
  let match;
  while ((match = declaration.exec(text)) !== null) {
    const literal = objectLiteralAt(match.index + match[0].length);
    if (!literal) {
      fail(`i18n.ts: could not read the object literal for ${match[1]}`);
      continue;
    }
    let value;
    try {
      value = vm.runInNewContext(`(${literal})`, Object.create(null));
    } catch (err) {
      fail(`i18n.ts: ${match[1]} is not a plain data literal (${err.message})`);
      continue;
    }
    tables.push({ name: match[1], partial: Boolean(match[2]), value });
  }
  return tables;
}

try {
  const locales = parseLocales();
  const tables = parseTables();

  if (tables.length < MIN_TABLES) {
    fail(
      `i18n.ts: found only ${tables.length} locale tables (expected at least ${MIN_TABLES}) — ` +
        'the declaration format probably changed; update this guard rather than lowering the floor.',
    );
  }

  let pairs = 0;
  const exempt = [];

  for (const { name, partial, value } of tables) {
    const present = locales.filter((locale) => value[locale]);

    if (!present.length) {
      fail(`${name}: carries none of the declared locales (${locales.join(', ')})`);
      continue;
    }

    for (const locale of present) {
      if (!Object.keys(value[locale]).length) fail(`${name}.${locale}: parsed as an empty table`);
    }

    if (partial) {
      exempt.push(`${name} (${present.join(', ')} only)`);
      continue;
    }

    const missingLocale = locales.filter((locale) => !value[locale]);
    if (missingLocale.length) {
      fail(`${name}: missing the ${missingLocale.join(', ')} table entirely`);
      continue;
    }

    // Compare every locale against the union of all keys, so the report names
    // the table that is short rather than an arbitrary "first" locale.
    const union = new Set(locales.flatMap((locale) => Object.keys(value[locale])));
    for (const locale of locales) {
      const own = new Set(Object.keys(value[locale]));
      const missing = [...union].filter((key) => !own.has(key));
      if (missing.length) {
        const others = locales.filter((l) => l !== locale);
        fail(
          `${name}.${locale} is missing ${missing.length} key(s) present in ` +
            `${others.join('/')}: ${missing.join(', ')}`,
        );
      }
    }
    pairs += union.size;
  }

  if (exempt.length) {
    console.log(`ℹ️  deliberately locale-specific (Partial<Record<…>>): ${exempt.join('; ')}`);
  }

  if (!failed) {
    console.log(
      `✅ i18n parity: ${tables.length} locale tables, ${pairs} keys present in all of ` +
        `${locales.join('/')}`,
    );
  }
} catch (err) {
  fail(err instanceof Error ? err.message : String(err));
}

process.exit(failed ? 1 : 0);
