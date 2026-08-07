<?php
declare(strict_types=1);

namespace IwacSearch\Indexer\Mapper;

use IwacSearch\Indexer\CountryResolver;
use IwacSearch\Indexer\EntityAuthority;
use IwacSearch\Indexer\PropertyValues;

/**
 * Shared infrastructure for the content mappers.
 *
 * Reading Omeka's grouped property values is {@see PropertyValues}' job — this
 * class is only about turning them into DOCUMENT FIELDS. Subclasses declare
 * classIds()/typeTag()/readTerms() and compose map() from the protected
 * builders below; each builder owns one cluster of schema fields (identity,
 * facets, entities, dates, body, sentiment) so a subset opts in by calling it.
 *
 * Field derivations mirror the IWAC-Hugging-Face pipeline 1:1 (verified by the
 * Phase 0 parity spike) so the produced documents match the previous HF-built
 * collection.
 */
abstract class AbstractMapper implements MapperInterface
{
    /** Terms every content subset reads (identity, facets, entities, date, link). */
    protected const COMMON_TERMS = [
        'dcterms:identifier',
        'dcterms:alternative',
        'dcterms:creator',
        'dcterms:language',
        'dcterms:publisher',
        'dcterms:subject',
        'dcterms:spatial',
        'dcterms:date',
        'fabio:hasURL',
    ];

    protected const BODY_TERMS = ['bibo:content'];
    protected const DESCRIPTION_TERMS = [
        'bibo:shortDescription',
        'dcterms:description',
    ];
    protected const SENTIMENT_TERMS = [
        'iwac:gpt56LunaCentralite', 'iwac:gpt56LunaPolarite', 'iwac:gpt56LunaSubjectiviteScore',
        'iwac:mistralSmall2603Centralite', 'iwac:mistralSmall2603Polarite', 'iwac:mistralSmall2603SubjectiviteScore',
        'iwac:deepseekV4Flash0731Centralite', 'iwac:deepseekV4Flash0731Polarite', 'iwac:deepseekV4Flash0731SubjectiviteScore',
    ];

    /**
     * Omeka term infix => document field prefix.
     *
     * Both sides name the MODEL. Generation 1 of the sentiment vocabulary
     * named a VENDOR SLOT instead (`iwac:geminiPolarite`) and carried no
     * `iwac:*Model` annotation, so the source recorded nothing about which
     * model produced a value and this map had to bridge the two spellings.
     * Generation 2 fixed that at the source: `iwac:gpt56LunaPolarite` says
     * which model ran, so the map is now a pure snake_case transliteration.
     *
     * Two DeepSeek prefixes exist in Omeka — `iwac:deepseekV4Flash*` is a
     * RETIRED preview run and `iwac:deepseekV4Flash0731*` the current one.
     * We read 0731; the two are different annotations of the same corpus and
     * must never be merged.
     *
     * Re-annotating with a different model = swap the row here for one with
     * new field names + a schema bump. Never repoint an existing prefix at a
     * new model: that silently changes what an already-published facet URL
     * means.
     */
    private const SENTIMENT_MODELS = [
        'gpt56Luna'           => 'gpt_5_6_luna',
        'mistralSmall2603'    => 'mistral_small_2603',
        'deepseekV4Flash0731' => 'deepseek_v4_flash_0731',
    ];

    /**
     * Subjectivité is stored as a linked-resource CATEGORY (not a number) for
     * ALL THREE models — confirmed live against the generation-2 properties,
     * which link to the same five scale items (78043–78047) generation 1 did.
     * The HF pipeline converts the label to the 1–5 score; we do the same.
     * Scale derived empirically by pairing the Omeka label against the HF
     * *_subjectivite_score for 25 articles.
     */
    private const SUBJECTIVITE_LABELS = [
        'Très objectif'    => 1.0,
        'Plutôt objectif'  => 2.0,
        'Mixte'            => 3.0,
        'Plutôt subjectif' => 4.0,
        'Très subjectif'   => 5.0,
    ];

    public function __construct(
        protected readonly EntityAuthority $authority,
        protected readonly CountryResolver $countries,
    ) {
    }

    abstract public function subsetName(): string;

    /** @return list<int> */
    abstract public function classIds(): array;

    /** Value placed in the document's type_s field. */
    abstract protected function typeTag(): string;

    /** @return list<int>|null Most subsets are scoped by class alone. */
    public function itemSetIds(): ?array
    {
        return null;
    }

    // ────────────────────────────────────────────────────────────────────
    // Builders
    // ────────────────────────────────────────────────────────────────────

