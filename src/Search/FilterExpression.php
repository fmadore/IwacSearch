<?php

declare(strict_types=1);

namespace IwacSearch\Search;

/**
 * Structural check for an editor-written `locked_filters` fragment.
 *
 * The fragment is NOT a privacy boundary for the live client — the scoped key
 * enforces `is_public:=true` there — but {@see InitialResponseRenderer} runs
 * it with the ADMIN key to pre-render the first page, prepending the public
 * guard and ANDing the fragment on. A fragment with an unbalanced `)` closes
 * the group early and attaches a branch the guard no longer covers:
 *
 *     type_s:=article) || (type_s:=document
 *       → is_public:=true && (type_s:=article) || (type_s:=document)
 *
 * which returns private documents whichever precedence Typesense gives `&&`
 * and `||`, and the snapshot is then cached for every visitor. So parentheses
 * must balance outside backtick-quoted values, backticks must pair, and the
 * fragment may not mention `is_public` at all.
 *
 * A backslash is refused anywhere: inside a backtick-quoted value Typesense
 * may read it as escaping the closing backtick, which would move where a
 * value ends between this scan and the server. No IWAC filter needs one.
 *
 * Checked on save ({@see \IwacSearch\Site\BlockLayout\IwacSearchBlock::onHydrate()})
 * and again before every server-side render, for blocks stored before the
 * save-time check existed. Ported from DRESearch's FilterExpression, plus the
 * backslash rule.
 */
final class FilterExpression
{
    public const MAX_LENGTH = 1000;

    /** Why the fragment is unusable, or null when it is structurally safe (an empty one is). */
    public static function problem(string $filter): ?string
    {
        if (strlen($filter) > self::MAX_LENGTH) {
            return sprintf('The locked filter is longer than %d characters.', self::MAX_LENGTH);
        }
        if (!mb_check_encoding($filter, 'UTF-8')) {
            return 'The locked filter is not valid UTF-8.'; // @translate
        }
        if (str_contains($filter, '\\')) {
            return 'The locked filter may not contain a backslash.'; // @translate
        }

        $depth = 0;
        $quoted = false;
        $outside = '';
        $length = strlen($filter);
        for ($i = 0; $i < $length; $i++) {
            $char = $filter[$i];
            if ($char === '`') {
                $quoted = !$quoted;
                continue;
            }
            if ($quoted) {
                continue;
            }
            $outside .= $char;
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth < 0) {
                    return 'The locked filter closes a parenthesis it never opened.'; // @translate
                }
            }
        }
        if ($quoted) {
            return 'The locked filter has an unterminated backtick-quoted value.'; // @translate
        }
        if ($depth !== 0) {
            return 'The locked filter has unbalanced parentheses.'; // @translate
        }
        if (preg_match('/\bis_public\b/', $outside) === 1) {
            return 'The locked filter may not refer to is_public; visibility is enforced by the module.'; // @translate
        }
        return null;
    }
}
