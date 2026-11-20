import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import {
  compare,
  readSearchModels,
  readVisModels,
  // @ts-expect-error — a plain-JS Node script with no type declarations.
} from '../../scripts/check-sentiment-models.js';

/**
 * scripts/check-sentiment-models.js — the weekly check that search indexes the
 * sentiment models IwacVisualizations shows.
 *
 * It reads AbstractMapper.php with a regex, so the first case is the one that
 * matters day to day: a reformat the parser no longer understands must fail
 * here, on the PR, not silently in a scheduled job.
 */

type Models = Record<string, string>;
type Verdict = { ok: boolean; lines: string[] };

const MAPPER = readFileSync('src/Indexer/Mapper/AbstractMapper.php', 'utf8');

function registry(active: Models): string {
  return JSON.stringify({
    hiddenStems: ['gemini'],
    active: Object.entries(active).map(([stem, id]) => ({ id, stem, name: id })),
    labels: {},
  });
}

describe('readSearchModels', () => {
  it('reads every row of SENTIMENT_MODELS, the sixth model included', () => {
    const models = readSearchModels(MAPPER) as Models;
    expect(Object.keys(models)).toHaveLength(6);
    expect(models.gpt56Luna).toBe('gpt_5_6_luna');
    expect(models.claudeHaiku55).toBe('claude_haiku_5_5');
  });

  it('refuses a source without the constant', () => {
    expect(() => readSearchModels('<?php class X {}')).toThrow(/SENTIMENT_MODELS/);
  });
});

describe('readVisModels', () => {
  it('maps each active stem to its id', () => {
    expect(readVisModels(registry({ a: 'a_1', b: 'b_2' }))).toEqual({ a: 'a_1', b: 'b_2' });
  });

  it('refuses an entry without a stem', () => {
    expect(() => readVisModels(JSON.stringify({ active: [{ id: 'x' }] }))).toThrow(/stem/);
  });
});

describe('compare', () => {
  it('passes when both sides list the same models', () => {
    const verdict = compare({ a: 'a_1', b: 'b_2' }, { a: 'a_1', b: 'b_2' }) as Verdict;
    expect(verdict.ok).toBe(true);
    expect(verdict.lines[0]).toMatch(/all 2 active/);
  });

  it('fails on a model active in the registry and not indexed here', () => {
    // What happened with claudeHaiku55 before v12.
    const verdict = compare(
      { a: 'a_1' },
      { a: 'a_1', claudeHaiku55: 'claude_haiku_5_5' },
    ) as Verdict;
    expect(verdict.ok).toBe(false);
    expect(verdict.lines.join('\n')).toContain("'claudeHaiku55' => 'claude_haiku_5_5'");
  });

  it('fails when one stem names two different models', () => {
    const verdict = compare({ a: 'a_1' }, { a: 'a_2' }) as Verdict;
    expect(verdict.ok).toBe(false);
    expect(verdict.lines.join('\n')).toMatch(/a_1 here but a_2/);
  });

  it('reports, but passes, a model indexed here that the registry retired', () => {
    const verdict = compare({ a: 'a_1', old: 'old_1' }, { a: 'a_1' }) as Verdict;
    expect(verdict.ok).toBe(true);
    expect(verdict.lines.join('\n')).toMatch(/old_1 .* no longer active/);
  });

  it('holds this checkout to its own expectation of the registry', () => {
    // The six models v12 indexes, as the registry declared them in November 2026.
    const vis = {
      gpt56Luna: 'gpt_5_6_luna',
      mistralSmall2603: 'mistral_small_2603',
      deepseekV4Flash0731: 'deepseek_v4_flash_0731',
      gemma431bIt: 'gemma_4_31b_it',
      qwen3827b: 'qwen3_8_27b',
      claudeHaiku55: 'claude_haiku_5_5',
    };
    expect((compare(readSearchModels(MAPPER), readVisModels(registry(vis))) as Verdict).ok).toBe(
      true,
    );
  });
});
