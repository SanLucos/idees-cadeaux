<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Enum\NotificationType;
use App\Entity\ManagerInvitation;
use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Exception\HiddenResourceException;
use App\Notification\Notifier;
use App\Repository\FriendshipRepository;
use App\Repository\ManagerInvitationRepository;
use App\Repository\UserRepository;
use App\Serializer\ManagedProfileNormalizer;
use App\Service\MediaUrls;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * A child's second manager (spec §5.15, §11 décision 60): invited among
 * the inviting manager's adult friends, accepted with the parental
 * consent, two managers at most, both with the same rights — each may
 * remove the other, or leave, unless they are the last one.
 */
final class ChildManagersController
{
    public const int MAX_MANAGERS = 2;

    public function __construct(
        private readonly UserRepository $users,
        private readonly FriendshipRepository $friendships,
        private readonly ManagerInvitationRepository $invitations,
        private readonly ManagedProfileNormalizer $normalizer,
        private readonly Notifier $notifier,
        private readonly MediaUrls $media,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/managed-profiles/{id}/manager-invitation', name: 'child_manager_invite', methods: ['POST'])]
    public function invite(string $id, Request $request, #[CurrentUser] User $me): JsonResponse
    {
        $child = $this->managedBy($id, $me);
        if (null !== $child->getDeletionScheduledAt()) {
            throw new ApiProblemException('managed_profile.deletion_scheduled', 'This profile is scheduled for deletion.', 422);
        }
        if (\count($child->getManagers()) >= self::MAX_MANAGERS) {
            throw new ApiProblemException('managed_profile.managers_full', 'This profile already has two managers.', 422);
        }
        if (null !== $this->invitations->findOpenForChild($child)) {
            throw new ApiProblemException('managed_profile.manager_invitation_pending', 'An invitation is already pending.', 422);
        }

        // Only an adult friend of mine: anyone else is « not found », as a stranger would be.
        $userId = self::decode($request)['userId'] ?? null;
        $invitee = \is_string($userId) && Uuid::isValid($userId) ? $this->users->find($userId) : null;
        if (null === $invitee || $invitee->isManaged() || !$this->friendships->areFriends($me, $invitee)) {
            throw new ApiProblemException('managed_profile.manager_not_a_friend', 'Invite one of your friends.', 422);
        }

        $this->invitations->deleteForChild($child);
        $invitation = new ManagerInvitation($child, $invitee, $me);
        $this->em->persist($invitation);
        $this->em->flush();

        $this->notifier->notify(NotificationType::ManagerInvitationReceived, [$invitee], [
            'actor' => Notifier::person($me),
            'child' => Notifier::person($child),
            'invitation' => ['id' => $invitation->getId()->toRfc4122()],
        ], $me);

        return new JsonResponse($this->normalizer->normalize($child), 201);
    }

    #[Route('/api/managed-profiles/{id}/manager-invitation', name: 'child_manager_invitation_cancel', methods: ['DELETE'])]
    public function cancel(string $id, #[CurrentUser] User $me): JsonResponse
    {
        $child = $this->managedBy($id, $me);
        $this->invitations->deleteForChild($child);

        return new JsonResponse($this->normalizer->normalize($child));
    }

    /** Remove the other manager, or leave; never the last one. */
    #[Route('/api/managed-profiles/{id}/managers/{managerId}', name: 'child_manager_remove', methods: ['DELETE'])]
    public function remove(string $id, string $managerId, #[CurrentUser] User $me): JsonResponse
    {
        $child = $this->managedBy($id, $me);
        $manager = Uuid::isValid($managerId) ? $this->users->find($managerId) : null;
        if (null === $manager || !$child->isManagedBy($manager)) {
            throw new HiddenResourceException();
        }
        if (\count($child->getManagers()) < 2) {
            throw new ApiProblemException('managed_profile.last_manager', 'A child profile keeps at least one manager.', 422);
        }

        $child->removeManager($manager);
        $this->em->flush();

        // Leaving: the profile is no longer theirs to see.
        return $manager === $me ? new JsonResponse(null, 204) : new JsonResponse($this->normalizer->normalize($child));
    }

    #[Route('/api/manager-invitations', name: 'manager_invitations_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $me): JsonResponse
    {
        return new JsonResponse(array_map($this->describe(...), $this->invitations->findOpenForInvitee($me)));
    }

    #[Route('/api/manager-invitations/{id}/accept', name: 'manager_invitation_accept', methods: ['POST'])]
    public function accept(string $id, Request $request, #[CurrentUser] User $me): JsonResponse
    {
        $invitation = $this->mine($id, $me);
        $child = $invitation->getChild();

        if (true !== (self::decode($request)['parentalConsent'] ?? null)) {
            throw new ApiProblemException('managed_profile.consent_required', 'Parental consent is required.', 422);
        }
        if (\count($this->users->findChildrenOf($me)) >= ManagedProfileController::MAX_PER_MANAGER) {
            throw new ApiProblemException('managed_profile.limit_reached', 'Maximum of 10 managed profiles reached.', 422);
        }
        // Full meanwhile, or its inviter no longer manages it: as good as expired.
        if (\count($child->getManagers()) >= self::MAX_MANAGERS || !$child->isManagedBy($invitation->getInvitedBy())) {
            $this->em->remove($invitation);
            $this->em->flush();
            throw new HiddenResourceException();
        }

        $child->addManager($me);
        $this->em->remove($invitation);
        $this->em->flush();

        $this->notifier->notify(NotificationType::ManagerInvitationAccepted, [$invitation->getInvitedBy()], [
            'actor' => Notifier::person($me),
            'child' => Notifier::person($child),
        ], $me);

        return new JsonResponse($this->normalizer->normalize($child));
    }

    #[Route('/api/manager-invitations/{id}/decline', name: 'manager_invitation_decline', methods: ['POST'])]
    public function decline(string $id, #[CurrentUser] User $me): JsonResponse
    {
        $this->em->remove($this->mine($id, $me));
        $this->em->flush();

        return new JsonResponse(null, 204);
    }

    private function managedBy(string $id, User $me): User
    {
        $child = Uuid::isValid($id) ? $this->users->find($id) : null;
        if (null === $child || !$child->isManagedBy($me)) {
            throw new HiddenResourceException();
        }

        return $child;
    }

    private function mine(string $id, User $me): ManagerInvitation
    {
        $invitation = Uuid::isValid($id) ? $this->invitations->find($id) : null;
        if (null === $invitation || $invitation->getInvitee() !== $me || !$invitation->isUsable()) {
            throw new HiddenResourceException();
        }

        return $invitation;
    }

    /**
     * What the invitee learns before accepting: who invites them, and the
     * child's pseudo and avatar — nothing of its list.
     *
     * @return array<string, mixed>
     */
    private function describe(ManagerInvitation $invitation): array
    {
        $child = $invitation->getChild();

        return [
            'id' => $invitation->getId()->toRfc4122(),
            'expiresAt' => $invitation->getExpiresAt()->format(\DATE_ATOM),
            'invitedBy' => Notifier::person($invitation->getInvitedBy()),
            'child' => Notifier::person($child) + ['avatarUrl' => $this->media->url($child->getAvatarPath())],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(Request $request): array
    {
        $body = json_decode($request->getContent() ?: '{}', true);
        if (!\is_array($body)) {
            throw new ApiProblemException('validation.invalid_body', 'Malformed JSON body.', 400);
        }

        return $body;
    }
}