    /**
     * Identity + display + ACL skeleton common to every subset.
     *
     * @param array{id:int,title:string,is_public:bool,class:int,item_sets:list<int>} $item
     * @return array<string, mixed>
     */
    protected function buildBase(array $item, PropertyValues $values, ?string $thumbnailUrl): array
    {
        $oid = $item['id'];
        $title = trim($item['title']);

        $doc = [
            'id'        => (string) $oid,
            'type_s'    => $this->typeTag(),
            'title'     => $title !== '' ? $title : sprintf('[Untitled #%d]', $oid),
            'title_txt' => $title,
            'is_public' => $item['is_public'],
            'omeka_url' => SiteUrls::itemUrl($oid),
        ];

        // Schema field item_set_ids (int32[], not faceted) — set memberships
        // for future ACL/collection filtering. Was declared in schema.yaml
        // from day one but never populated until 3.6.0 (caught by the
        // check-schema-drift guard).
        if ($item['item_sets'] !== []) {
            $doc['item_set_ids'] = array_values($item['item_sets']);
        }

        $this->maybeAdd($doc, 'identifier', $values->firstScalar('dcterms:identifier'));
        // Alternative titles (dcterms:alternative) — a second FTS channel so
        // searching a variant title finds the item (and the autocomplete can
        // reconcile it). Display keeps the main title.
        $this->maybeAddList($doc, 'alt_title_txt', $values->displays('dcterms:alternative'));

        // A thumbnailed media is our proxy for "has primary media", which is
        // the precondition the HF pipeline used to emit the IIIF manifest.
        if ($thumbnailUrl !== null) {
            $doc['thumbnail_url']  = $thumbnailUrl;
            $doc['iiif_manifest']  = SiteUrls::iiifManifestUrl($oid);
        }

        return $doc;
    }

    /**
     * creator / language / newspaper facets, plus country_ss derived from the
     * newspaper name. Used by articles / publications / documents / audiovisual
     * (references override creator + country — see ReferenceMapper).
     *
     * @param array<string, mixed> $doc
     */
    protected function addCommonFacets(array &$doc, PropertyValues $values): void
    {
        $creators = $values->displays('dcterms:creator');
        $this->maybeAddList($doc, 'creator_ss', $creators);
        if ($creators !== []) {
            // creator_ss is a string[]; Typesense can't sort on it, so the
            // references page's "sort by author" sorts on this scalar instead.
            $this->maybeAdd($doc, 'creator_sort', $this->authorSortKey($creators[0]));
        }

        $this->maybeAddList($doc, 'language_ss', $values->displays('dcterms:language'));

        $newspapers = $values->displays('dcterms:publisher');
        $this->maybeAddList($doc, 'newspaper_ss', $newspapers);
        $this->maybeAddList($doc, 'country_ss', $this->countries->forNewspapers($newspapers));
    }

    /**
     * Resolve dcterms:subject + dcterms:spatial linked targets to the
     * persons_ss / places_ss / … buckets (+ entity_ids + alias FTS) via the
     * class-keyed EntityAuthority.
     *
     * @param array<string, mixed> $doc
     */
    protected function addAuthorityEntities(array &$doc, PropertyValues $values): void
    {
        $ids = array_merge(
            $values->linkedIds('dcterms:subject'),
            $values->linkedIds('dcterms:spatial'),
        );
        foreach ($this->authority->resolve($ids) as $field => $vals) {
            $doc[$field] = isset($doc[$field])
                ? array_values(array_unique(array_merge($doc[$field], $vals)))
                : $vals;
        }

        // MERGED subject facet: every dcterms:subject display value in one
        // list, regardless of which entity class it resolves to (persons,
        // organisations and topics together). dcterms:spatial stays out.
        $this->maybeAddList($doc, 'subjects_ss', $values->displays('dcterms:subject'));
    }

    /**
     * date / pub_year / date_decade_ss from dcterms:date.
     *
     * @param array<string, mixed> $doc
     */
    protected function addDateFields(array &$doc, PropertyValues $values): void
    {
        $iso = $values->firstScalar('dcterms:date');
        if ($iso === '') {
            return;
        }
        $epoch = $this->dateToEpoch($iso);
        $year  = $this->dateToYear($iso);
        if ($epoch !== null) {
            $doc['date'] = $epoch;
        }
        if ($year !== null) {
            $doc['pub_year'] = $year;
            $decade = (int) (floor($year / 10) * 10);
            $doc['date_decade_ss'] = ["{$decade}s"];
        }
    }

