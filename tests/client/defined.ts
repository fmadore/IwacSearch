/**
 * The value, or a test failure that says what was missing.
 *
 * `noUncheckedIndexedAccess` types every `list[i]` as possibly undefined, and
 * in a test that is a real question — did the request carry a second search?
 * did the dropdown render a row? — so it gets an answer that fails by name,
 * rather than a `!` that turns the missing element into an opaque TypeError
 * a few lines later.
 */
export function defined<T>(value: T | null | undefined, what: string): T {
  if (value === undefined || value === null) {
    throw new Error(`expected ${what}, got ${String(value)}`);
  }
  return value;
}
