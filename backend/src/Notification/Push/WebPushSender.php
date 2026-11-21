<?php

declare(strict_types=1);

namespace App\Notification\Push;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Web Push (spec §11 décision 70): the web app installed from the
 * browser (Chrome on Android first) receives push without Firebase. The
 * payload is encrypted for the browser's subscription (RFC 8291) and the
 * request signed with our VAPID key pair (RFC 8292); the service worker
 * (sw.js) shows it.
 *
 * Inactive until VAPID_PUBLIC_KEY and VAPID_PRIVATE_KEY are set
 * (bin/console app:web-push:generate-keys), like FCM (décision 27).
 */
final class WebPushSender implements PushSender
{
    /** A notification still worth showing two days later (a birthday reminder, say). */
    private const int TTL = 172800;

    private ?WebPush $webPush = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(VAPID_PUBLIC_KEY)%')]
        private readonly string $publicKey,
        #[Autowire('%env(VAPID_PRIVATE_KEY)%')]
        private readonly string $privateKey,
        #[Autowire('%env(VAPID_SUBJECT)%')]
        private readonly string $subject,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->publicKey && '' !== $this->privateKey;
    }

    public function publicKey(): string
    {
        return $this->publicKey;
    }

    public function send(array $tokens, string $title, string $body, array $data): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        $queued = [];
        $webPush = $this->webPush();
        $payload = (string) json_encode(['title' => $title, 'body' => $body, 'data' => $data], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        foreach ($tokens as $token) {
            $keys = $token->getWebPushKeys();
            if (null === $keys) {
                continue;
            }
            $queued[] = $token;
            $webPush->queueNotification(new Subscription($token->getToken(), $keys['p256dh'], $keys['auth'], 'aes128gcm'), $payload);
        }

        // flush() sends one by one, in the order queued.
        $invalid = [];
        foreach ($webPush->flush() as $i => $report) {
            if ($report->isSuccess()) {
                continue;
            }
            // Spec §5.11 "nettoyage des tokens invalides": 404 / 410, the
            // browser dropped the subscription (signed out, data cleared…).
            if ($report->isSubscriptionExpired() && isset($queued[$i])) {
                $invalid[] = $queued[$i];
            } else {
                $this->logger->warning('Web push send failed: {status} {reason}', [
                    'status' => $report->getResponse()?->getStatusCode(),
                    'reason' => $report->getReason(),
                ]);
            }
        }

        return $invalid;
    }

    private function webPush(): WebPush
    {
        if (null === $this->webPush) {
            $client = new Psr18Client($this->httpClient);
            $this->webPush = new WebPush(
                ['VAPID' => ['subject' => $this->subject, 'publicKey' => $this->publicKey, 'privateKey' => $this->privateKey]],
                ['TTL' => self::TTL, 'urgency' => 'normal'],
                $client,
                $client,
                $client,
                null,
                $this->logger,
            );
            $this->webPush->setReuseVAPIDHeaders(true);
        }

        return $this->webPush;
    }
}
