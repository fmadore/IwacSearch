<?php

declare(strict_types=1);

namespace IwacSearch\Controller;

use Laminas\Http\Request;
use Laminas\Http\Response;
use LogicException;

/**
 * A controller's request and response as the HTTP messages they are.
 *
 * Laminas types AbstractController::getRequest() and getResponse() as the
 * protocol-neutral Stdlib interfaces, since laminas-mvc once dispatched
 * console requests too. Omeka dispatches controllers over HTTP only, so
 * these narrow them for the methods only HTTP messages have (isPost,
 * getPost, getMethod, headers, status codes), and fail with a message that
 * says so rather than with an undefined method if that ever stops holding.
 */
trait HttpMessages
{
    private function httpRequest(): Request
    {
        $request = $this->getRequest();
        if (!$request instanceof Request) {
            throw new LogicException(sprintf('IwacSearch controllers answer HTTP requests only, not %s.', get_debug_type($request)));
        }
        return $request;
    }

    private function httpResponse(): Response
    {
        $response = $this->getResponse();
        if (!$response instanceof Response) {
            throw new LogicException(sprintf('IwacSearch controllers answer with HTTP responses only, not %s.', get_debug_type($response)));
        }
        return $response;
    }
}
