<?php
declare(strict_types=1);

namespace IwacSearch\Indexer\Mapper;

use IwacSearch\Indexer\CountryResolver;
use IwacSearch\Indexer\EntityAuthority;

/**
 * Shared infrastructure for the content mappers, reading Omeka grouped
 * property values (the OmekaSourceReader shape) rather than HF rows.
 *
 * $values is term => list of value rows, each:
 *   ['vrid' => ?int, 'value' => ?string, 'uri' => ?string, 'title' => ?string]
 * where vrid + title come from a value_resource link, value is the literal,
 * uri is a uri value's @id.
 *
 * Subclasses declare classIds()/typeTag()/readTerms() and compose map() from
 * the protected builders. Field derivations mirror the IWAC-Hugging-Face
 * pipeline 1:1 (verified by the Phase 0 parity spike) so the produced
 * documents match the previous HF-built collection.
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
    protected const DESCRIPTION_TERMS = ['bibo:shortDescription'];
    protected const SENTIMENT_TERMS = [
        'iwac:geminiCentralite', 'iwac:geminiPolarite', 'iwac:geminiSubjectiviteScore',
        'iwac:chatgptCentralite', 'iwac:chatgptPolarite', 'iwac:chatgptSubjectiviteScore',
        'iwac:mistralCentralite', 'iwac:mistralPolarite', 'iwac:mistralSubjectiviteScore',
    ];

    /**
     * Subjectivité is stored as a linked-resource CATEGORY (not a number) for
     * ALL THREE models — confirmed live (the iwac-data note claiming only
     * Mistral is categorical is stale). The HF pipeline converts the label to
     * the 1–5 score; we do the same. Scale derived empirically by pairing the
     * Omeka label against the HF *_subjectivite_score for 25 articles.
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
     * @param array<string, list<array{vrid:?int,value:?string,uri:?string,title:?string}>> $values
     * @return array<string, mixed>
     */
    protected function buildBase(array $item, array $values, ?string $thumbnailUrl): array
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

        $this->maybeAdd($doc, 'identifier', $this->firstScalar($values, 'dcterms:identifier'));
        // Alternative titles (dcterms:alternative) — a second FTS channel so
        // searching a variant title finds the item (and the autocomplete can
        // reconcile it). Display keeps the main title.
        $this->maybeAddList($doc, 'alt_title_txt', $this->disp($values, 'dcterms:alternative'));

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
     * @param array<string, list<array{vrid:?int,value:?string,uri:?string,title:?string}>> $values
     */
    protected function addCommonFacets(array &$doc, array $values): void
    {
        $creators = $this->disp($values, 'dcterms:creator');
        $this->maybeAddList($doc, 'creator_ss', $creators);
        if ($creators !== []) {
            // creator_ss is a string[]; Typesense can't sort on it, so the
            // references page's "sort by author" sorts on this scalar instead.
            $this->maybeAdd($doc, 'creator_sort', $this->authorSortKey($creators[0]));
        }

        $this->maybeAddList($doc, 'language_ss', $this->disp($values, 'dcterms:language'));

        $newspapers = $this->disp($values, 'dcterms:publisher');
        $this->maybeAddList($doc, 'newspaper_ss', $newspapers);
        $this->maybeAddList($doc, 'country_ss', $this->countries->forNewspapers($newspapers));
    }

    /**
     * Resolve dcterms:subject + dcterms:spatial linked targets to the
     * persons_ss / places_ss / … buckets (+ entity_ids + alias FTS) via the
     * class-keyed EntityAuthority.
     *
     * @param array<string, mixed> $doc
     * @param array<string, list<array{vrid:?int,value:?string,uri:?string,title:?string}>> $values
     */
    protected function addAuthorityEntities(array &$doc, array $values): void
    {
        $ids = array_merge(
            $this->linkedIds($values, 'dcterms:subject'),
            $this->linkedIds($values, 'dcterms:spatial'),
        );
        foreach ($this->authority->resolve($ids) as $field => $vals) {
            $doc[$field] = isset($doc[$field])
                ? array_values(array_unique(array_merge($doc[$field], $vals)))
                : $vals;
        }

        // MERGED subject facet: every dcterms:subject display value in one
        // list, regardless of which entity class it resolves to (persons,
        // organisations and topics together). dcterms:spatial stays out.
        $this->maybeAddList($doc, 'subjects_ss', $this->disp($values, 'dcterms:subject'));
    }

    /**
     * date / pub_year / date_decade_ss from dcterms:date.
     *
     * @param array<string, mixed> $doc
     * @param array<string, list<array{vrid:?int,value:?string,uri:?string,title:?string}>> $values
     */
    protected function addDateFields(array &$doc, array $values): void
    {
        $iso = $this->firstScalar($values, 'dcterms:date');
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
     * @param array<string, list<array{vrid:?int,value:?string,uri:?string,title:?string,vpub:bool}>> $values
     */
    protected function addBodyFields(array &$doc, array $values): void
    {
        $hasPublic = false;
        $ocr = '';
        foreach ($values['bibo:content'] ?? [] as $v) {
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
     * Public-safe display body from the AI summary (bibo:shortDescription).
     *
     * @param array<string, mixed> $doc
     * @param array<string, list<array{vrid:?int,value:?string,uri:?string,title:?string}>> $values
     */
    protected function addDescription(array &$doc, array $values): void
    {
        $this->maybeAdd($doc, 'abstract', $this->firstLiteral($values, 'bibo:shortDescription'));
    }

    /**
     * Three-model AI sentiment. Centralité + polarité are categorical labels
     * (linked or literal — disp() handles both); subjectivité is a linked
     * category resolved to its 1–5 score for every model.
     *
     * @param array<string, mixed> $doc
     * @param array<string, list<array{vrid:?int,value:?string,uri:?string,title:?string}>> $values
     */
    protected function addAiSentiment(array &$doc, array $values): void
    {
        foreach (['gemini', 'chatgpt', 'mistral'] as $model) {
            $cent = $this->firstDisp($values, "iwac:{$model}Centralite");
            $pol  = $this->firstDisp($values, "iwac:{$model}Polarite");
            if ($cent !== '') {
                $doc["{$model}_centralite_ss"] = [$cent];
            }
            if ($pol !== '') {
                $doc["{$model}_polarite_ss"] = [$pol];
            }

            $subjLabel = $this->firstDisp($values, "iwac:{$model}SubjectiviteScore");
            $score = self::SUBJECTIVITE_LABELS[$subjLabel]
                ?? (is_numeric($subjLabel) ? (float) $subjLabel : null);
            if ($score !== null) {
                $doc["{$model}_subjectivite"] = $score;
            }
        }
    }

    // ────────────────────────────────────────────────────────────────────
    // Value extraction (final — discourage subclass override)
    // ────────────────────────────────────────────────────────────────────

    /**
     * Display values for a term: the linked-resource title when present, else
     * the literal. The multi-value facet primitive.
     *
     * @param array<string, list<array{vrid:?int,value:?string,uri:?string,title:?string}>> $values
     * @return list<string>
     */
    final protected function disp(array $values, string $term): array
    {
        $out = [];
        foreach ($values[$term] ?? [] as $v) {
            $s = trim((string) (($v['title'] ?? '') !== '' ? $v['title'] : ($v['value'] ?? '')));
            if ($s !== '') {
                $out[] = $s;
            }
        }
        return array_values(array_unique($out));
    }

    /** First display value (title||literal) or ''. */
    final protected function firstDisp(array $values, string $term): string
    {
        return $this->disp($values, $term)[0] ?? '';
    }

    /**
     * First literal (@value) only — for fields that are always literals
     * (OCR, abstract).
     *
     * @param array<string, list<array{vrid:?int,value:?string,uri:?string,title:?string}>> $values
     */
    final protected function firstLiteral(array $values, string $term): string
    {
        foreach ($values[$term] ?? [] as $v) {
            $s = trim((string) ($v['value'] ?? ''));
            if ($s !== '') {
                return $s;
            }
        }
        return '';
    }

    /**
     * First scalar: literal, else uri @id (for identifier / date / DOI-like).
     *
     * @param array<string, list<array{vrid:?int,value:?string,uri:?string,title:?string}>> $values
     */
    final protected function firstScalar(array $values, string $term): string
    {
        foreach ($values[$term] ?? [] as $v) {
            $s = trim((string) (($v['value'] ?? '') !== '' ? $v['value'] : ($v['uri'] ?? '')));
            if ($s !== '') {
                return $s;
            }
        }
        return '';
    }

    /**
     * value_resource ids for a term (linked-resource targets only).
     *
     * @param array<string, list<array{vrid:?int,value:?string,uri:?string,title:?string}>> $values
     * @return list<int>
     */
    final protected function linkedIds(array $values, string $term): array
    {
        $out = [];
        foreach ($values[$term] ?? [] as $v) {
            if ($v['vrid'] !== null) {
                $out[] = $v['vrid'];
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $doc */
    final protected function maybeAdd(array &$doc, string $key, string $value): void
    {
        if ($value !== '') {
            $doc[$key] = $value;
        }
    }

    /** @param array<string,mixed> $doc @param list<string> $values */
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
