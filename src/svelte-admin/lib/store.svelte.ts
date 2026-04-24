import { AdminApi, AdminApiError } from './api';
import type { AdminBootstrap, BrowseConfig } from './types';

/**
 * Single source of truth for admin state, using Svelte 5 runes.
 *
 * Optimistic mutations: every write mutates the local `configs` array
 * immediately so the UI reacts before the server confirms. If the
 * server rejects, we roll back and surface the error — the user sees
 * a clear "couldn't save, undone" instead of a spinner that eventually
 * times out.
 *
 * We intentionally do NOT refetch the whole list after every mutation.
 * The server returns the persisted row; we merge it in. A manual
 * "Refresh" button (or a visible error banner) handles the rare
 * desync case where someone edited the DB outside this UI.
 */
export class AdminStore {
  // `$state` on class fields gives us fine-grained reactivity: a
  // template reading one item's `title` only re-renders when that
  // specific field changes, not when the whole array is reassigned.
  configs: BrowseConfig[] = $state([]);
  lastError: string | null = $state(null);
  /** Set while a mutation is in flight so Save/Delete buttons can disable. */
  mutationInProgress: boolean = $state(false);

  private readonly api: AdminApi;

  constructor(private readonly bootstrap: AdminBootstrap) {
    this.api = new AdminApi(bootstrap);
    this.configs = [...bootstrap.configs];
  }

  get catalog() {
    return this.bootstrap.catalog;
  }

  async create(draft: Omit<BrowseConfig, 'id'>): Promise<BrowseConfig | null> {
    this.lastError = null;
    this.mutationInProgress = true;

    // Optimistic: add a temporary row so the table updates instantly.
    // We'll replace it with the server's canonical version on success,
    // or remove it on failure.
    const tempId = -Date.now(); // negative so it can't collide with a real id
    const optimistic: BrowseConfig = { ...draft, id: tempId };
    this.configs = [...this.configs, optimistic];

    try {
      const saved = await this.api.createConfig(draft);
      this.configs = this.configs.map((c) => (c.id === tempId ? saved : c));
      return saved;
    } catch (e) {
      this.configs = this.configs.filter((c) => c.id !== tempId);
      this.lastError = this.formatError(e);
      return null;
    } finally {
      this.mutationInProgress = false;
    }
  }

  async update(id: number, patch: Partial<Omit<BrowseConfig, 'id'>>): Promise<BrowseConfig | null> {
    this.lastError = null;
    this.mutationInProgress = true;

    const previous = this.configs.find((c) => c.id === id);
    if (!previous) return null;

    // Optimistic: show the new values immediately.
    this.configs = this.configs.map((c) => (c.id === id ? { ...c, ...patch } : c));

    try {
      const saved = await this.api.updateConfig(id, patch);
      // Replace with the canonical server row — covers server-side
      // massaging like slug lowercasing that the optimistic copy missed.
      this.configs = this.configs.map((c) => (c.id === id ? saved : c));
      return saved;
    } catch (e) {
      // Roll back.
      this.configs = this.configs.map((c) => (c.id === id ? previous : c));
      this.lastError = this.formatError(e);
      return null;
    } finally {
      this.mutationInProgress = false;
    }
  }

  async delete(id: number): Promise<boolean> {
    this.lastError = null;
    this.mutationInProgress = true;

    const previous = this.configs.find((c) => c.id === id);
    if (!previous) return false;

    this.configs = this.configs.filter((c) => c.id !== id);

    try {
      await this.api.deleteConfig(id);
      return true;
    } catch (e) {
      // Restore at the original position so the list order stays stable.
      this.configs = [...this.configs, previous].sort(
        (a, b) => a.position - b.position || a.title.localeCompare(b.title),
      );
      this.lastError = this.formatError(e);
      return false;
    } finally {
      this.mutationInProgress = false;
    }
  }

  dismissError(): void {
    this.lastError = null;
  }

  private formatError(e: unknown): string {
    if (e instanceof AdminApiError) {
      return e.detail ? `${e.message} — ${e.detail}` : e.message;
    }
    return e instanceof Error ? e.message : 'Unknown error';
  }
}
