import type { AdminBootstrap, ApiError, ApiSuccess, BrowseConfig } from './types';

/**
 * Thin JSON client for /admin/iwac-search/browse-config/api/*.
 *
 * We never do a list-fetch on mount — the initial page of configs
 * comes inlined in bootstrap. This client is used only for mutations
 * and the optional post-mutation refresh that keeps the UI aligned
 * with any server-side massaging (e.g. slug lowercasing).
 *
 * Design invariants:
 *   - Same-origin fetch with `credentials: include` so the Omeka
 *     admin session cookie rides along.
 *   - Every mutation sends `X-CSRF-Token: <bootstrap.csrf_token>`.
 *   - Every response we don't understand becomes an `ApiClientError`
 *     — the Svelte app displays its `.message` without unwrapping.
 */
export class AdminApiError extends Error {
  public readonly code: string;
  public readonly detail?: string;
  public readonly status: number;

  constructor(status: number, body: ApiError | { error?: string; message?: string }) {
    super(body.message ?? `HTTP ${status}`);
    this.status = status;
    this.code = body.error ?? `http_${status}`;
    this.detail = (body as ApiError).detail;
  }
}

export class AdminApi {
  constructor(private readonly bootstrap: AdminBootstrap) {}

  listConfigs(): Promise<BrowseConfig[]> {
    return this.json<BrowseConfig[]>(this.bootstrap.endpoints.list, { method: 'GET' });
  }

  createConfig(input: Omit<BrowseConfig, 'id'>): Promise<BrowseConfig> {
    return this.json<BrowseConfig>(this.bootstrap.endpoints.list, {
      method: 'POST',
      body: JSON.stringify(input),
    });
  }

  updateConfig(id: number, input: Partial<Omit<BrowseConfig, 'id'>>): Promise<BrowseConfig> {
    return this.json<BrowseConfig>(this.itemUrl(id), {
      method: 'PATCH',
      body: JSON.stringify(input),
    });
  }

  deleteConfig(id: number): Promise<{ id: number; deleted: true }> {
    return this.json<{ id: number; deleted: true }>(this.itemUrl(id), {
      method: 'DELETE',
    });
  }

  private itemUrl(id: number): string {
    // The PHP side emitted `.../browse-config/api/0` — we swap the
    // trailing 0 for the real id. A `.replace` is fine because the
    // only `0` in the URL is the id segment.
    return this.bootstrap.endpoints.item.replace(/0$/, String(id));
  }

  private async json<T>(url: string, init: RequestInit): Promise<T> {
    const headers = new Headers(init.headers ?? {});
    headers.set('Accept', 'application/json');
    if (init.method && init.method !== 'GET') {
      headers.set('Content-Type', 'application/json');
      headers.set('X-CSRF-Token', this.bootstrap.csrf_token);
    }

    let res: Response;
    try {
      res = await fetch(url, {
        ...init,
        headers,
        credentials: 'same-origin',
      });
    } catch (cause) {
      throw new AdminApiError(0, {
        error: 'network_error',
        message: `Network error: ${cause instanceof Error ? cause.message : 'unknown'}`,
      });
    }

    // Body may be empty (e.g. HEAD-ish 204) or HTML (an upstream 500
    // page); both parse-failure modes degrade cleanly to null and are
    // handled below.
    const body: unknown = await res.json().catch(() => null);

    if (!res.ok) {
      const errBody = (body && typeof body === 'object' ? body : {}) as ApiError;
      throw new AdminApiError(res.status, errBody);
    }

    const envelope = body as ApiSuccess<T> | null;
    if (!envelope || !('data' in envelope)) {
      throw new AdminApiError(res.status, {
        error: 'malformed_response',
        message: 'Server response missing `data` envelope.',
      });
    }
    return envelope.data;
  }
}
