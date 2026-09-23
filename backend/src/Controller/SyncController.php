<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Sync\SyncService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Offline sync (spec §8, §11 décision 32: delta + inventory).
 *
 * POST /api/sync {since?, hashes?: {type: hash}} returns
 * - `cursor`: pass it as `since` next time;
 * - `changes`: documents whose version moved since `since` (all of
 *   them on a first sync), in their API view;
 * - `hashes`: per type, SHA-256 of the sorted "id:version" lines;
 * - `manifests`: for each type whose hash differs from the device's,
 *   the full [id, version] inventory. The device drops what isn't
 *   listed and fetches what it lacks (POST /api/sync/fetch).
 *
 * Nothing is ever named that the user can't see: a hidden or private
 * item simply never appears, and disappears from devices when it
 * leaves the inventory — no tombstone can reveal it existed.
 * Always the adult's own sync (child profiles included), never acting.
 */
final class SyncController
{
    /** Versions have 1 s resolution: re-send the last seconds to never miss a same-second change. */
    private const int OVERLAP_SECONDS = 2;

    public function __construct(private readonly SyncService $sync)
    {
    }

    #[Route('/api/sync', name: 'sync', methods: ['POST'])]
    public function sync(Request $request, #[CurrentUser] User $me): JsonResponse
    {
        $body = self::decode($request);
        $cursor = SyncService::v(new \DateTimeImmutable());
        $since = null;
        if (isset($body['since'])) {
            $parsed = \is_string($body['since']) ? \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $body['since'], new \DateTimeZone('UTC')) : false;
            if (false === $parsed) {
                throw new ApiProblemException('sync.cursor_invalid', 'Invalid sync cursor.', 400);
            }
            $since = SyncService::v($parsed->modify('-'.self::OVERLAP_SECONDS.' seconds'));
        }
        $deviceHashes = \is_array($body['hashes'] ?? null) ? $body['hashes'] : [];

        $inventory = $this->sync->inventory($me);
        $changes = $hashes = $manifests = [];
        foreach ($inventory as $type => $entries) {
            $changed = null === $since ? $entries : array_filter($entries, static fn (array $e) => $e['version'] >= $since);
            if ([] !== $changed) {
                $changes[$type] = $this->sync->documents($type, $changed, $me);
            }

            $lines = array_map(static fn (string $id, array $e) => $id.':'.$e['version'], array_map('strval', array_keys($entries)), $entries);
            sort($lines);
            $hashes[$type] = hash('sha256', implode("\n", $lines));
            if (($deviceHashes[$type] ?? null) !== $hashes[$type]) {
                $manifests[$type] = array_map(static fn (string $line) => explode(':', $line, 2), $lines);
            }
        }

        return new JsonResponse(['cursor' => $cursor, 'changes' => (object) $changes, 'hashes' => $hashes, 'manifests' => (object) $manifests]);
    }

    /** Bodies for inventory items the device lacks; anything else asked for is silently left out. */
    #[Route('/api/sync/fetch', name: 'sync_fetch', methods: ['POST'])]
    public function fetch(Request $request, #[CurrentUser] User $me): JsonResponse
    {
        $body = self::decode($request);
        $type = (string) ($body['type'] ?? '');
        $ids = $body['ids'] ?? null;
        if (!\in_array($type, SyncService::TYPES, true) || !\is_array($ids) || \count($ids) > 500) {
            throw new ApiProblemException('sync.fetch_invalid', 'A known type and at most 500 ids are required.', 400);
        }

        $entries = array_intersect_key($this->sync->inventory($me)[$type], array_flip(array_map('strval', $ids)));

        return new JsonResponse(['documents' => $this->sync->documents($type, $entries, $me)]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(Request $request): array
    {
        $body = json_decode($request->getContent() ?: '{}', true);
        if (!\is_array($body)) {
            throw new ApiProblemException('validation.invalid_body', 'Malformed JSON body.', 400);
        }

        return $body;
    }
}
