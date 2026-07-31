import { describe, expect, it } from 'vitest';
import { EXPORT_FORMATS, serialize } from '../../src/svelte/lib/export';
import type { IwacDoc } from '../../src/svelte/lib/types';

const reference: IwacDoc = {
  id: '42',
  identifier: 'iwac-42',
  title: 'Islam & société',
  type_s: 'reference',
  reference_type_ss: ['Article de revue'],
  creator_ss: ['Aminata Diallo'],
  publisher_s: 'Cahiers africains',
  volume_s: '12',
  issue_s: '2',
  pages_s: '185–209',
  pub_year: 2024,
  subjects_ss: ['Islam', 'Société'],
  abstract: 'Résumé de référence',
  doi: '10.1234/example',
  omeka_url: 'https://islam.zmo.de/s/afrique_ouest/item/42',
};

const publication: IwacDoc = {
  id: '84',
  title: 'Al Mawadda no 48–49',
  type_s: 'publication',
  newspaper_ss: ['Al Mawadda'],
  date: Date.UTC(2020, 5, 1) / 1000,
  abstract: 'Sommaire de la publication',
  omeka_url: 'https://islam.zmo.de/s/afrique_ouest/item/84',
};

const meta = { query: 'éducation', found: 27 };

describe('result export serializers', () => {
  it('advertises every format requested by issue #15', () => {
    expect(EXPORT_FORMATS.map((format) => format.format)).toEqual(['txt', 'json', 'ris', 'bibtex']);
  });

  it('writes a readable text export with query and cap metadata', () => {
    const text = serialize('txt', [reference, publication], meta, 'en');

    expect(text).toContain('Search: éducation');
    expect(text).toContain('Exported results: 2 / 27');
    expect(text).toContain('Aminata Diallo (2024). Islam & société');
    expect(text).toContain('[Islamic publication]');
  });

  it('keeps structured documents and provenance in JSON', () => {
    const data = JSON.parse(serialize('json', [reference], meta, 'fr'));

    expect(data).toMatchObject({
      source: 'Islam West Africa Collection (https://islam.zmo.de/)',
      query: 'éducation',
      total_found: 27,
      exported: 1,
      results: [reference],
    });
    expect(data.exported_at).toMatch(/^\d{4}-\d{2}-\d{2}T/);
  });

  it('produces importable RIS fields for reference managers', () => {
    const ris = serialize('ris', [reference], meta, 'fr');

    expect(ris).toContain('TY  - JOUR');
    expect(ris).toContain('AU  - Aminata Diallo');
    expect(ris).toContain('T2  - Cahiers africains');
    expect(ris).toContain('SP  - 185');
    expect(ris).toContain('EP  - 209');
    expect(ris).toContain('ER  - ');
  });

  it('produces BibTeX with stable keys and escaped special characters', () => {
    const bibtex = serialize('bibtex', [reference, publication], meta, 'fr');

    expect(bibtex).toContain('@article{iwac42,');
    expect(bibtex).toContain('title = {Islam \\& société}');
    expect(bibtex).toContain('pages = {185--209}');
    expect(bibtex).toContain('@article{iwac84,');
    expect(bibtex).toContain('journal = {Al Mawadda}');
  });
});
