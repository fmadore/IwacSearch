import { EXACT_MODE_PARAMS, isExactQuery, withoutField } from './queryBuilders';

/**
 * The server-side stopword set every keyword search names. Provisioned as
 * StopwordsSync::SET_NAME by the rebuild and cli/stopwords-sync.php;
 * check-schema-drift.js keeps the two equal.
 */
export const STOPWORD_SET = 'fr_default';

/** Shared population policy. Auxiliary requests change only projection and pagination. */
export function queryPolicy(q: string, queryBy: string, stopwords = true, keywordOnly = false) {
  const query = q.trim() ? q : '*';
  const exact = query !== '*' && isExactQuery(query);
  return {
    q: query,
    query_by: exact || keywordOnly ? withoutField(queryBy, 'embedding') : queryBy,
    ...(stopwords && !exact ? { stopwords: STOPWORD_SET } : {}),
    ...(exact ? EXACT_MODE_PARAMS : {}),
    exclude_fields: 'ocr_text,toc_txt,embedding',
  };
}
