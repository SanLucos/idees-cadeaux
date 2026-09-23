<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Message\DeliverNotification;
use App\MessageHandler\DeliverNotificationHandler;
use App\Notification\Push\InMemoryPushSender;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email;

/**
 * The in-memory `async` transport only keeps the last request's
 * messages (each request reboots the kernel): call deliver() right after
 * the request that notified. It runs the email/push delivery and returns
 * what went out.
 */
trait NotificationTestTrait
{
    /**
     * @return array{emails: Email[], pushes: list<array{token: string, title: string, body: string, data: array<string, string>}>}
     */
    protected function deliver(): array
    {
        InMemoryPushSender::$sent = [];
        $container = self::getContainer();
        /** @var InMemoryTransport $transport */
        $transport = $container->get('messenger.transport.async');
        $handler = $container->get(DeliverNotificationHandler::class);

        foreach ($transport->getSent() as $envelope) {
            if ($envelope->getMessage() instanceof DeliverNotification) {
                $handler($envelope->getMessage());
            }
        }

        $emails = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof SendEmailMessage && $message->getMessage() instanceof Email) {
                $emails[] = $message->getMessage();
            }
        }
        $transport->reset();

        return ['emails' => $emails, 'pushes' => InMemoryPushSender::$sent];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function notificationsOf(string $token): array
    {
        return static::createClient()->request('GET', '/api/notifications', ['auth_bearer' => $token])->toArray()['member'];
    }

    /**
     * @return string[]
     */
    protected function typesOf(string $token): array
    {
        return array_column($this->notificationsOf($token), 'type');
    }

    protected function consentToEverything(string $token, ?string $pushToken = null): void
    {
        static::createClient()->request('PATCH', '/api/notification-settings', [
            'auth_bearer' => $token,
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['consents' => ['email' => true, 'push' => true]],
        ]);
        if (null !== $pushToken) {
            static::createClient()->request('POST', '/api/device-tokens', ['auth_bearer' => $token, 'json' => ['token' => $pushToken, 'platform' => 'android']]);
        }
    }
}