    /**
     * OCR body + word count from bibo:content, plus the has_fulltext flag:
     * true only when a bibo:content value exists AND its Omeka value-level
     * visibility is public (vpub). Restricted OCR stays INDEXED (snippet
     * highlights keep working) but is flagged as not publicly readable, so
     * the "Full text available" filter reflects what a visitor can actually
     * open on the item page. Always sets the flag — primary-source subsets
     * without OCR get an honest `false`; references never call this, so
     * the field stays absent there (and the facet hidden).
     *
     * (lda_topic_label is dropped in the MySQL model — HF-only LDA.)
     *
     * @param array<string, mixed> $doc
     */
    protected function addBodyFields(array &$doc, PropertyValues $values): void
    {
        $hasPublic = false;
        $ocr = '';
        foreach ($values->rows('bibo:content') as $v) {
            $s = trim((string) ($v['value'] ?? ''));
            if ($s === '') {
                continue;
            }
            if ($ocr === '') {
                $ocr = $s;
            }
            if ($v['vpub'] ?? true) {
                $hasPublic = true;
            }
        }
        $doc['has_fulltext'] = $hasPublic;
        if ($ocr === '') {
            return;
        }
        $doc['ocr_text'] = $ocr;
        // Unicode-aware word count (French accents stay inside words).
        $doc['nb_words'] = preg_match_all('/[\p{L}\p{N}]+/u', $ocr);
    }

    /**
     * Public-safe display body. Prefer the curated AI summary, then fall back
     * to the resource's ordinary Dublin Core description. The fallback gives
     * sparse non-press records (notably photographs and audiovisual items) a
     * useful card body without changing the article/document preference.
     *
     * @param array<string, mixed> $doc
     */
    protected function addDescription(array &$doc, PropertyValues $values): void
    {
        $description = $values->firstPublicLiteral('bibo:shortDescription');
        if ($description === '') {
            $description = $values->firstPublicLiteral('dcterms:description');
        }
        $this->maybeAdd($doc, 'abstract', $description);
    }

    /**
     * Three-model AI sentiment. Centralité + polarité are categorical labels
     * (linked or literal — disp() handles both); subjectivité is a linked
     * category resolved to its 1–5 score for every model.
     *
     * The Omeka term infix and the document field prefix name the same model
     * in two spellings — see {@see SENTIMENT_MODELS}.
     *
     * @param array<string, mixed> $doc
     */
    protected function addAiSentiment(array &$doc, PropertyValues $values): void
    {
        foreach (self::SENTIMENT_MODELS as $model => $field) {
            $cent = $values->firstDisplay("iwac:{$model}Centralite");
            $pol  = $values->firstDisplay("iwac:{$model}Polarite");
            if ($cent !== '') {
                $doc["{$field}_centralite_ss"] = [$cent];
            }
            if ($pol !== '') {
                $doc["{$field}_polarite_ss"] = [$pol];
            }

            $subjLabel = $values->firstDisplay("iwac:{$model}SubjectiviteScore");
            $score = self::SUBJECTIVITE_LABELS[$subjLabel]
                ?? (is_numeric($subjLabel) ? (float) $subjLabel : null);
            if ($score !== null) {
                $doc["{$field}_subjectivite"] = $score;
            }
        }
    }

    // ────────────────────────────────────────────────────────────────────
    // Document assembly (final — discourage subclass override)
    // ────────────────────────────────────────────────────────────────────

    /**
     * Set a scalar field, but only when it has a value — Typesense optional
     * fields are absent, not empty-string.
     *
     * @param array<string,mixed> $doc
     */
    final protected function maybeAdd(array &$doc, string $key, string $value): void
    {
        if ($value !== '') {
            $doc[$key] = $value;
        }
    }

    /**
     * @param array<string,mixed> $doc
     * @param list<string> $values
     */
    final protected function maybeAddList(array &$doc, string $key, array $values): void
    {
        if ($values !== []) {
            $doc[$key] = $values;
        }
    }

    /**
     * Surname-first, lowercased, diacritics-folded sort key from one author
     * name — so "sort by author" reads like a bibliography and collates A–Z.
     */
    final protected function authorSortKey(string $author): string
    {
        $name = trim($author);
        if ($name === '') {
            return '';
        }
        $parts = preg_split('/\s+/', $name) ?: [$name];
        if (count($parts) > 1) {
            $surname = array_pop($parts);
            $name = $surname . ' ' . implode(' ', $parts);
        }
        return $this->foldDiacritics(mb_strtolower($name, 'UTF-8'));
    }

    /** Fold the French/Latin-1 diacritics that occur in bylines down to ASCII. */
    final protected function foldDiacritics(string $s): string
    {
        static $map = [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
            'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
            'œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss',
        ];
        return strtr($s, $map);
    }

    /** ISO-8601 (year, year-month, or full) → Unix epoch seconds (UTC, period start). */
    final protected function dateToEpoch(string $iso): ?int
    {
        if ($iso === '') {
            return null;
        }
        if (preg_match('/^\d{4}$/', $iso)) {
            $iso .= '-01-01';
        } elseif (preg_match('/^\d{4}-\d{2}$/', $iso)) {
            $iso .= '-01';
        }
        $ts = strtotime($iso . ' UTC');
        return is_int($ts) ? $ts : null;
    }

    final protected function dateToYear(string $iso): ?int
    {
        return preg_match('/^(\d{4})/', $iso, $m) ? (int) $m[1] : null;
    }
}
