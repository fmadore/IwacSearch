<?php

declare(strict_types=1);

namespace IwacSearch\Tests\Support;

use Laminas\View\Renderer\PhpRenderer;

/**
 * Renders one of the module's .phtml partials outside Omeka.
 *
 * Laminas's PhpRenderer extracts a partial's variables and includes the file
 * with `$this` bound to the renderer; this does the same, and answers the
 * three helpers the admin partials call. Escaping is htmlspecialchars, which
 * is not Laminas's escaper (that one hex-encodes far more), so tests assert
 * on structure and on values that survive both, never on escaped bytes.
 *
 * `translate()` wraps its argument in `⟦…⟧` when $markTranslations is on, so
 * a test can tell a translated source string from data printed verbatim.
 */
final class TemplateRenderer extends PhpRenderer
{
    public function __construct(private readonly bool $markTranslations = false)
    {
    }

    public function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function escapeHtmlAttr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function translate(string $message): string
    {
        return $this->markTranslations ? '⟦' . $message . '⟧' : $message;
    }

    /** @param array<string, mixed> $variables */
    public function renderTemplate(string $template, array $variables): string
    {
        $file = dirname(__DIR__, 3) . '/view/' . $template . '.phtml';
        return (function () use ($file, $variables): string {
            extract($variables);
            ob_start();
            include $file;
            return (string) ob_get_clean();
        })();
    }
}
