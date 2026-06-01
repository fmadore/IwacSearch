<?php
declare(strict_types=1);

namespace IwacSearch\Indexer\Mapper;

use IwacSearch\Indexer\AuthorityResolver;

/**
 * Shared infrastructure for per-subset mappers.
 *
 * Subclasses should:
 *   - declare subsetName() with their HF subset key
 *   - declare typeTag() with the value to put in type_s (article|publication|...)
 *   - implement map() by calling buildBase() then layering subset-specific fields
 *
 * The protected helpers cover the parsing patterns that recur in every
 * IWAC subset: pipe-separated multi-values, ISO-ish dates with year-only
 * fallbacks, optional integer/float casts, three-model AI sentiment,
 * and authority-resolved entity buckets.
 */
abstract class AbstractMapper implements MapperInterface
{
    public function __construct(
        protected readonly AuthorityResolver $authority
    ) {
    }

    abstract public function subsetName(): string;

    /** Value placed in the document's type_s field. */
    abstract protected function typeTag(): string;

    // ────────────────────────────────────────────────────────────────────
    // Builders
    // ────────────────────────────────────────────────────────────────────

    /**
     * Build the identity + display + ACL skeleton common to every subset.
     * Returns null if the row has no Omeka ID (skip signal).
     *
     * @param  array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    protected function buildBase(array $row): ?array
    {
        $oid = $this->intOr($row['o:id'] ?? null, 0);
        if ($oid === 0) {
            return null;
        }

        $title = $this->str($row['title'] ?? '');
        $doc = [
            'id'        => (string) $oid,
            'type_s'    => $this->typeTag(),
            'title'     => $title !== '' ? $title : sprintf('[Untitled #%d]', $oid),
            'title_txt' => $title,
            'is_public' => false, // overlaid by Reindexer
        ];

        $this->maybeAdd($doc, 'identifier',    $this->str($row['identifier']    ?? ''));
        $this->maybeAdd($doc, 'omeka_url',     $this->str($row['iwac_url']      ?? ''));
        $this->maybeAdd($doc, 'thumbnail_url', $this->str($row['thumbnail']     ?? ''));
        $this->maybeAdd($doc, 'iiif_manifest', $this->str($row['iiif_manifest'] ?? ''));

        return $doc;
    }

    /**
     * Layer the country/newspaper/language/creator facets that appear in
     * articles, publications, and (sometimes) documents.
     *
     * All four fields go through the same `splitPipe()` helper so a row
     * like `"Niger|Nigeria"` becomes two distinct facet values
     * (`Niger`, `Nigeria`) rather than one literal string. The IWAC
     * upstream sometimes joins multiple countries / publishing
     * newspapers with `|`, especially for cross-border references and
     * Niger/Nigeria coverage in the Hausa press — the previous "first
     * value wins" code surfaced those joined strings as single facet
     * tokens (`Niger|Nigeria`), polluting the country dropdown.
     *
     * Centralising on splitPipe() also means new pipe-separated fields
     * (added in future schema bumps) can join this method without
     * inventing a parallel code path.
     *
     * @param array<string, mixed> $doc
     * @param array<string, mixed> $row
     */
    protected function addCommonFacets(array &$doc, array $row): void
    {
        $creators = $this->splitPipe($row['author'] ?? null);
        $this->maybeAddList($doc, 'creator_ss', $creators);
        // Scalar sort key from the FIRST author. creator_ss is a string[] and
        // Typesense can't sort on an array, so the references page's
        // "sort by author" sorts on this single-value field instead.
        if ($creators !== []) {
            $this->maybeAdd($doc, 'creator_sort', $this->authorSortKey($creators[0]));
        }

        $this->maybeAddList($doc, 'language_ss',  $this->splitPipe($row['language']  ?? null));
        $this->maybeAddList($doc, 'country_ss',   $this->splitPipe($row['country']   ?? null));
        $this->maybeAddList($doc, 'newspaper_ss', $this->splitPipe($row['newspaper'] ?? null));
    }

    /**
     * Build a single-value sort key from one author name.
     *
     * Surname-first so a "sort by author" reads like a bibliography
     * ("Marie Miran-Guyon" → "miran-guyon marie"); lowercased and with
     * Latin diacritics folded so "Ménard" sorts beside "Menard" rather
     * than after "Z" (Typesense string sort is codepoint-ordered, and
     * accented letters sit above the ASCII range). A single-token name is
     * used as-is. Pure string work — no intl dependency.
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

    /**
     * Fold the Latin-1/French diacritics that occur in the corpus's author
     * names down to ASCII, for predictable A–Z collation. Deliberately small
     * and dependency-free (no ext-intl) — it only needs to cover the
     * accented characters that actually appear in West-African scholarship
     * bylines, not the whole Unicode range.
     */
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

