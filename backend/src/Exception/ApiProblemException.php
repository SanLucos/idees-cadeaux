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
        private readonly array $extra = [],
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

    /**
     * Extra problem+json members (e.g. who already reserved, spec §5.7).
     * Only for errors whose audience may already see that data: never
     * on a path an owner can reach (CLAUDE.md règle 1, "erreurs").
     *
     * @return array<string, mixed>
     */
    public function getExtra(): array
    {
        return $this->extra;
    }
}
