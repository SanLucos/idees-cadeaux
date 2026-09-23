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
            'type' => $user->getType()->value,
            'managedBy' => $this->managerOf($user),
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
            'managedBy' => $this->managerOf($user),
        ];
    }

    /**
     * @return array{id: string, displayName: string|null}|null
     */
    private function managerOf(User $user): ?array
    {
        $manager = $user->isManaged() ? $user->getManagedBy() : null;

        return null !== $manager ? ['id' => $manager->getId()->toRfc4122(), 'displayName' => $manager->getDisplayName()] : null;
    }

    private function avatarUrl(User $user): ?string
    {
        return null !== $user->getAvatarPath() ? $this->storagePublicBaseUrl.'/'.$user->getAvatarPath() : null;
    }
}
