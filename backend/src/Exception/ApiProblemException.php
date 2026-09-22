<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Base class for domain exceptions that must surface as problem+json
 * with a stable `code` the client translates (CLAUDE.md règle 7).
 */
class ApiProblemException extends \RuntimeException implements TranslatableApiExceptionInterface
{
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $statusCode = 422,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
