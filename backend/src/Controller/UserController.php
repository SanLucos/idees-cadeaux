<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Repository\FriendshipRepository;
use App\Repository\UserRepository;
use App\Serializer\UserNormalizer;
use App\Service\AvatarUploadService;
use App\Service\ProfileFields;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use App\Security\Attribute\ActingUser;

final class UserController
{
    public function __construct(
        private readonly UserNormalizer $normalizer,
        private readonly EntityManagerInterface $em,
        private readonly AvatarUploadService $avatarUploads,
        private readonly UserRepository $users,
        private readonly FriendshipRepository $friendships,
    ) {
    }

    #[Route('/api/users/me', name: 'users_me_get', methods: ['GET'])]
    public function me(#[ActingUser] User $user): JsonResponse
    {
        return new JsonResponse($this->normalizer->normalize($user));
    }

    /**
     * A friend's profile (spec §4 visibility table): pseudo, avatar,
     * anniversaire — never email, locale or verification state
     * (App\Serializer\UserNormalizer::normalizeForFriend). Anyone who
     * isn't an accepted friend gets the same 404 as an id that doesn't
     * exist at all.
     */
    #[Route('/api/users/{id}', name: 'users_show', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function show(string $id, #[ActingUser] User $me): JsonResponse
    {
        $target = $this->users->find($id);

        if (null === $target || (($target !== $me) && !$target->isManagedBy($me) && !$this->friendships->areFriends($me, $target))) {
            throw new ApiProblemException('resource.not_found', 'User not found.', 404);
        }

        return new JsonResponse($target === $me ? $this->normalizer->normalize($target) : $this->normalizer->normalizeForFriend($target));
    }

    /**
     * Sets the profile fields that make up onboarding (spec §5.1/§5.2):
     * pseudo (2–30 chars), birthday (day+month together, year optional).
     */
    #[Route('/api/users/me', name: 'users_me_patch', methods: ['PATCH'])]
    public function update(Request $request, #[ActingUser] User $user): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        if (!\is_array($body)) {
            throw new ApiProblemException('validation.invalid_body', 'Malformed JSON body.', 400);
        }

        if (\array_key_exists('displayName', $body)) {
            $user->setDisplayName(ProfileFields::displayName($body['displayName']));
        }

        ProfileFields::applyBirthDate($user, $body);

        if (\array_key_exists('locale', $body)) {
            $locale = (string) $body['locale'];
            if (!\in_array($locale, ['fr', 'en'], true)) {
                throw new ApiProblemException('validation.locale_invalid', 'Unsupported locale.', 422);
            }
            $user->setLocale($locale);
        }

        $this->em->flush();

        return new JsonResponse($this->normalizer->normalize($user));
    }

    #[Route('/api/users/me/avatar', name: 'users_me_avatar', methods: ['POST'])]
    public function uploadAvatar(Request $request, #[ActingUser] User $user): JsonResponse
    {
        $file = $request->files->get('avatar');
        if (null === $file) {
            throw new ApiProblemException('validation.avatar_required', 'The "avatar" file field is required.', 422);
        }

        $path = $this->avatarUploads->upload($user, $file);
        $user->setAvatarPath($path);
        $this->em->flush();

        return new JsonResponse($this->normalizer->normalize($user));
    }
}
