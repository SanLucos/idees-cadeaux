<?php

declare(strict_types=1);

namespace App\Controller\Notification;

use App\Entity\DeviceToken;
use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Notification\Push\WebPushSender;
use App\Repository\DeviceTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Spec §5.11: push token registered at sign-in, removed at sign-out
 * (a Web Push subscription for the web app, spec §11 décision 70).
 * Registering is idempotent; a token already known moves to whoever
 * signs in on that device.
 */
final class DeviceTokenController
{
    public function __construct(
        private readonly DeviceTokenRepository $tokens,
        private readonly EntityManagerInterface $em,
        private readonly WebPushSender $webPush,
    ) {
    }

    #[Route('/api/device-tokens', name: 'device_tokens_register', methods: ['POST'])]
    public function register(Request $request, #[CurrentUser] User $me): JsonResponse
    {
        [$token, $platform, $webPushKeys] = self::parse($request);

        $existing = $this->tokens->findOneBy(['token' => $token]);
        if (null === $existing) {
            $this->em->persist(new DeviceToken($me, $platform, $token, $webPushKeys));
        } else {
            $existing->claim($me, $platform, $webPushKeys);
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
     * The public half of the VAPID key pair (spec §11 décision 70): the
     * web app subscribes the browser with it. Empty while Web Push is not
     * configured; the web app then offers no push.
     */
    #[Route('/api/web-push/public-key', name: 'web_push_public_key', methods: ['GET'])]
    public function webPushPublicKey(): JsonResponse
    {
        return new JsonResponse(['publicKey' => $this->webPush->isConfigured() ? $this->webPush->publicKey() : '']);
    }

    /**
     * web: the token is the subscription's endpoint, and keys
     * {p256dh, auth} are required to encrypt what is sent to it.
     *
     * @return array{string, string, array{p256dh: string, auth: string}|null}
     */
    private static function parse(Request $request, bool $requirePlatform = true): array
    {
        $body = json_decode($request->getContent() ?: '{}', true);
        $token = \is_array($body) ? trim((string) ($body['token'] ?? '')) : '';
        $platform = \is_array($body) ? (string) ($body['platform'] ?? '') : '';
        if ('' === $token || \strlen($token) > 512 || ($requirePlatform && !\in_array($platform, DeviceToken::PLATFORMS, true))) {
            throw new ApiProblemException('validation.device_token_invalid', 'A token and a platform (ios, android, web) are required.', 422);
        }

        $webPushKeys = null;
        if ($requirePlatform && 'web' === $platform) {
            $keys = \is_array($body['keys'] ?? null) ? $body['keys'] : [];
            $p256dh = \is_string($keys['p256dh'] ?? null) ? $keys['p256dh'] : '';
            $auth = \is_string($keys['auth'] ?? null) ? $keys['auth'] : '';
            if (!str_starts_with($token, 'https://') || !self::isBase64Url($p256dh, 128) || !self::isBase64Url($auth, 64)) {
                throw new ApiProblemException('validation.device_token_invalid', 'A web push subscription needs an https endpoint and its keys (p256dh, auth).', 422);
            }
            $webPushKeys = ['p256dh' => $p256dh, 'auth' => $auth];
        }

        return [$token, $platform, $webPushKeys];
    }

    private static function isBase64Url(string $value, int $maxLength): bool
    {
        return '' !== $value && \strlen($value) <= $maxLength && 1 === preg_match('/^[A-Za-z0-9_-]+=*$/', $value);
    }
}
