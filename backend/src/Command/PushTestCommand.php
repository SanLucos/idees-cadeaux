<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Enum\NotificationChannel;
use App\Entity\Enum\NotificationType;
use App\Entity\User;
use App\Notification\NotificationSettings;
use App\Notification\Push\FcmPushSender;
use App\Notification\Push\WebPushSender;
use App\Repository\DeviceTokenRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Why does this account get no push? (spec §5.11, §11 décision 70). Shows
 * the server's push configuration, the account's consent and devices,
 * then sends a test notification to each device and prints what the push
 * service answered. A subscription it reports gone is removed, as after a
 * real send. Run on the server:
 *   bin/console app:push:test <email>
 */
#[AsCommand(name: 'app:push:test', description: 'Diagnoses push for an account and sends it a test notification')]
final class PushTestCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly DeviceTokenRepository $deviceTokens,
        private readonly NotificationSettings $settings,
        private readonly WebPushSender $webPush,
        private readonly FcmPushSender $fcm,
        private readonly TranslatorInterface $translator,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Email of the account');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = mb_strtolower(trim((string) $input->getArgument('email')));
        $user = $this->users->findOneBy(['email' => $email]);
        if (!$user instanceof User) {
            $io->error(\sprintf('No account with the email %s.', $email));

            return Command::FAILURE;
        }

        $io->section('Server');
        $io->listing([
            'Web Push (VAPID): '.($this->webPush->isConfigured() ? 'configured' : 'NOT configured — set VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY'),
            'FCM (native apps): '.($this->fcm->isConfigured() ? 'configured' : 'not configured'),
        ]);

        $io->section('Account');
        $consent = $this->settings->consent($user, NotificationChannel::Push);
        $io->listing([
            'Push consent: '.(true === $consent?->isEnabled() ? 'granted on '.$consent->getConsentedAt()?->format('Y-m-d H:i') : 'NOT granted'),
            'Push for a friend request: '.($this->settings->isEnabled($user, NotificationType::FriendRequestReceived, NotificationChannel::Push) ? 'on' : 'off'),
        ]);

        $tokens = $this->deviceTokens->findBy(['user' => $user]);
        $io->section(\sprintf('Devices (%d)', \count($tokens)));
        if ([] === $tokens) {
            $io->warning('No device registered: the app never subscribed (push not turned on there, browser permission refused, or no VAPID key when it tried).');

            return Command::FAILURE;
        }

        $locale = $user->getLocale();
        $title = $this->translator->trans('title.push_test', [], 'notifications', $locale);
        $body = $this->translator->trans('push_test', [], 'notifications', $locale);
        $data = ['type' => 'push_test'];

        $rows = [];
        $web = array_values(array_filter($tokens, static fn ($token) => $token->isWebPush()));
        foreach ($this->webPush->deliver($web, $title, $body, $data) as $result) {
            $rows[] = [$result['token']->getPlatform(), parse_url($result['token']->getToken(), \PHP_URL_HOST), (string) $result['status'], $result['success'] ? 'sent' : ($result['expired'] ? 'gone, removed' : $result['reason'])];
            if ($result['expired']) {
                $this->em->remove($result['token']);
            }
        }
        if (!$this->webPush->isConfigured()) {
            foreach ($web as $token) {
                $rows[] = ['web', parse_url($token->getToken(), \PHP_URL_HOST), '', 'not sent: no VAPID key'];
            }
        }

        $native = array_values(array_filter($tokens, static fn ($token) => !$token->isWebPush()));
        if ([] !== $native) {
            $invalid = $this->fcm->send($native, $title, $body, $data);
            foreach ($native as $token) {
                $gone = \in_array($token, $invalid, true);
                $rows[] = [$token->getPlatform(), 'FCM', '', !$this->fcm->isConfigured() ? 'not sent: FCM not configured' : ($gone ? 'invalid, removed' : 'sent')];
                if ($gone) {
                    $this->em->remove($token);
                }
            }
        }
        $this->em->flush();

        $io->section('Test notification');
        $io->table(['Platform', 'Push service', 'Status', 'Result'], $rows);
        $io->note('« sent » means the push service accepted it. If nothing shows on the phone, check the phone\'s settings for the app (Chrome → Notifications) and that it is not in a power-saving mode.');

        return Command::SUCCESS;
    }
}
