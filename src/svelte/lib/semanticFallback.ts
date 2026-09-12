/** Query-wide evidence only: a page sample cannot establish absence. */
export interface KeywordScorable {
  keyword_found?: number;
  hits?: unknown[];
}
export function isSemanticOnlyResponse(
  response: KeywordScorable | null | undefined,
  query: string,
): boolean {
  return Boolean(
    response &&
    query.trim() &&
    query !== '*' &&
    response.keyword_found === 0 &&
    response.hits?.length,
  );
}
