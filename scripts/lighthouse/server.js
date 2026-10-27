#!/usr/bin/env node
/**
 * The static server the Lighthouse fixtures are measured from.
 *
 *     PORT=4190 node scripts/lighthouse/server.js
 *
 * Serves this checkout, with text gzipped as the live site serves it (Apache
 * compresses the module's assets; uncompressed, the gate would measure a page
 * three times heavier than the one readers download), compressed whole at a
 * fixed level so a file is the same byte count on every run and platform.
 *
 * Three live routes are stood in for:
 *   - /files/<tier>/<storage_id>.<ext> — the thumbnails the captured hits
 *     name, answered with a placeholder of that tier's size;
 *   - /discovery/token and /search-api/multi_search — the scoped key and
 *     Typesense. The fixtures carry their first page of results
 *     server-rendered; what the client still asks for on load (the year
 *     histogram, the tab counts) gets a key and empty pages, and
 *     scripts/check-lighthouse.js counts the round trips.
 */

import { readFileSync, stat } from 'node:fs';
import { createServer } from 'node:http';
import { extname, join, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { gzipSync } from 'node:zlib';

const ROOT = resolve(fileURLToPath(import.meta.url), '..', '..', '..');
const PLACEHOLDERS = join(ROOT, 'tests', 'lighthouse', 'fixtures', 'files');
const HOST = '127.0.0.1';
const PORT = Number(process.env.PORT || 4190);

const MIME = {
  '.css': 'text/css; charset=utf-8',
  '.html': 'text/html; charset=utf-8',
  '.jpg': 'image/jpeg',
  '.js': 'text/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.png': 'image/png',
  '.svg': 'image/svg+xml',
};
const COMPRESSIBLE = new Set(['.css', '.html', '.js', '.json', '.svg']);
const gzipped = new Map();

function send(request, response, status, type, body) {
  const headers = { 'Content-Type': type, 'Cache-Control': 'no-store' };
  if (
    /\bgzip\b/.test(request.headers['accept-encoding'] || '') &&
    /^(text|application\/json|image\/svg)/.test(type)
  ) {
    const key = typeof body === 'string' ? null : body;
    let zipped = key && gzipped.get(key);
    if (!zipped) {
      zipped = gzipSync(body, { level: 6 });
      if (key) gzipped.set(key, zipped);
    }
    response.writeHead(status, { ...headers, 'Content-Encoding': 'gzip', Vary: 'Accept-Encoding' });
    response.end(zipped);
    return;
  }
  response.writeHead(status, headers);
  response.end(body);
}

const files = new Map();
function fileBody(path, size, mtime) {
  const key = `${path}:${size}:${mtime}`;
  if (!files.has(key)) files.set(key, readFileSync(path));
  return files.get(key);
}

function json(request, response, value) {
  send(request, response, 200, MIME['.json'], JSON.stringify(value));
}

const server = createServer((request, response) => {
  let pathname;
  try {
    pathname = decodeURIComponent(new URL(request.url, `http://${HOST}`).pathname);
  } catch {
    response.writeHead(400).end('Bad request');
    return;
  }
  // Every browser asks for one; a 404 is a console error best-practices would count.
  if (pathname === '/favicon.ico') {
    response.writeHead(204).end();
    return;
  }
  if (pathname.endsWith('/discovery/token')) {
    json(request, response, {
      key: 'fixture',
      expires_at: Math.floor(Date.now() / 1000) + 3600,
      expires_in: 3600,
      host: '',
      collection: 'iwac_current',
    });
    return;
  }
  if (pathname.endsWith('/search-api/multi_search')) {
    let raw = '';
    request.on('data', (chunk) => (raw += chunk));
    request.on('end', () => {
      let searches = [];
      try {
        searches = JSON.parse(raw).searches || [];
      } catch {
        // An unreadable body still gets one empty page.
      }
      const empty = { found: 0, out_of: 0, page: 1, hits: [], facet_counts: [], search_time_ms: 0 };
      json(request, response, { results: (searches.length ? searches : [{}]).map(() => empty) });
    });
    return;
  }
  const thumb = /^\/files\/(square|medium|large|original)\/[^/]+\.(?:jpe?g|png)$/.exec(pathname);
  let target = thumb
    ? join(PLACEHOLDERS, `${thumb[1] === 'original' ? 'large' : thumb[1]}.jpg`)
    : resolve(ROOT, `.${pathname}`);
  if (!thumb && target !== ROOT && !target.startsWith(ROOT + sep)) {
    response.writeHead(403).end('Forbidden');
    return;
  }
  stat(target, (error, info) => {
    if (error || !info.isFile()) {
      response.writeHead(404).end('Not found');
      return;
    }
    const type = MIME[extname(target)] || 'application/octet-stream';
    const body = fileBody(target, info.size, info.mtimeMs);
    if (COMPRESSIBLE.has(extname(target))) send(request, response, 200, type, body);
    else {
      response.writeHead(200, { 'Content-Type': type, 'Cache-Control': 'no-store' });
      response.end(body);
    }
  });
});

server.listen(PORT, HOST, () => {
  console.log(`IwacSearch Lighthouse fixtures: http://${HOST}:${PORT}`);
});

for (const signal of ['SIGINT', 'SIGTERM']) {
  process.on(signal, () => server.close(() => process.exit(0)));
}
