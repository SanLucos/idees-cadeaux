<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Occasion;
use App\Repository\OccasionRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only reference list (spec §5.4, §7). Labels are translated
 * client-side from `code` (spec §5.14: "occasions référencées par code
 * + clé de traduction").
 */
final class OccasionController
{
    public function __construct(private readonly OccasionRepository $occasions)
    {
    }

    #[Route('/api/occasions', name: 'occasions_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return new JsonResponse(array_map(static fn (Occasion $o): array => [
            'code' => $o->getCode(),
            'translationKey' => $o->getTranslationKey(),
            'sortOrder' => $o->getSortOrder(),
        ], $this->occasions->findAllOrdered()));
    }
}
