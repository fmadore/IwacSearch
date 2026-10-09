#!/usr/bin/env node
/**
 * Compile language/fr.po into the language/fr.mo Omeka's translator loads.
 *
 *     node scripts/build-mo.js           # write language/fr.mo
 *     node scripts/build-mo.js --check   # fail if fr.mo does not hold fr.po
 *
 * There is no gettext toolchain on the maintainer's machine, and the .mo is
 * committed: the release zip is assembled with `git archive`, so a file
 * generated at package time would never reach it. `--check` (part of
 * `npm run lint`) compares CATALOGUES, not bytes, so a fr.mo recompiled with
 * real `msgfmt` passes too. Same approach as IwacVisualizations'
 * build-mo.js / check-i18n-mo.js, sized for a catalogue of plain strings:
 * no contexts, no plural entries (the client owns every plural on the page).
 */

import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const LANGUAGE = join(dirname(fileURLToPath(import.meta.url)), '..', 'language');
const PO = join(LANGUAGE, 'fr.po');
const MO = join(LANGUAGE, 'fr.mo');

/** Unquote one PO string literal (C escapes). */
function unquote(literal) {
  return JSON.parse(literal.replace(/\\'/g, "'"));
}

/** msgid → msgstr, in file order. Throws on anything this compiler does not support. */
export function parsePo(text) {
  const entries = new Map();
  let field = null;
  let id = null;
  let str = null;
  const flush = () => {
    if (id === null) return;
    if (str === null) throw new Error(`msgid ${JSON.stringify(id)} has no msgstr`);
    if (entries.has(id)) throw new Error(`duplicate msgid ${JSON.stringify(id)}`);
    entries.set(id, str);
    id = str = field = null;
  };
  for (const [n, raw] of text.split(/\r?\n/).entries()) {
    const line = raw.trim();
    if (line === '' || line.startsWith('#')) continue;
    const m = line.match(/^(msgid|msgstr)\s+(".*")$/);
    if (m) {
      if (m[1] === 'msgid') {
        flush();
        id = unquote(m[2]);
      } else {
        if (id === null) throw new Error(`line ${n + 1}: msgstr without msgid`);
        str = unquote(m[2]);
      }
      field = m[1];
    } else if (line.startsWith('"') && field) {
      if (field === 'msgid') id += unquote(line);
      else str += unquote(line);
    } else {
      throw new Error(`line ${n + 1}: unsupported PO syntax: ${line}`);
    }
  }
  flush();
  return entries;
}

/** GNU .mo bytes for a catalogue (little-endian, keys sorted, no hash table). */
export function compileMo(entries) {
  const keys = [...entries.keys()].sort((a, b) =>
    Buffer.compare(Buffer.from(a, 'utf8'), Buffer.from(b, 'utf8')),
  );
  const ids = keys.map((k) => Buffer.from(k, 'utf8'));
  const strs = keys.map((k) => Buffer.from(entries.get(k), 'utf8'));
  const n = keys.length;
  const header = 28;
  let offset = header + n * 16;
  const table = Buffer.alloc(n * 16);
  const blobs = [];
  for (const [i, buf] of [...ids, ...strs].entries()) {
    table.writeUInt32LE(buf.length, i * 8);
    table.writeUInt32LE(offset, i * 8 + 4);
    blobs.push(buf, Buffer.from([0]));
    offset += buf.length + 1;
  }
  const head = Buffer.alloc(header);
  head.writeUInt32LE(0x950412de, 0); // magic
  head.writeUInt32LE(0, 4); // revision
  head.writeUInt32LE(n, 8);
  head.writeUInt32LE(header, 12); // originals table
  head.writeUInt32LE(header + n * 8, 16); // translations table
  head.writeUInt32LE(0, 20); // hash table size
  head.writeUInt32LE(header + n * 16, 24); // hash table offset
  return Buffer.concat([head, table, ...blobs]);
}

/** Read a .mo back into msgid → msgstr. */
export function parseMo(buf) {
  const magic = buf.readUInt32LE(0);
  const read = magic === 0x950412de ? 'readUInt32LE' : 'readUInt32BE';
  const n = buf[read](8);
  const ids = buf[read](12);
  const strs = buf[read](16);
  const at = (table, i) => {
    const len = buf[read](table + i * 8);
    const off = buf[read](table + i * 8 + 4);
    return buf.subarray(off, off + len).toString('utf8');
  };
  const entries = new Map();
  for (let i = 0; i < n; i++) entries.set(at(ids, i), at(strs, i));
  return entries;
}

function sameCatalogue(a, b) {
  return a.size === b.size && [...a].every(([k, v]) => b.get(k) === v);
}

if (process.argv[1] && fileURLToPath(import.meta.url) === resolve(process.argv[1])) {
  const catalogue = parsePo(readFileSync(PO, 'utf8'));
  if (!catalogue.has('')) {
    console.error('✗ language/fr.po has no header entry (an empty msgid)');
    process.exit(1);
  }
  if (process.argv.includes('--check')) {
    let compiled;
    try {
      compiled = parseMo(readFileSync(MO));
    } catch (e) {
      console.error(
        `✗ language/fr.mo is missing or unreadable (${e.message}) — run npm run build:mo`,
      );
      process.exit(1);
    }
    if (!sameCatalogue(catalogue, compiled)) {
      console.error('✗ language/fr.mo does not match language/fr.po — run npm run build:mo');
      process.exit(1);
    }
    console.log(`✓ gettext: language/fr.mo holds the ${catalogue.size - 1} strings of fr.po`);
  } else {
    const bytes = compileMo(catalogue);
    if (!sameCatalogue(catalogue, parseMo(bytes))) {
      console.error('✗ compiled catalogue does not read back — not writing');
      process.exit(1);
    }
    writeFileSync(MO, bytes);
    console.log(
      `✓ build:mo: ${catalogue.size - 1} strings → language/fr.mo (${bytes.length} bytes)`,
    );
  }
}
