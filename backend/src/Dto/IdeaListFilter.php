<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Enum\IdeaStatus;
use App\Entity\Enum\IdeaVisibility;
use App\Exception\ApiProblemException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Query-string filters shared by every idea list (spec §5.4
 * "Consultation": filtre par occasion, tri date/prix, recherche texte,
 * archives) — parsed and validated once here.
 */
final readonly class IdeaListFilter
{
    public const int ITEMS_PER_PAGE = 20;
    private const int MAX_ITEMS_PER_PAGE = 50;

    public function __construct(
        public IdeaStatus $status = IdeaStatus::Active,
        public ?IdeaVisibility $visibility = null,
        /** 'personal' | 'suggestion' | null (friend view only) */
        public ?string $kind = null,
        public ?string $occasion = null,
        public ?string $search = null,
        /** 'recent' | 'price_asc' | 'price_desc' */
        public string $sort = 'recent',
        public int $page = 1,
        public int $itemsPerPage = self::ITEMS_PER_PAGE,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $query = $request->query;

        $status = IdeaStatus::tryFrom((string) $query->get('status', IdeaStatus::Active->value));
        $visibilityParam = $query->get('visibility');
        $visibility = null === $visibilityParam ? null : IdeaVisibility::tryFrom((string) $visibilityParam);
        $kind = $query->get('kind');
        $sort = (string) $query->get('sort', 'recent');
        $search = trim((string) $query->get('q', ''));
        $occasion = $query->get('occasion');

        if (null === $status
            || (null !== $visibilityParam && null === $visibility)
            || (null !== $kind && !\in_array($kind, ['personal', 'suggestion'], true))
            || !\in_array($sort, ['recent', 'price_asc', 'price_desc'], true)
        ) {
            throw new ApiProblemException('validation.invalid_filter', 'Unsupported list filter.', 400);
        }

        return new self(
            status: $status,
            visibility: $visibility,
            kind: $kind,
            occasion: null !== $occasion && '' !== $occasion ? (string) $occasion : null,
            search: '' !== $search ? mb_substr($search, 0, 100) : null,
            sort: $sort,
            page: max(1, $query->getInt('page', 1)),
            itemsPerPage: min(self::MAX_ITEMS_PER_PAGE, max(1, $query->getInt('itemsPerPage', self::ITEMS_PER_PAGE))),
        );
    }
}
