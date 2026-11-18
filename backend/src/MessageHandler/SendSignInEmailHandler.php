<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SendSignInEmail;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
final class SendSignInEmailHandler
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LocaleSwitcher $localeSwitcher,
        private readonly TranslatorInterface $translator,
        private readonly string $mailFromAddress,
        private readonly string $mailFromName,
        private readonly string $webAppUrl,
    ) {
    }

    public function __invoke(SendSignInEmail $message): void
    {
        $link = rtrim($this->webAppUrl, '/').'/login/email?'.http_build_query(['email' => $message->email, 'code' => $message->code]);

        $this->localeSwitcher->runWithLocale($message->locale, function () use ($message, $link): void {
            $email = (new TemplatedEmail())
                ->from(new Address($this->mailFromAddress, $this->mailFromName))
                ->to($message->email)
                ->subject($this->translator->trans('sign_in.subject', domain: 'emails'))
                ->htmlTemplate('emails/sign_in.html.twig')
                ->context([
                    'code' => $message->code,
                    'link' => $link,
                    'new_account' => $message->newAccount,
                ]);

            $this->mailer->send($email);
        });
    }
}
