<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Entity\User;
use App\Repository\IdeaRepository;
use App\Repository\ManagedProfileInvitationRepository;
use App\Repository\ManagerInvitationRepository;
use App\Service\MediaUrls;

/** A child profile as its manager sees it in « Mes enfants » (spec §5.15). */
final class ManagedProfileNormalizer
{
    public function __construct(
        private readonly UserNormalizer $users,
        private readonly IdeaRepository $ideas,
        private readonly ManagedProfileInvitationRepository $invitations,
        private readonly ManagerInvitationRepository $managerInvitations,
        private readonly MediaUrls $media,
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
            // « Partager avec mes amis » (spec §11 décision 59).
            'shareWithManagerFriends' => $child->isSharedWithManagerFriends(),
            // Its managers, and the second one invited (spec §11 décision 60).
            'managers' => array_map(fn (User $m) => [
                'id' => $m->getId()->toRfc4122(),
                'displayName' => $m->getDisplayName(),
                'avatarUrl' => $this->media->url($m->getAvatarPath()),
            ], $child->getManagers()),
            'managerInvitation' => null !== ($managerInvitation = $this->managerInvitations->findOpenForChild($child))
                ? ['user' => ['id' => $managerInvitation->getInvitee()->getId()->toRfc4122(), 'displayName' => $managerInvitation->getInvitee()->getDisplayName()], 'expiresAt' => $managerInvitation->getExpiresAt()->format(\DATE_ATOM)]
                : null,
            'invitation' => null !== $invitation && $invitation->isUsable()
                ? ['email' => $invitation->getEmail(), 'expiresAt' => $invitation->getExpiresAt()->format(\DATE_ATOM)]
                : null,
        ];
    }
}
