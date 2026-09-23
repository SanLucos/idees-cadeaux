<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Entity\Idea;
use App\Entity\User;

/**
 * Spec §4 "Conséquences techniques" 1: distinct serialisation views.
 *
 * - normalizeForOwner(): the owner's own idea. No author, no
 *   suggestion flag — and, from lot 4, never reservation, comments,
 *   contribution, reactions nor any counter.
 * - normalizeForFriend(): what a friend of the owner (or the author of
 *   a draft) sees. Lot 4 adds the hidden interactions here.
 *
 * The manager view (lot 4 bis) and guest view (lot 7 bis) come later.
 */
final class IdeaNormalizer
{
    public function __construct(private readonly string $storagePublicBaseUrl)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function normalizeForOwner(Idea $idea): array
    {
        return $this->publicFields($idea) + [
            'view' => 'owner',
            'canEdit' => true,
            'canUnarchive' => $idea->isArchived(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function normalizeForFriend(Idea $idea, User $viewer): array
    {
        $author = $idea->getAuthor();
        $isMine = $author === $viewer;

        return $this->publicFields($idea) + [
            'view' => 'friend',
            'isSuggestion' => $idea->isSuggestion(),
            'isMine' => $isMine,
            'author' => [
                'id' => $author->getId()->toRfc4122(),
                'displayName' => $author->getDisplayName(),
                'avatarUrl' => $this->url($author->getAvatarPath()),
            ],
            'canEdit' => $isMine,
            'canUnarchive' => $idea->isArchived() && $idea->getArchivedBy() === $viewer,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publicFields(Idea $idea): array
    {
        $imagePath = $idea->getImagePath();

        return [
            'id' => $idea->getId()->toRfc4122(),
            'ownerId' => $idea->getOwner()->getId()->toRfc4122(),
            'title' => $idea->getTitle(),
            'url' => $idea->getUrl(),
            'priceAmount' => $idea->getPriceAmount(),
            'priceCurrency' => $idea->getPriceCurrency(),
            'imageUrl' => $this->url($imagePath),
            'thumbnailUrl' => $this->url(null !== $imagePath ? self::thumbnailPath($imagePath) : null),
            'note' => $idea->getNote(),
            'occasion' => $idea->getOccasion()?->getCode(),
            'visibility' => $idea->getVisibility()->value,
            'publishedAt' => $idea->getPublishedAt()?->format(\DATE_ATOM),
            'status' => $idea->getStatus()->value,
            'archivedAt' => $idea->getArchivedAt()?->format(\DATE_ATOM),
            'archiveKind' => $idea->getArchiveKind()?->value,
            'createdAt' => $idea->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $idea->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }

    public static function thumbnailPath(string $imagePath): string
    {
        return preg_replace('/\.jpg$/', '-thumb.jpg', $imagePath) ?? $imagePath;
    }

    private function url(?string $path): ?string
    {
        return null !== $path ? $this->storagePublicBaseUrl.'/'.$path : null;
    }
}
