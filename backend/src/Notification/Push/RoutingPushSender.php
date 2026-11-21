<?php

declare(strict_types=1);

namespace App\Notification\Push;

/**
 * The PushSender in use: the phones' tokens go to FCM, the web app's
 * subscriptions to Web Push (spec §11 décision 70).
 */
final class RoutingPushSender implements PushSender
{
    public function __construct(
        private readonly FcmPushSender $fcm,
        private readonly WebPushSender $webPush,
    ) {
    }

    public function send(array $tokens, string $title, string $body, array $data): array
    {
        $web = array_values(array_filter($tokens, static fn ($token) => $token->isWebPush()));
        $native = array_values(array_filter($tokens, static fn ($token) => !$token->isWebPush()));

        return [
            ...([] === $native ? [] : $this->fcm->send($native, $title, $body, $data)),
            ...([] === $web ? [] : $this->webPush->send($web, $title, $body, $data)),
        ];
    }
}
