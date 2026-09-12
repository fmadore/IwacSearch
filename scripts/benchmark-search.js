/** Read-only comparison harness. Default target is the disposable integration server. */
import { readFile } from 'node:fs/promises';

const endpoint = process.env.IWAC_BENCH_ENDPOINT ?? 'http://127.0.0.1:18108';
const key = process.env.IWAC_BENCH_KEY_FILE
  ? (await readFile(process.env.IWAC_BENCH_KEY_FILE, 'utf8')).trim()
  : 'iwac-disposable-test-key';
const cases = JSON.parse(
  await readFile(process.argv[2] ?? 'data/relevance-cases.example.json', 'utf8'),
);
const variants = {
  baseline: {},
  no_token_dropping: { drop_tokens_threshold: 0 },
  rerank: { rerank_hybrid_matches: true, drop_tokens_threshold: 0 },
};
const measurements = [];
for (const [variant, params] of Object.entries(variants)) {
  for (const test of cases) {
    for (let repeat = 0; repeat < 4; repeat++) {
      const start = performance.now();
      const response = await fetch(`${endpoint}/multi_search`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-TYPESENSE-API-KEY': key },
        signal: AbortSignal.timeout(30000),
        body: JSON.stringify({
          searches: [
            {
              collection: test.collection ?? 'iwac_current',
              q: test.q,
              query_by: test.query_by ?? 'title_txt,ocr_text,embedding',
              filter_by: test.filter_by ?? 'is_public:=true',
              per_page: 10,
              exclude_fields: 'ocr_text,toc_txt,embedding',
              enable_analytics: false,
              ...params,
            },
          ],
        }),
      });
      const raw = await response.text();
      if (!response.ok) throw new Error(`Benchmark HTTP ${response.status}`);
      const result = JSON.parse(raw).results?.[0];
      if (!result || result.error)
        throw new Error(`Benchmark failed: ${result?.error ?? 'missing result'}`);
      const hits = result.hits ?? [];
      const expected = test.expected_ids ?? [];
      const relevantRanks = hits.flatMap((hit, index) =>
        expected.includes(hit.document.id) ? [index + 1] : [],
      );
      measurements.push({
        variant,
        case: test.label,
        warmup: repeat === 0,
        milliseconds: Math.round((performance.now() - start) * 100) / 100,
        bytes: Buffer.byteLength(raw),
        found: result.found,
        recall_at_10: expected.length ? relevantRanks.length / expected.length : null,
        reciprocal_rank: expected.length ? (relevantRanks[0] ? 1 / relevantRanks[0] : 0) : null,
      });
    }
  }
}
const summaries = Object.keys(variants).map((variant) => {
  const rows = measurements.filter((m) => m.variant === variant && !m.warmup);
  const latencies = rows.map((m) => m.milliseconds).sort((a, b) => a - b);
  return {
    variant,
    p50_ms: latencies[Math.floor(latencies.length * 0.5)],
    p95_ms: latencies[Math.min(latencies.length - 1, Math.floor(latencies.length * 0.95))],
    mean_bytes: Math.round(rows.reduce((n, r) => n + r.bytes, 0) / rows.length),
  };
});
console.log(JSON.stringify({ endpoint, summaries, measurements }, null, 2));
