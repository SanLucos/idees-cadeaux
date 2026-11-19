<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Entity\Idea;
use App\Entity\User;
use App\Security\IdeaAccess;
use App\Service\MediaUrls;

/**
 * Spec §4 "Conséquences techniques" 1: distinct serialisation views.
 *
 * - normalizeForOwner(): a personal idea, for one of its recipients
 *   (décision 69: each of them is its owner). Never reservation,
 *   comments, contribution, reactions nor any counter.
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
        private readonly MediaUrls $media,
    ) {
    }

    /**
     * `$viewer` is one of the recipients (or acts as one). Fields,
     * deletion and "reçue" are every recipient's; publishing and the
     * recipients, the author's (`isMine`).
     *
     * @return array<string, mixed>
     */
    public function normalizeForOwner(Idea $idea, User $viewer): array
    {
        return $this->publicFields($idea) + [
            'view' => 'owner',
            // Never `author` here (a visibility invariant): only whether it's mine.
            'isMine' => $idea->getAuthor() === $viewer,
            'canEdit' => $idea->hasRecipient($viewer),
            'canUnarchive' => $idea->isArchived() && $idea->getArchivedBy() === $viewer,
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
            'author' => $this->person($author),
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
     * `$viewer` is the manager or the child they act as; `$human`, the
     * adult behind it (by default from the acting context). A suggestion
     * the adult wrote is theirs either way: they change it in their own
     * name (spec §11 décision 25), never the child's.
     *
     * @param Idea[] $ideas all on one managed profile's list
     *
     * @return list<array<string, mixed>>
     */
    public function normalizeManyForManager(array $ideas, User $viewer, ?User $human = null): array
    {
        $human ??= IdeaAccess::humanBehind($viewer);
        $interactions = $this->interactions->summarize($ideas, $human);

        return array_values(array_map(function (Idea $idea) use ($viewer, $human, $interactions): array {
            $author = $idea->getAuthor();
            $isMine = $author === $viewer || ($idea->isSuggestion() && $author === $human);

            return array_merge(
                $this->publicFields($idea) + [
                    'view' => 'manager',
                    'isSuggestion' => $idea->isSuggestion(),
                    'isMine' => $isMine,
                    'author' => $this->person($author),
                    // A personal idea: any recipient, so the child acted as (décision 69).
                    'canEdit' => $idea->isSuggestion() ? $isMine : $idea->hasRecipient($viewer),
                    'canUnarchive' => $idea->isArchived() && \in_array($idea->getArchivedBy(), [$viewer, $human], true),
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
        return $this->normalizeMany([$idea], $viewer)[0];
    }

    /**
     * Each idea in the view `$viewer` gets of it (IdeaAccess::viewOf,
     * which the caller has already checked): owner for a recipient,
     * manager for the manager of a recipient, friend otherwise. Keeps
     * the order.
     *
     * @param Idea[] $ideas
     *
     * @return list<array<string, mixed>>
     */
    public function normalizeMany(array $ideas, User $viewer): array
    {
        $friendly = $managed = [];
        foreach ($ideas as $idea) {
            if (IdeaAccess::isOwnerSide($idea, $viewer)) {
                continue;
            }
            if (null !== IdeaAccess::managedRecipient($idea, $viewer)) {
                $managed[] = $idea;
            } else {
                $friendly[] = $idea;
            }
        }

        $docs = [];
        foreach ([...$this->normalizeManyForFriend($friendly, $viewer), ...$this->normalizeManyForManager($managed, $viewer)] as $doc) {
            $docs[$doc['id']] = $doc;
        }

        return array_values(array_map(
            fn (Idea $idea) => $docs[$idea->getId()->toRfc4122()] ?? $this->normalizeForOwner($idea, $viewer),
            $ideas,
        ));
    }

    /**
     * @return array{id: string, displayName: string, avatarUrl: ?string}
     */
    private function person(User $user): array
    {
        return [
            'id' => $user->getId()->toRfc4122(),
            'displayName' => $user->getDisplayName(),
            'avatarUrl' => $this->url($user->getAvatarPath()),
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
            // Every recipient, whoever reads it (décision 69): never the guest view.
            'recipients' => array_map($this->person(...), $idea->getRecipients()),
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
        return $this->media->url($path);
    }
}
