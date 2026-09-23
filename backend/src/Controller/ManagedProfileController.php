<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Exception\HiddenResourceException;
use App\Repository\IdeaRepository;
use App\Repository\ManagedProfileInvitationRepository;
use App\Repository\UserRepository;
use App\Serializer\UserNormalizer;
use App\Service\ManagedProfileInvitations;
use App\Service\ProfileFields;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * "Mes enfants" (spec §5.15, §7 `/managed-profiles`): the manager's
 * own endpoints, never reachable while acting as a child
 * (ActingAsGuardListener). Someone else's profile is a 404.
 */
final class ManagedProfileController
{
    public const int MAX_PER_MANAGER = 10;
    public const string DELETION_GRACE = '14 days';

    public function __construct(
        private readonly UserRepository $users,
        private readonly IdeaRepository $ideas,
        private readonly ManagedProfileInvitationRepository $invitations,
        private readonly ManagedProfileInvitations $invitationService,
        private readonly UserNormalizer $userNormalizer,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/managed-profiles', name: 'managed_profiles_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $me): JsonResponse
    {
        return new JsonResponse(array_map(
            fn (User $child) => $this->normalize($child),
            $this->users->findBy(['managedBy' => $me], ['createdAt' => 'ASC']),
        ));
    }

    /**
     * Spec §5.15 "création": pseudo required, birthday optional, and the
     * explicit parental-authority consent (§5.13), timestamped.
     */
    #[Route('/api/managed-profiles', name: 'managed_profiles_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $me): JsonResponse
    {
        if ($me->isManaged()) {
            throw new ApiProblemException('acting_as.not_allowed', 'A managed profile cannot manage profiles.', 403);
        }

        $body = self::decode($request);
        if (true !== ($body['parentalConsent'] ?? null)) {
            throw new ApiProblemException('managed_profile.consent_required', 'Parental consent is required.', 422);
        }
        if (\count($this->users->findBy(['managedBy' => $me])) >= self::MAX_PER_MANAGER) {
            throw new ApiProblemException('managed_profile.limit_reached', 'Maximum of 10 managed profiles reached.', 422);
        }

        $id = null;
        if (isset($body['id'])) {
            if (!\is_string($body['id']) || !Uuid::isValid($body['id'])) {
                throw new ApiProblemException('validation.invalid_id', 'The id must be a UUID.', 422);
            }
            if (null !== $existing = $this->users->find($body['id'])) {
                // Idempotent replay (spec §8), or someone else's id.
                if ($existing->isManagedBy($me)) {
                    return new JsonResponse($this->normalize($existing));
                }
                throw new ApiProblemException('request.conflict', 'This id is already used.', 409);
            }
            $id = Uuid::fromString($body['id']);
        }

        $child = User::createManaged($me, ProfileFields::displayName($body['displayName'] ?? ''), $id);
        ProfileFields::applyBirthDate($child, $body);

        $this->em->persist($child);
        $this->em->flush();

        return new JsonResponse($this->normalize($child), 201);
    }

    #[Route('/api/managed-profiles/{id}', name: 'managed_profiles_update', methods: ['PATCH'])]
    public function update(string $id, Request $request, #[CurrentUser] User $me): JsonResponse
    {
        $child = $this->mine($id, $me);
        $body = self::decode($request);

        if (\array_key_exists('displayName', $body)) {
            $child->setDisplayName(ProfileFields::displayName($body['displayName']));
        }
        ProfileFields::applyBirthDate($child, $body);
        $this->em->flush();

        return new JsonResponse($this->normalize($child));
    }

    /**
     * Spec §5.15/§5.13: deletion with 14 days of grace; the profile is
     * erased by App\Scheduler\DeleteScheduledProfilesTask afterwards.
     */
    #[Route('/api/managed-profiles/{id}', name: 'managed_profiles_delete', methods: ['DELETE'])]
    public function delete(string $id, #[CurrentUser] User $me): JsonResponse
    {
        $child = $this->mine($id, $me);
        if (null === $child->getDeletionScheduledAt()) {
            $child->setDeletionScheduledAt(new \DateTimeImmutable('+'.self::DELETION_GRACE));
            $this->invitations->deleteForProfile($child);
            $this->em->flush();
        }

        return new JsonResponse($this->normalize($child));
    }

    #[Route('/api/managed-profiles/{id}/cancel-deletion', name: 'managed_profiles_cancel_deletion', methods: ['POST'])]
    public function cancelDeletion(string $id, #[CurrentUser] User $me): JsonResponse
    {
        $child = $this->mine($id, $me);
        $child->setDeletionScheduledAt(null);
        $this->em->flush();

        return new JsonResponse($this->normalize($child));
    }

    /**
     * Spec §5.15 "rattacher un email". Same answer whether or not that
     * email already has an account: the invitee finds out, not the
     * manager (no enumeration).
     */
    #[Route('/api/managed-profiles/{id}/attach-email', name: 'managed_profiles_attach_email', methods: ['POST'])]
    public function attachEmail(string $id, Request $request, #[CurrentUser] User $me): JsonResponse
    {
        $child = $this->mine($id, $me);
        if (null !== $child->getDeletionScheduledAt()) {
            throw new ApiProblemException('managed_profile.deletion_scheduled', 'This profile is scheduled for deletion.', 422);
        }

        $email = mb_strtolower(trim((string) (self::decode($request)['email'] ?? '')));
        if ('' === $email || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw new ApiProblemException('validation.email_invalid', 'A valid email is required.', 422);
        }

        $this->invitationService->invite($child, $me, $email);

        return new JsonResponse($this->normalize($child), 202);
    }

    private function mine(string $id, User $me): User
    {
        $child = Uuid::isValid($id) ? $this->users->find($id) : null;
        if (null === $child || !$child->isManagedBy($me)) {
            throw new HiddenResourceException();
        }

        return $child;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(User $child): array
    {
        $invitation = $this->invitations->findForProfile($child);

        return $this->userNormalizer->normalizeForFriend($child) + [
            'ideaCount' => $this->ideas->countOwnerView($child)['published'],
            'parentalConsentAt' => $child->getParentalConsentAt()?->format(\DATE_ATOM),
            'deletionScheduledAt' => $child->getDeletionScheduledAt()?->format(\DATE_ATOM),
            'invitation' => null !== $invitation && $invitation->isUsable()
                ? ['email' => $invitation->getEmail(), 'expiresAt' => $invitation->getExpiresAt()->format(\DATE_ATOM)]
                : null,
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
