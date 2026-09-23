<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\Enum\NotificationType;
use App\Entity\User;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * One-click email unsubscribe (spec §5.11, RFC 8058): a signed,
 * account-free link per recipient and notification type.
 */
final class UnsubscribeLinks
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly string $secret,
    ) {
    }

    public function url(User $user, NotificationType $type): string
    {
        return $this->urls->generate('email_unsubscribe', [
            'user' => $user->getId()->toRfc4122(),
            'type' => $type->value,
            'signature' => $this->sign($user->getId()->toRfc4122(), $type->value),
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    public function isValid(string $userId, string $type, string $signature): bool
    {
        return hash_equals($this->sign($userId, $type), $signature);
    }

    private function sign(string $userId, string $type): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', 'unsubscribe|'.$userId.'|'.$type, $this->secret, true)), '+/', '-_'), '=');
    }
}
