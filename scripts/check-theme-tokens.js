#!/usr/bin/env node
/**
 * Theme-token contract guard (IwacSearch) — `npm run lint:theme`.
 *
 * IwacSearch consumes the IWAC theme's design tokens (see
 * IWAC-theme/docs/DESIGN-SYSTEM.md) and must never redefine them or drift from
 * their canonical values. The RULES live in `scripts/theme-token-guard.cjs`, a
 * copy of IWAC-theme/scripts/lib/theme-token-guard.cjs that the theme's
 * `npm run sync:tokens` writes beside tokens.json. Do not edit that copy — edit
 * the theme's and re-sync, so both modules move with the contract.
 *
 * This file used to BE the guard: a 621-line fork of IwacVisualizations'
 * 701-line one, and the forks had drifted — among other things this one still
 * accepted `max-width: 767.98px` a release after the theme retired it, and
 * exempted from the raw-colour rule any line mentioning `--iwac-vis-`, a
 * namespace this module never uses. What stays here is only what is genuinely
 * this module's:
 *
 *   - where its hand-written styles live: `src/` (a `.svelte` file's `<style>`
 *     regions as CSS, the rest as script), and `asset/css/`, the hand-edited
 *     sheets Vite does not produce. `asset/css/` was outside the walk until
 *     2026-08, and every colour fallback in it had stayed on the pre-v2.6
 *     palette while `src/` was spotless — a guard that skips a file is not a
 *     guard; it is a comment about the files it does read;
 *   - the namespace it owns: `--iwac-*`, EXCEPT `--iwac-vis-*`, which is
 *     IwacVisualizations'.
 */
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import guard from './theme-token-guard.cjs';

guard.cli({
  root: join(dirname(fileURLToPath(import.meta.url)), '..'),
  roots: [
    ['src', ['.svelte', '.css', '.ts']],
    ['asset/css', ['.css']],
    ['view', ['.phtml']],
  ],
  skip: (rel) => rel.endsWith('.d.ts'),
  ownPrefix: /^--iwac-(?!vis-)/,
});
