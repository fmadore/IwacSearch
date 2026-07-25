<?php
declare(strict_types=1);

namespace IwacSearch\Indexer;

/**
 * One Omeka resource's property values, grouped by term — and the extraction
 * primitives every consumer needs to read them.
 *
 * Omeka stores a value three ways at once, and which one is "the value"
 * depends on what you are building:
 *
 *   value            the literal (@value)          — OCR, abstracts
 *   uri              a URI value's @id             — identifiers, DOIs
 *   value_resource   a link to another resource,   — subjects, spatial,
 *                    carrying that resource's title   linked categories
 *
 * so "give me the display strings for dcterms:subject" (title ‖ literal) and
 * "give me the linked ids for dcterms:subject" are different questions over
 * the same rows. Those primitives used to be reimplemented in three places —
 * `AbstractMapper::disp()` and `EntityAuthority::displays()` were
 * character-for-character identical, `firstLiteral()` existed twice, and
 * `AbstractMapper::linkedIds()` / `IncrementalIndexer::vrids()` differed only
 * in name — each carrying its own copy of the same five-line array-shape
 * annotation (that shape appeared 19 times across the indexer).
 *
 * Being an object rather than a bag of static helpers buys two things: the
 * shape annotation collapses to a type name, and it is the natural unit to
 * construct in a mapper unit test — no database, no reader.
 *
 * Instances are immutable and cheap: the constructor keeps the reader's array
 * as-is and every accessor is a read.
 */
final class PropertyValues
{
    /**
     * @param array<string, list<array{vrid:?int,value:?string,uri:?string,title:?string,vpub:bool}>> $byTerm
     *   term ("dcterms:subject") → value rows, in Omeka insertion order.
     */
    private function __construct(private readonly array $byTerm)
    {
    }

    /**
     * @param array<string, list<array{vrid:?int,value:?string,uri:?string,title:?string,vpub:bool}>> $byTerm
     */
    public static function fromRows(array $byTerm): self
    {
        return new self($byTerm);
    }

    /** An empty bag — for resources with no values on the requested terms. */
    public static function none(): self
    {
        return new self([]);
    }

    /**
     * Display values: the linked-resource title when present, else the
     * literal. The multi-value facet primitive. Deduplicated, order preserved.
     *
     * @return list<string>
     */
    public function displays(string $term): array
    {
        $out = [];
        foreach ($this->byTerm[$term] ?? [] as $v) {
            $s = trim((string) (($v['title'] ?? '') !== '' ? $v['title'] : ($v['value'] ?? '')));
            if ($s !== '') {
                $out[] = $s;
            }
        }
        return array_values(array_unique($out));
    }

    /** First display value (title ‖ literal), or ''. */
    public function firstDisplay(string $term): string
    {
        return $this->displays($term)[0] ?? '';
    }

    /**
     * Literals only (@value) — for fields that are never linked resources
     * (OCR, abstracts, coordinates). Deduplicated, order preserved.
     *
     * @return list<string>
     */
    public function literals(string $term): array
    {
        $out = [];
        foreach ($this->byTerm[$term] ?? [] as $v) {
            $s = trim((string) ($v['value'] ?? ''));
            if ($s !== '') {
                $out[] = $s;
            }
        }
        return array_values(array_unique($out));
    }

    /** First literal (@value) only, or ''. */
    public function firstLiteral(string $term): string
    {
        foreach ($this->byTerm[$term] ?? [] as $v) {
            $s = trim((string) ($v['value'] ?? ''));
            if ($s !== '') {
                return $s;
            }
        }
        return '';
    }

    /**
     * First scalar: the literal, else the uri @id. For fields catalogued
     * either way (identifier, date, DOI).
     */
    public function firstScalar(string $term): string
    {
        foreach ($this->byTerm[$term] ?? [] as $v) {
            $s = trim((string) (($v['value'] ?? '') !== '' ? $v['value'] : ($v['uri'] ?? '')));
            if ($s !== '') {
                return $s;
            }
        }
        return '';
    }

    /**
     * value_resource ids for a term (linked-resource targets only) — the
     * entity-authority lookup key. NOT deduplicated: a repeated link is a
     * repeated occurrence, and the callers that care dedupe themselves.
     *
     * @return list<int>
     */
    public function linkedIds(string $term): array
    {
        $out = [];
        foreach ($this->byTerm[$term] ?? [] as $v) {
            if (($v['vrid'] ?? null) !== null) {
                $out[] = (int) $v['vrid'];
            }
        }
        return $out;
    }

    /**
     * The raw rows for a term — the escape hatch for the one consumer that
     * needs value-level metadata rather than a projection: `has_fulltext`
     * reads each bibo:content row's `vpub` (Omeka value-level visibility) to
     * tell "OCR exists" from "OCR is publicly readable".
     *
     * @return list<array{vrid:?int,value:?string,uri:?string,title:?string,vpub:bool}>
     */
    public function rows(string $term): array
    {
        return $this->byTerm[$term] ?? [];
    }

    public function has(string $term): bool
    {
        return ($this->byTerm[$term] ?? []) !== [];
    }
}