    /**
     * Resolve subject + spatial through the authority lookup and merge
     * the resulting persons_ss / places_ss / etc. buckets into $doc.
     *
     * @param array<string, mixed> $doc
     * @param array<string, mixed> $row
     */
    protected function addAuthorityEntities(array &$doc, array $row): void
    {
        foreach ([
            $this->authority->resolve($this->str($row['subject'] ?? '')),
            $this->authority->resolve($this->str($row['spatial'] ?? '')),
        ] as $resolved) {
            foreach ($resolved as $field => $values) {
                if (isset($doc[$field])) {
                    $doc[$field] = array_values(array_unique(array_merge($doc[$field], $values)));
                } else {
                    $doc[$field] = $values;
                }
            }
        }
    }

    /**
     * Add date / pub_year / date_decade_ss derived from pub_date.
     *
     * @param array<string, mixed> $doc
     * @param array<string, mixed> $row
     */
    protected function addDateFields(array &$doc, array $row): void
    {
        $iso = $this->str($row['pub_date'] ?? '');
        if ($iso === '') { return; }

        $epoch = $this->dateToEpoch($iso);
        $year  = $this->dateToYear($iso);
        if ($epoch !== null) { $doc['date'] = $epoch; }
        if ($year !== null) {
            $doc['pub_year'] = $year;
            $decade = (int) (floor($year / 10) * 10);
            $doc['date_decade_ss'] = ["{$decade}s"];
        }
    }

    /**
     * Add OCR + body metrics + LDA topic label. Only meaningful for
     * subsets with the OCR pipeline (articles, publications, documents).
     *
     * @param array<string, mixed> $doc
     * @param array<string, mixed> $row
     */
    protected function addBodyFields(array &$doc, array $row): void
    {
        $this->maybeAdd($doc, 'ocr_text',        $this->str($row['OCR'] ?? ''));
        $this->maybeAddInt($doc, 'nb_words',     $row['nb_mots'] ?? null);
        $this->maybeAdd($doc, 'lda_topic_label', $this->str($row['lda_topic_label'] ?? ''));
    }

    /**
     * Populate the public-facing `abstract` from the AI-generated
     * `descriptionAI` column.
     *
     * Distinct from references (whose `abstract` IS the real, human-written
     * abstract): for primary sources `descriptionAI` is a short, clean
     * French summary that is safe to surface publicly — unlike the
     * licensing-restricted `ocr_text`, which the scoped key excludes.
     * Articles, documents, and audiovisual carry it; publications do not
     * (they have a table of contents instead). Reusing the existing
     * `abstract` field means the result card renders one body field for
     * every type and the summary becomes lightly FTS-searchable (it's in
     * `query_by`), which only helps recall.
     *
     * @param array<string, mixed> $doc
     * @param array<string, mixed> $row
     */
    protected function addDescription(array &$doc, array $row): void
    {
        $this->maybeAdd($doc, 'abstract', $this->str($row['descriptionAI'] ?? ''));
    }

    /**
     * Add the three-model AI sentiment fields if present in the row.
     * Articles are the only subset that currently carries these.
     *
     * @param array<string, mixed> $doc
     * @param array<string, mixed> $row
     */
    protected function addAiSentiment(array &$doc, array $row): void
    {
        foreach (['gemini', 'chatgpt', 'mistral'] as $model) {
            $cent = $this->str($row["{$model}_centralite_islam_musulmans"] ?? '');
            $pol  = $this->str($row["{$model}_polarite"]                   ?? '');
            $subj = $row["{$model}_subjectivite_score"] ?? null;

            if ($cent !== '') { $doc["{$model}_centralite_ss"] = [$cent]; }
            if ($pol  !== '') { $doc["{$model}_polarite_ss"]   = [$pol]; }
            if (is_numeric($subj)) {
                $doc["{$model}_subjectivite"] = (float) $subj;
            }
        }
    }

    // ────────────────────────────────────────────────────────────────────
    // Low-level helpers (final to discourage subclass override)
    // ────────────────────────────────────────────────────────────────────

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

    /** @param array<string,mixed> $doc */
    final protected function maybeAddInt(array &$doc, string $key, mixed $value): void
    {
        if (is_numeric($value)) {
            $doc[$key] = (int) $value;
        }
    }

    final protected function str(mixed $v): string
    {
        return is_string($v) ? trim($v) : '';
    }

    final protected function intOr(mixed $v, int $default): int
    {
        return is_numeric($v) ? (int) $v : $default;
    }

    /** @return list<string> */
    final protected function splitPipe(?string $v): array
    {
        if ($v === null || $v === '') {
            return [];
        }
        return array_values(array_filter(
            array_map('trim', explode('|', $v)),
            static fn(string $s): bool => $s !== ''
        ));
    }

    /**
     * Parse an ISO-8601 date that may be year-only, year-month, or full.
     * Returns Unix epoch seconds (UTC) for the start of the period.
     */
    final protected function dateToEpoch(string $iso): ?int
    {
        if ($iso === '')                            { return null; }
        if (preg_match('/^\d{4}$/', $iso))           { $iso .= '-01-01'; }
        elseif (preg_match('/^\d{4}-\d{2}$/', $iso)) { $iso .= '-01'; }
        $ts = strtotime($iso . ' UTC');
        return is_int($ts) ? $ts : null;
    }

    final protected function dateToYear(string $iso): ?int
    {
        return preg_match('/^(\d{4})/', $iso, $m) ? (int) $m[1] : null;
    }
}
