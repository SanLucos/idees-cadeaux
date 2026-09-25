<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\IdeaListFilter;
use App\Entity\Idea;
use App\Entity\ShareLink;
use App\Repository\IdeaRepository;
use App\Serializer\IdeaNormalizer;

/**
 * The guest view of a share link (spec §5.16), shared by the API (app's
 * guest mode) and the web page: pseudo, avatar and published personal
 * ideas — it's the owner view, so an owner who opens their own link
 * signed out learns nothing either. `$urlPrefix` turns the signed media
 * paths into absolute URLs for the app ('' keeps them same-origin).
 */
final class GuestView
{
    public function __construct(
        private readonly IdeaRepository $ideas,
        private readonly IdeaNormalizer $normalizer,
        private readonly GuestMediaUrls $media,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(ShareLink $link, IdeaListFilter $filter, string $urlPrefix = ''): array
    {
        $owner = $link->getOwner();
        $paginator = $this->ideas->findGuestView($owner, $filter);

        $ideas = array_map(
            fn (Idea $idea) => $this->normalizer->normalizeForGuest(
                $idea,
                fn (string $kind) => $urlPrefix.$this->media->path($link, $kind, $idea->getId()->toRfc4122()),
            ),
            iterator_to_array($paginator),
        );

        return [
            'owner' => [
                'displayName' => $owner->getDisplayName(),
                'avatarUrl' => $this->avatarUrl($link, $urlPrefix),
            ],
            'occasions' => $this->ideas->findGuestOccasionCodes($owner),
            'member' => array_values($ideas),
            'totalItems' => \count($paginator),
            'page' => $filter->page,
            'itemsPerPage' => $filter->itemsPerPage,
        ];
    }

    public function avatarUrl(ShareLink $link, string $urlPrefix = ''): ?string
    {
        $owner = $link->getOwner();

        return null !== $owner->getAvatarPath() ? $urlPrefix.$this->media->path($link, 'avatar', $owner->getId()->toRfc4122()) : null;
    }
}
