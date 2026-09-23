<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Enum\NotificationChannel;
use App\Message\DeliverNotification;
use App\Notification\NotificationSettings;
use App\Notification\NotificationTexts;
use App\Notification\Push\PushSender;
use App\Notification\UnsubscribeLinks;
use App\Repository\DeviceTokenRepository;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Email and push for a notification (spec §5.11). Settings are read
 * again here, at send time: a consent withdrawn in the meantime wins.
 */
#[AsMessageHandler]
final class DeliverNotificationHandler
{
    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly DeviceTokenRepository $deviceTokens,
        private readonly NotificationSettings $settings,
        private readonly NotificationTexts $texts,
        private readonly UnsubscribeLinks $unsubscribe,
        private readonly PushSender $push,
        private readonly MailerInterface $mailer,
        private readonly LocaleSwitcher $localeSwitcher,
        private readonly EntityManagerInterface $em,
        private readonly string $mailFromAddress,
        private readonly string $mailFromName,
    ) {
    }

    public function __invoke(DeliverNotification $message): void
    {
        $notification = $this->notifications->find($message->notificationId);
        if (null === $notification) {
            return;
        }
        $user = $notification->getUser();
        if ($user->isSuspended()) {
            return;
        }
        $type = $notification->getType();

        if ($this->settings->isEnabled($user, $type, NotificationChannel::Push)) {
            $tokens = $this->deviceTokens->findBy(['user' => $user]);
            $invalid = $this->push->send($tokens, $this->texts->title($notification), $this->texts->body($notification), [
                'notificationId' => $notification->getId()->toRfc4122(),
                'type' => $type->value,
            ]);
            foreach ($invalid as $token) {
                $this->em->remove($token);
            }
            $this->em->flush();
        }

        if (null !== $user->getEmail() && null !== $user->getEmailVerifiedAt() && $this->settings->isEnabled($user, $type, NotificationChannel::Email)) {
            $this->localeSwitcher->runWithLocale($user->getLocale(), function () use ($notification, $user, $type): void {
                $unsubscribeUrl = $this->unsubscribe->url($user, $type);
                $email = (new TemplatedEmail())
                    ->from(new Address($this->mailFromAddress, $this->mailFromName))
                    ->to($user->getEmail())
                    ->subject($this->texts->body($notification))
                    ->htmlTemplate('emails/notification.html.twig')
                    ->context([
                        'title' => $this->texts->title($notification),
                        'body' => $this->texts->body($notification),
                        'unsubscribe_url' => $unsubscribeUrl,
                    ]);
                // RFC 8058 one-click unsubscribe.
                $email->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$unsubscribeUrl.'>');
                $email->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

                $this->mailer->send($email);
            });
        }
    }
}
