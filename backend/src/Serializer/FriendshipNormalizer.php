<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Entity\Enum\FriendshipStatus;
use App\Entity\Friendship;
use App\Entity\User;

/**
 * The requester's view never reveals a decline (CLAUDE.md règle 4):
 * it stays "pending" until the request's natural 30-day expiry, then
 * flips to "expired" — exactly as if the addressee had never acted.
 * The addressee, who made that choice, always sees the real status.
 */
final class FriendshipNormalizer
{
    public function __construct(private readonly string $storagePublicBaseUrl)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function normalize(Friendship $friendship, User $viewer): array
    {
        $displayStatus = $this->displayStatusFor($friendship, $viewer);
        $isMasked = $displayStatus !== $friendship->getStatus();
        $other = $friendship->otherParty($viewer);

        return [
            'id' => $friendship->getId()->toRfc4122(),
            'status' => $displayStatus->value,
            'origin' => $friendship->getOrigin()->value,
            'direction' => $friendship->getRequester() === $viewer ? 'outgoing' : 'incoming',
            'createdAt' => $friendship->getCreatedAt()->format(\DATE_ATOM),
            'respondedAt' => $isMasked ? null : $friendship->getRespondedAt()?->format(\DATE_ATOM),
            'user' => $this->summarize($other),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summarize(User $user): array
    {
        return [
            'id' => $user->getId()->toRfc4122(),
            'displayName' => $user->getDisplayName(),
            'avatarUrl' => null !== $user->getAvatarPath() ? $this->storagePublicBaseUrl.'/'.$user->getAvatarPath() : null,
        ];
    }

    private function displayStatusFor(Friendship $friendship, User $viewer): FriendshipStatus
    {
        if ($friendship->getRequester() === $viewer && FriendshipStatus::Declined === $friendship->getStatus()) {
            return $friendship->getExpiresAt() > new \DateTimeImmutable() ? FriendshipStatus::Pending : FriendshipStatus::Expired;
        }

        return $friendship->getStatus();
    }
}
