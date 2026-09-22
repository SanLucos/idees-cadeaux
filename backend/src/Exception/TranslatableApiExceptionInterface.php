<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Marks an exception as producing a stable, translatable `code` field
 * in the problem+json error response (CLAUDE.md règle 7 — i18n).
 */
interface TranslatableApiExceptionInterface extends \Throwable
{
    /**
     * Stable machine-readable code, e.g. "friendship.already_pending".
     * Never changes across releases: the client translates it.
     */
    public function getErrorCode(): string;

    public function getStatusCode(): int;
}
