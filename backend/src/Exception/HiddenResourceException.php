<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown whenever a resource is hidden by the "règle d'or de la surprise"
 * (CLAUDE.md règle 1): a suggestion, reservation, comment, contribution or
 * private idea invisible to the current actor. Always renders as 404,
 * never 403 — a 403 would itself leak the resource's existence.
 */
final class HiddenResourceException extends ApiProblemException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('resource.not_found', 'Resource not found.', 404, $previous);
    }
}
