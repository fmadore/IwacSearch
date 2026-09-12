import { getScopedKey } from './scopedKey';
import { HttpError, postJson } from './transport';

/** One refresh on expiry/revocation, coalesced across concurrent callers. */
export async function authenticatedSearch<T>(
  tokenEndpoint: string,
  url: string,
  key: string,
  body: unknown,
  label: string,
  signal?: AbortSignal,
): Promise<T> {
  try {
    return await postJson<T>(url, key, body, label, signal);
  } catch (error) {
    if (!(error instanceof HttpError) || ![401, 403].includes(error.status)) throw error;
    signal?.throwIfAborted();
    const refreshed = await getScopedKey(tokenEndpoint, key);
    return postJson<T>(url, refreshed.key, body, label, signal);
  }
}
