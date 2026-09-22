<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Entity\User;

/**
 * The "vue propriétaire" shape for a user (self or, from lot 2 on,
 * an accepted friend) — profile fields only, no relation data.
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
            'avatarUrl' => null !== $user->getAvatarPath() ? $this->storagePublicBaseUrl.'/'.$user->getAvatarPath() : null,
            'birthDay' => $user->getBirthDay(),
            'birthMonth' => $user->getBirthMonth(),
            'birthYear' => $user->getBirthYear(),
            'locale' => $user->getLocale(),
            'isOnboarded' => $user->isOnboarded(),
            'emailVerified' => null !== $user->getEmailVerifiedAt(),
        ];
    }
}
