<?php

declare(strict_types=1);

namespace App\Security;

use App\Exception\ApiProblemException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Reads the X-Acting-As header (spec §5.15, CLAUDE.md): lets a
 * manager act as one of their managed (child) profiles. Only parses
 * the header here; verifying the acting user actually manages that
 * profile is a security voter's job, wired when comptes/profils
 * enfants land (lots 1 and 4 bis).
 */
final class ActingAsResolver
{
    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function getRequestedProfileId(): ?Uuid
    {
        $header = $this->requestStack->getCurrentRequest()?->headers->get('X-Acting-As');
        if (null === $header || '' === $header) {
            return null;
        }

        if (!Uuid::isValid($header)) {
            throw new ApiProblemException('acting_as.invalid', 'The X-Acting-As header is not a valid identifier.', 400);
        }

        return Uuid::fromString($header);
    }
}
