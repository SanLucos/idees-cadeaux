<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Repository\UserRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Who the current request acts as (spec §5.15, `X-Acting-As`).
 *
 * - human(): the authenticated account, always an adult.
 * - actor(): the managed profile named by the header — only if the
 *   human is its manager and it isn't scheduled for deletion — or the
 *   human when there's no header. Everything a request creates is
 *   attributed to the actor ("ces actions sont attribuées à l'enfant").
 *
 * A header naming anything else (someone else's child, an adult, an
 * unknown id) is refused with the same 403, never silently ignored.
 */
final class ActingContext implements ResetInterface
{
    private ?User $actor = null;

    public function __construct(
        private readonly Security $security,
        private readonly ActingAsResolver $header,
        private readonly UserRepository $users,
    ) {
    }

    public function human(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }

    public function actor(): ?User
    {
        if (null !== $this->actor) {
            return $this->actor;
        }

        $human = $this->human();
        $profileId = $this->header->getRequestedProfileId();
        if (null === $human || null === $profileId) {
            return $human;
        }

        $profile = $this->users->find($profileId);
        if (null === $profile || !$profile->isManagedBy($human) || null !== $profile->getDeletionScheduledAt()) {
            throw new ApiProblemException('acting_as.forbidden', 'You cannot act as this profile.', 403);
        }

        return $this->actor = $profile;
    }

    public function isActing(): bool
    {
        return null !== $this->header->getRequestedProfileId();
    }

    public function reset(): void
    {
        $this->actor = null;
    }
}
