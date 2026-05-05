<?php
declare(strict_types=1);

namespace IwacSearch\Form;

use Laminas\Form\Element\Csrf;
use Laminas\Form\Form;

/**
 * Minimal form for the maintenance page.
 *
 * Carries only a CSRF token — the action (reindex vs. sync stopwords)
 * is determined by the route the form posts to, not by a hidden field.
 * Two route actions = two POST endpoints = no need to discriminate
 * inside the form payload.
 *
 * Both buttons on the page reuse the same form instance via the view
 * helper; the rendered <form> tags wrap each submit button so the CSRF
 * token gets a fresh nonce per render.
 */
class MaintenanceForm extends Form
{
    public function init(): void
    {
        $this->add([
            'name'    => 'maintenance_csrf',
            'type'    => Csrf::class,
            'options' => [
                'csrf_options' => [
                    // 10 min — generous enough that a reload doesn't expire
                    // the token, tight enough that a stale tab can't replay
                    // an old token after a long idle.
                    'timeout' => 600,
                ],
            ],
        ]);
    }
}
