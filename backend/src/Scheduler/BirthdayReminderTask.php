<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Entity\Enum\NotificationType;
use App\Entity\User;
use App\Notification\BirthdayCalendar;
use App\Notification\NotificationRecipients;
use App\Notification\Notifier;
use App\Repository\UserRepository;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Spec §5.11 birthday reminders: to a person's friends (never to the
 * person), at the delays each recipient chose (J-14 and J-2 by
 * default, §11 décision 29), around 9:00 in the recipient's timezone.
 * Runs hourly and only handles timezones where it is 9 o'clock; the
 * dedupe key makes a rerun in the same hour harmless.
 */
#[AsPeriodicTask(frequency: '1 hour')]
final class BirthdayReminderTask
{
    public const int SEND_HOUR = 9;

    public function __construct(
        private readonly UserRepository $users,
        private readonly NotificationRecipients $recipients,
        private readonly Notifier $notifier,
    ) {
    }

    public function __invoke(?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();
        $sent = 0;

        foreach ($this->users->findAll() as $recipient) {
            $local = $now->setTimezone(new \DateTimeZone($recipient->getTimezone()));
            if (self::SEND_HOUR !== (int) $local->format('G') || [] === $recipient->getBirthdayReminderDays() || $recipient->isSuspended()) {
                continue;
            }

            foreach ($this->recipients->friendsOf($recipient) as $friend) {
                $sent += $this->remind($recipient, $friend, $local);
            }
        }

        return $sent;
    }

    private function remind(User $recipient, User $friend, \DateTimeImmutable $local): int
    {
        if (null === $friend->getBirthDay() || null === $friend->getBirthMonth() || $friend->isSuspended()) {
            return 0;
        }

        [$days, $date] = BirthdayCalendar::next($friend->getBirthDay(), $friend->getBirthMonth(), $local);
        if (!\in_array($days, $recipient->getBirthdayReminderDays(), true)) {
            return 0;
        }

        return \count($this->notifier->notify(
            NotificationType::BirthdayReminder,
            [$recipient],
            ['friend' => Notifier::person($friend), 'days' => $days, 'date' => $date->format('Y-m-d')],
            dedupeKey: \sprintf('birthday:%s:%s:%d', $friend->getId()->toRfc4122(), $date->format('Y-m-d'), $days),
        ));
    }
}
