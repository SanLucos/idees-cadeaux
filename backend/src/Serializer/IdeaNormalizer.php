<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Entity\Idea;
use App\Entity\User;
use App\Security\IdeaAccess;

/**
 * Spec §4 "Conséquences techniques" 1: distinct serialisation views.
 *
 * - normalizeForOwner(): the owner's own idea. No author, no
 *   suggestion flag — and, from lot 4, never reservation, comments,
 *   contribution, reactions nor any counter.
 * - normalizeForFriend(): what a friend of the owner (or the author of
 *   a draft) sees, plus the hidden interactions from
 *   InteractionNormalizer on a published idea.
 *
 * - normalizeForManager(): a managed profile's list as read by its
 *   manager (spec §5.15): everything the friend view has, on every
 *   idea of the list; interactions are computed for the manager as a
 *   person (pledge amounts keep règle 3).
 *
 * - normalizeForGuest(): the guest view of a share link (spec §5.16),
 *   for anyone holding the link: the idea itself, nothing about its
 *   state, author or interactions; images through signed URLs.
 */
final class IdeaNormalizer
{
    public function __construct(
        private readonly InteractionNormalizer $interactions,
        private readonly string $storagePublicBaseUrl,
    ) {
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
     * @param callable(string $kind): string $mediaUrl signed URL of this idea's 'image' or 'thumb'
     *
     * @return array<string, mixed>
     */
    public function normalizeForGuest(Idea $idea, callable $mediaUrl): array
    {
        $hasImage = null !== $idea->getImagePath();

        return [
            'id' => $idea->getId()->toRfc4122(),
            'view' => 'guest',
            'title' => $idea->getTitle(),
            'url' => $idea->getUrl(),
            'priceAmount' => $idea->getPriceAmount(),
            'priceCurrency' => $idea->getPriceCurrency(),
            'imageUrl' => $hasImage ? $mediaUrl('image') : null,
            'thumbnailUrl' => $hasImage ? $mediaUrl('thumb') : null,
            'note' => $idea->getNote(),
            'occasion' => $idea->getOccasion()?->getCode(),
        ];
    }

    /**
     * Friend view of several ideas, with interactions loaded in batch.
     *
     * @param Idea[] $ideas
     *
     * @return list<array<string, mixed>>
     */
    public function normalizeManyForFriend(array $ideas, User $viewer): array
    {
        $interactions = $this->interactions->summarize($ideas, $viewer);

        return array_values(array_map(
            fn (Idea $idea) => $this->normalizeForFriend($idea, $viewer, $interactions[$idea->getId()->toRfc4122()] ?? []),
            $ideas,
        ));
    }

    /**
     * @param array<string, mixed>|null $interactions precomputed by normalizeManyForFriend()
     *
     * @return array<string, mixed>
     */
    public function normalizeForFriend(Idea $idea, User $viewer, ?array $interactions = null): array
    {
        $interactions ??= $this->interactions->summarize([$idea], $viewer)[$idea->getId()->toRfc4122()] ?? [];

        $author = $idea->getAuthor();
        $isMine = $author === $viewer;

        $data = $this->publicFields($idea) + [
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

        // A private draft has no interactions: only its author's "offert" right remains.
        return array_merge(
            $data,
            ['canMarkGifted' => $isMine && $idea->isSuggestion() && !$idea->isArchived()],
            $interactions,
        );
    }

    /**
     * @param Idea[] $ideas all on one managed profile's list
     *
     * @return list<array<string, mixed>>
     */
    public function normalizeManyForManager(array $ideas, User $viewer): array
    {
        $human = IdeaAccess::humanBehind($viewer);
        $interactions = $this->interactions->summarize($ideas, $human);

        return array_values(array_map(function (Idea $idea) use ($viewer, $interactions): array {
            $author = $idea->getAuthor();
            $isMine = $author === $viewer;

            return array_merge(
                $this->publicFields($idea) + [
                    'view' => 'manager',
                    'isSuggestion' => $idea->isSuggestion(),
                    'isMine' => $isMine,
                    'author' => [
                        'id' => $author->getId()->toRfc4122(),
                        'displayName' => $author->getDisplayName(),
                        'avatarUrl' => $this->url($author->getAvatarPath()),
                    ],
                    'canEdit' => $isMine,
                    'canUnarchive' => $idea->isArchived() && $idea->getArchivedBy() === $viewer,
                ],
                ['canMarkGifted' => $isMine && $idea->isSuggestion() && !$idea->isArchived()],
                $interactions[$idea->getId()->toRfc4122()] ?? [],
            );
        }, $ideas));
    }

    /**
     * The view `$viewer` gets of one idea they may see.
     *
     * @return array<string, mixed>
     */
    public function normalizeFor(Idea $idea, User $viewer): array
    {
        if (IdeaAccess::readsAsManager($idea->getOwner(), $viewer)) {
            return $this->normalizeManyForManager([$idea], $viewer)[0];
        }

        return $idea->getOwner() === $viewer && !$idea->isSuggestion()
            ? $this->normalizeForOwner($idea)
            : $this->normalizeForFriend($idea, $viewer);
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
