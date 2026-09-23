<?php

declare(strict_types=1);

namespace App\Controller\Notification;

use App\Entity\DeviceToken;
use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Repository\DeviceTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Spec §5.11: push token registered at sign-in, removed at sign-out.
 * Registering is idempotent; a token already known moves to whoever
 * signs in on that device.
 */
final class DeviceTokenController
{
    public function __construct(
        private readonly DeviceTokenRepository $tokens,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/device-tokens', name: 'device_tokens_register', methods: ['POST'])]
    public function register(Request $request, #[CurrentUser] User $me): JsonResponse
    {
        [$token, $platform] = self::parse($request);

        $existing = $this->tokens->findOneBy(['token' => $token]);
        if (null === $existing) {
            $this->em->persist(new DeviceToken($me, $platform, $token));
        } else {
            $existing->claim($me, $platform);
        }
        $this->em->flush();

        return new JsonResponse(['status' => 'registered'], 201);
    }

    #[Route('/api/device-tokens', name: 'device_tokens_delete', methods: ['DELETE'])]
    public function delete(Request $request, #[CurrentUser] User $me): Response
    {
        [$token] = self::parse($request, requirePlatform: false);

        $existing = $this->tokens->findOneBy(['token' => $token, 'user' => $me]);
        if (null !== $existing) {
            $this->em->remove($existing);
            $this->em->flush();
        }

        return new Response(null, 204);
    }

    /**
     * @return array{string, string}
     */
    private static function parse(Request $request, bool $requirePlatform = true): array
    {
        $body = json_decode($request->getContent() ?: '{}', true);
        $token = \is_array($body) ? trim((string) ($body['token'] ?? '')) : '';
        $platform = \is_array($body) ? (string) ($body['platform'] ?? '') : '';
        if ('' === $token || \strlen($token) > 512 || ($requirePlatform && !\in_array($platform, DeviceToken::PLATFORMS, true))) {
            throw new ApiProblemException('validation.device_token_invalid', 'A token and a platform (ios, android, web) are required.', 422);
        }

        return [$token, $platform];
    }
}
