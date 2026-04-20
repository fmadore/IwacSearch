<?php
declare(strict_types=1);

namespace IwacSearch\Indexer;

/**
 * Converts a row from the Hugging Face dataset to a Typesense document.
 *
 * One mapper per content subset. M0 implements `articles` (the largest and
 * richest subset). publications / documents / audiovisual mappers follow
 * the same shape and land in M0+.
 *
 * The schema definition in data/schema.yaml is the source of truth for
 * field names — this mapper produces a subset of those fields. Optional
 * fields are simply omitted when absent in the source row; Typesense
 * accepts that for `optional: true` fields.
 *
 * IMPORTANT: is_public is NOT sourced from HF. The reindexer fetches the
 * authoritative ACL state from the Omeka S API and overlays it on the
 * mapped document before import. See Reindexer::overlayAclState().
 */
final class DocumentMapper
{
    public function __construct(
        private readonly AuthorityResolver $authority
    ) {
    }

    /**
     * Map an `articles` row.
     *
     * @param  array<string, mixed> $row
     * @return array<string, mixed>|null  null if the row should be skipped
     */
    public function mapArticle(array $row): ?array
    {
        $oid = $this->intOr($row['o:id'] ?? null, 0);
        if ($oid === 0) {
            return null; // skip rows with no Omeka ID
        }

        $title = $this->str($row['title'] ?? '');
        $doc = [
            'id'           => (string) $oid,
            'type_s'       => 'article',
            'title'        => $title !== '' ? $title : sprintf('[Untitled #%d]', $oid),
            'title_txt'    => $title,
            // is_public defaults to false here; the reindexer must overlay
            // the Omeka API value before import. Defaulting closed is the
            // safe choice if the overlay step fails.
            'is_public'    => false,
        ];

        // Optional identity / display
        $this->maybeAdd($doc, 'identifier',     $this->str($row['identifier'] ?? ''));
        $this->maybeAdd($doc, 'omeka_url',      $this->str($row['iwac_url']   ?? ''));
        $this->maybeAdd($doc, 'source_url',     $this->str($row['URL']        ?? ''));
        $this->maybeAdd($doc, 'thumbnail_url',  $this->str($row['thumbnail']  ?? ''));
        $this->maybeAdd($doc, 'iiif_manifest',  $this->str($row['iiif_manifest'] ?? ''));

        // Multi-value pipe-separated fields → string[]
        $this->maybeAddList($doc, 'creator_ss',   $this->splitPipe($row['author']    ?? null));
        $this->maybeAddList($doc, 'language_ss',  $this->splitPipe($row['language']  ?? null));

        // country and newspaper are single-valued in the HF dataset but the
        // schema models them as string[] for consistency with cross-corpus
        // facets (some other subsets multi-value them).
        $country   = $this->str($row['country']    ?? '');
        $newspaper = $this->str($row['newspaper']  ?? '');
        if ($country   !== '') { $doc['country_ss']   = [$country]; }
        if ($newspaper !== '') { $doc['newspaper_ss'] = [$newspaper]; }

        // Authority-resolved entities (split by Type)
        // subject + spatial both feed into the resolver.
        $subjectResolved = $this->authority->resolve($this->str($row['subject'] ?? ''));
        $spatialResolved = $this->authority->resolve($this->str($row['spatial'] ?? ''));
        foreach ([$subjectResolved, $spatialResolved] as $resolved) {
            foreach ($resolved as $field => $values) {
                if (isset($doc[$field])) {
                    $doc[$field] = array_values(array_unique(array_merge($doc[$field], $values)));
                } else {
                    $doc[$field] = $values;
                }
            }
        }

        // Time
        $epoch  = $this->dateToEpoch($this->str($row['pub_date'] ?? ''));
        $year   = $this->dateToYear($this->str($row['pub_date'] ?? ''));
        if ($epoch !== null) { $doc['date']     = $epoch; }
        if ($year  !== null) {
            $doc['pub_year'] = $year;
            $decade = (int) (floor($year / 10) * 10);
            $doc['date_decade_ss'] = ["{$decade}s"];
        }

        // OCR + body metrics
        $this->maybeAdd($doc, 'ocr_text',         $this->str($row['OCR'] ?? ''));
        $this->maybeAddInt($doc, 'nb_words',      $row['nb_mots'] ?? null);
        $this->maybeAdd($doc, 'lda_topic_label',  $this->str($row['lda_topic_label'] ?? ''));

        // AI sentiment — three models, each gets centralite_ss + polarite_ss
        // (multi-value despite being single, for consistent faceting) and
        // subjectivite as a sortable float.
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

        return $doc;
    }

    // ────────────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $doc */
    private function maybeAdd(array &$doc, string $key, string $value): void
    {
        if ($value !== '') {
            $doc[$key] = $value;
        }
    }

    /** @param array<string,mixed> $doc @param list<string> $values */
    private function maybeAddList(array &$doc, string $key, array $values): void
    {
        if ($values !== []) {
            $doc[$key] = $values;
        }
    }

    /** @param array<string,mixed> $doc */
    private function maybeAddInt(array &$doc, string $key, mixed $value): void
    {
        if (is_numeric($value)) {
            $doc[$key] = (int) $value;
        }
    }

    private function str(mixed $v): string
    {
        return is_string($v) ? trim($v) : '';
    }

    private function intOr(mixed $v, int $default): int
    {
        return is_numeric($v) ? (int) $v : $default;
    }

    /** @return list<string> */
    private function splitPipe(?string $v): array
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
     * Parse an ISO-8601 date that may be year-only, year-month, or full date.
     * Returns Unix epoch seconds (UTC) for the start of the period.
     */
    private function dateToEpoch(string $iso): ?int
    {
        if ($iso === '') {
            return null;
        }
        // Year only → "1995-01-01"
        if (preg_match('/^\d{4}$/', $iso)) {
            $iso .= '-01-01';
        } elseif (preg_match('/^\d{4}-\d{2}$/', $iso)) {
            $iso .= '-01';
        }
        $ts = strtotime($iso . ' UTC');
        return is_int($ts) ? $ts : null;
    }

    private function dateToYear(string $iso): ?int
    {
        if (preg_match('/^(\d{4})/', $iso, $m)) {
            return (int) $m[1];
        }
        return null;
    }
}
