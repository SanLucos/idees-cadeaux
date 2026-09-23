<?php

declare(strict_types=1);

namespace App\Controller\Notification;

use App\Entity\Enum\NotificationChannel;
use App\Entity\Enum\NotificationType;
use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Notification\NotificationSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Notification settings (spec §5.11, §7 NotificationPreference):
 * per-channel consent (push, email; timestamped), per type × channel
 * switches, and birthday reminder delays (§11 décision 29).
 */
final class NotificationSettingsController
{
    public function __construct(
        private readonly NotificationSettings $settings,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/notification-settings', name: 'notification_settings_get', methods: ['GET'])]
    public function show(#[CurrentUser] User $me): JsonResponse
    {
        return new JsonResponse($this->view($me));
    }

    /**
     * Body (every key optional):
     * { consents: {email: bool, push: bool},
     *   preferences: {<type>: {<channel>: bool}},
     *   birthdayReminderDays: int[] }
     */
    #[Route('/api/notification-settings', name: 'notification_settings_patch', methods: ['PATCH'])]
    public function update(Request $request, #[CurrentUser] User $me): JsonResponse
    {
        $body = json_decode($request->getContent() ?: '{}', true);
        if (!\is_array($body)) {
            throw new ApiProblemException('validation.invalid_body', 'Malformed JSON body.', 400);
        }

        foreach ((array) ($body['consents'] ?? []) as $channel => $granted) {
            $channel = NotificationChannel::tryFrom((string) $channel);
            if (null === $channel || !$channel->needsConsent() || !\is_bool($granted)) {
                throw new ApiProblemException('validation.notification_settings_invalid', 'Unknown consent channel.', 422);
            }
            $this->settings->setConsent($me, $channel, $granted);
        }

        foreach ((array) ($body['preferences'] ?? []) as $type => $channels) {
            $type = NotificationType::tryFrom((string) $type);
            if (null === $type || !\is_array($channels)) {
                throw new ApiProblemException('validation.notification_settings_invalid', 'Unknown notification type.', 422);
            }
            foreach ($channels as $channel => $enabled) {
                $channel = NotificationChannel::tryFrom((string) $channel);
                if (null === $channel || !\is_bool($enabled)) {
                    throw new ApiProblemException('validation.notification_settings_invalid', 'Unknown channel.', 422);
                }
                $this->settings->set($me, $channel, $type, $enabled);
            }
        }

        if (\array_key_exists('birthdayReminderDays', $body)) {
            $days = $body['birthdayReminderDays'];
            if (!\is_array($days) || \count($days) > 3 || [] !== array_filter($days, static fn ($d) => !\is_int($d) || $d < 0 || $d > 30)) {
                throw new ApiProblemException('validation.birthday_reminders_invalid', 'Up to 3 delays, 0 to 30 days.', 422);
            }
            $me->setBirthdayReminderDays($days);
            $this->em->flush();
        }

        return new JsonResponse($this->view($me));
    }

    /**
     * @return array<string, mixed>
     */
    private function view(User $me): array
    {
        $consents = [];
        foreach ([NotificationChannel::Email, NotificationChannel::Push] as $channel) {
            $row = $this->settings->consent($me, $channel);
            $consents[$channel->value] = [
                'granted' => (bool) $row?->isEnabled(),
                'consentedAt' => $row?->getConsentedAt()?->format(\DATE_ATOM),
            ];
        }

        $preferences = [];
        foreach (NotificationType::cases() as $type) {
            foreach (NotificationChannel::cases() as $channel) {
                // The type-level switch, independent of the channel's consent.
                $preferences[$type->value][$channel->value] = $this->settings->isTypeEnabled($me, $type, $channel);
            }
        }

        return [
            'consents' => $consents,
            'preferences' => $preferences,
            'birthdayReminderDays' => $me->getBirthdayReminderDays(),
        ];
    }
}
