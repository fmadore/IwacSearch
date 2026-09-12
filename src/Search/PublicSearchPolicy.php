<?php

declare(strict_types=1);

namespace IwacSearch\Search;

/** Embedded in scoped keys; callers cannot widen these response bounds. */
final class PublicSearchPolicy
{
    /** @return array<string, mixed> */
    public static function parameters(): array
    {
        return [
            'exclude_fields' => 'ocr_text,toc_txt,embedding',
            'highlight_full_fields' => 'title_txt',
            'snippet_threshold' => 30,
            'highlight_affix_num_tokens' => 8,
        ];
    }
}
