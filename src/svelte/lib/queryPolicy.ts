import { EXACT_MODE_PARAMS, isExactQuery, withoutField } from './queryBuilders';

/** Shared population policy. Auxiliary requests change only projection and pagination. */
export function queryPolicy(q: string, queryBy: string, stopwords = true, keywordOnly = false) {
  const query = q.trim() ? q : '*';
  const exact = query !== '*' && isExactQuery(query);
  return {
    q: query,
    query_by: exact || keywordOnly ? withoutField(queryBy, 'embedding') : queryBy,
    ...(stopwords && !exact ? { stopwords: 'fr_default' } : {}),
    ...(exact ? EXACT_MODE_PARAMS : {}),
    exclude_fields: 'ocr_text,toc_txt,embedding',
  };
}
