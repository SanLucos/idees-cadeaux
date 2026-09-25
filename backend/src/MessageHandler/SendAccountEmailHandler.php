<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SendAccountEmail;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Transactional (spec §5.11): never subject to notification consent.
 */
#[AsMessageHandler]
final class SendAccountEmailHandler
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LocaleSwitcher $localeSwitcher,
        private readonly TranslatorInterface $translator,
        private readonly string $mailFromAddress,
        private readonly string $mailFromName,
    ) {
    }

    public function __invoke(SendAccountEmail $message): void
    {
        $this->localeSwitcher->runWithLocale($message->locale, function () use ($message): void {
            $params = $message->params + ['%app%' => $this->mailFromName];
            $prefix = 'account.'.$message->kind.'.';
            $suffix = $message->aboutProfile ? '_profile' : '';

            $email = (new TemplatedEmail())
                ->from(new Address($this->mailFromAddress, $this->mailFromName))
                ->to($message->email)
                ->subject($this->translator->trans($prefix.'subject'.$suffix, $params, 'emails'))
                ->htmlTemplate('emails/account.html.twig')
                ->context(['prefix' => $prefix, 'suffix' => $suffix, 'params' => $params, 'action_url' => $message->actionUrl, 'kind' => $message->kind]);

            $this->mailer->send($email);
        });
    }
}
