<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Entity\User;

/**
 * The "vue propriétaire" shape (self, GET/PATCH /users/me): every
 * account-internal field. Friends get a strictly smaller shape (spec
 * §4 visibility table: pseudo, avatar, anniversaire — never email,
 * locale or verification state) via normalizeForFriend().
 */
final class UserNormalizer
{
    public function __construct(private readonly string $storagePublicBaseUrl)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function normalize(User $user): array
    {
        return [
            'id' => $user->getId()->toRfc4122(),
            'email' => $user->getEmail(),
            'displayName' => $user->getDisplayName(),
            'avatarUrl' => $this->avatarUrl($user),
            'birthDay' => $user->getBirthDay(),
            'birthMonth' => $user->getBirthMonth(),
            'birthYear' => $user->getBirthYear(),
            'locale' => $user->getLocale(),
            'isOnboarded' => $user->isOnboarded(),
            'emailVerified' => null !== $user->getEmailVerifiedAt(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function normalizeForFriend(User $user): array
    {
        return [
            'id' => $user->getId()->toRfc4122(),
            'displayName' => $user->getDisplayName(),
            'avatarUrl' => $this->avatarUrl($user),
            'birthDay' => $user->getBirthDay(),
            'birthMonth' => $user->getBirthMonth(),
            'birthYear' => $user->getBirthYear(),
        ];
    }

    private function avatarUrl(User $user): ?string
    {
        return null !== $user->getAvatarPath() ? $this->storagePublicBaseUrl.'/'.$user->getAvatarPath() : null;
    }
}
