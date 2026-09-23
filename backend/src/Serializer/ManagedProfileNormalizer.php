<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Entity\User;
use App\Repository\IdeaRepository;
use App\Repository\ManagedProfileInvitationRepository;

/** A child profile as its manager sees it in « Mes enfants » (spec §5.15). */
final class ManagedProfileNormalizer
{
    public function __construct(
        private readonly UserNormalizer $users,
        private readonly IdeaRepository $ideas,
        private readonly ManagedProfileInvitationRepository $invitations,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function normalize(User $child): array
    {
        $invitation = $this->invitations->findForProfile($child);

        return $this->users->normalizeForFriend($child) + [
            'ideaCount' => $this->ideas->countOwnerView($child)['published'],
            'parentalConsentAt' => $child->getParentalConsentAt()?->format(\DATE_ATOM),
            'deletionScheduledAt' => $child->getDeletionScheduledAt()?->format(\DATE_ATOM),
            'invitation' => null !== $invitation && $invitation->isUsable()
                ? ['email' => $invitation->getEmail(), 'expiresAt' => $invitation->getExpiresAt()->format(\DATE_ATOM)]
                : null,
        ];
    }
}
