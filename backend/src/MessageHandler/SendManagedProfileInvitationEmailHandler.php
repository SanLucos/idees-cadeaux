<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SendManagedProfileInvitationEmail;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Written in the manager's language: the invitee has no account (and
 * so no locale) yet.
 */
#[AsMessageHandler]
final class SendManagedProfileInvitationEmailHandler
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LocaleSwitcher $localeSwitcher,
        private readonly TranslatorInterface $translator,
        private readonly string $mailFromAddress,
        private readonly string $mailFromName,
    ) {
    }

    public function __invoke(SendManagedProfileInvitationEmail $message): void
    {
        $this->localeSwitcher->runWithLocale($message->locale, function () use ($message): void {
            $params = ['%profile%' => $message->profileName, '%manager%' => $message->managerName];
            $email = (new TemplatedEmail())
                ->from(new Address($this->mailFromAddress, $this->mailFromName))
                ->to($message->email)
                ->subject($this->translator->trans('managed_invitation.subject', $params, 'emails'))
                ->htmlTemplate('emails/managed_profile_invitation.html.twig')
                ->context(['code' => $message->code, 'params' => $params]);

            $this->mailer->send($email);
        });
    }
}
