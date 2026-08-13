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

/**
 * A YouTube video (class 38, resource template 23). Its provenance lives in
 * `channel_ss` rather than `newspaper_ss`, and its canonical watch URL is
 * separate from the IWAC record — an export that dropped either would strip
 * the row of everything that identifies it.
 */
const video: IwacDoc = {
  id: '110631',
  title: 'Les leaders musulmans de la province du Bazèga',
  type_s: 'audiovisual',
  channel_ss: ["Cercle d'études, de Recherches et de Formation Islamiques"],
  media_kind_s: 'video',
  media_platform_s: 'youtube',
  duration_seconds: 332,
  country_ss: ['Burkina Faso'],
  date: Date.UTC(2026, 7, 11) / 1000,
  pub_year: 2026,
  abstract: 'Reportage de CERFI TV',
  omeka_url: 'https://islam.zmo.de/s/afrique_ouest/item/110631',
  source_url: 'https://www.youtube.com/watch?v=vtrJSbeZNsg',
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

  describe('audiovisual rows', () => {
    it('carries the channel, the running time and the watch URL in text', () => {
      const text = serialize('txt', [video], meta, 'en');

      expect(text).toContain("Cercle d'études, de Recherches et de Formation Islamiques");
      expect(text).toContain('[Audiovisual, 5:32]');
      // Provenance first, the third-party link after it.
      expect(text).toContain(
        'https://islam.zmo.de/s/afrique_ouest/item/110631. https://www.youtube.com/watch?v=vtrJSbeZNsg',
      );
    });

    it('files the channel as the RIS studio, and the watch URL beside UR', () => {
      const ris = serialize('ris', [video], meta, 'fr');

      expect(ris).toContain('TY  - VIDEO');
      // PB, not T2: the channel is the producer, not a series this belongs to.
      expect(ris).toContain("PB  - Cercle d'études, de Recherches et de Formation Islamiques");
      expect(ris).not.toContain('T2  - ');
      expect(ris).toContain('UR  - https://islam.zmo.de/s/afrique_ouest/item/110631');
      expect(ris).toContain('L2  - https://www.youtube.com/watch?v=vtrJSbeZNsg');
    });

    it('keeps url on the IWAC record in BibTeX and puts the source in note', () => {
      const bibtex = serialize('bibtex', [video], meta, 'fr');

      expect(bibtex).toContain('@misc{iwac110631,');
      expect(bibtex).toContain(
        "howpublished = {Cercle d'études, de Recherches et de Formation Islamiques}",
      );
      // BibTeX escaping applies to URLs too (the `_` in the site slug).
      expect(bibtex).toContain('url = {https://islam.zmo.de/s/afrique\\_ouest/item/110631}');
      expect(bibtex).toContain('note = {https://www.youtube.com/watch?v=vtrJSbeZNsg}');
    });
  });
});
