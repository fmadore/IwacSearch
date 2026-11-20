import { afterEach, describe, expect, it } from 'vitest';
import { flushSync, mount, unmount } from 'svelte';
import Pagination from '../../src/svelte/components/Pagination.svelte';

/**
 * The pager's tools row (page jump, page size). A surface that does not own
 * the page size (the federated union tab) with few pages has neither control,
 * and the row used to render anyway: an empty div with its bottom padding.
 */

let app: ReturnType<typeof mount> | null = null;

afterEach(() => {
  if (app) void unmount(app);
  app = null;
  document.body.innerHTML = '';
});

const noop = (): void => {};

describe('Pagination tools row', () => {
  it('is absent when it would hold no control', () => {
    app = mount(Pagination, {
      target: document.body,
      props: { currentPage: 1, totalPages: 3, onPageChange: noop, perPage: 20 },
    });
    flushSync();
    expect(document.querySelector('.iwac-pager__tools')).toBeNull();
  });

  it('holds the size picker when the surface owns the page size', () => {
    app = mount(Pagination, {
      target: document.body,
      props: {
        currentPage: 1,
        totalPages: 3,
        onPageChange: noop,
        perPage: 20,
        onPerPageChange: noop,
      },
    });
    flushSync();
    expect(document.querySelector('.iwac-pager__tools select')).not.toBeNull();
  });
});
