<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Enum\VerificationCodePurpose;
use App\Message\SendVerificationCodeEmail;
use App\Repository\UserRepository;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
final class SendVerificationCodeEmailHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly MailerInterface $mailer,
        private readonly LocaleSwitcher $localeSwitcher,
        private readonly TranslatorInterface $translator,
        private readonly string $mailFromAddress,
        private readonly string $mailFromName,
    ) {
    }

    public function __invoke(SendVerificationCodeEmail $message): void
    {
        $user = $this->users->find($message->userId);
        if (null === $user || null === $user->getEmail()) {
            return;
        }

        $translationKey = match ($message->purpose) {
            VerificationCodePurpose::VerifyEmail => 'verify_email',
            VerificationCodePurpose::ResetPassword => 'reset_password',
            VerificationCodePurpose::Reauthenticate => 'reauthenticate',
        };

        $this->localeSwitcher->runWithLocale($user->getLocale(), function () use ($user, $translationKey, $message): void {
            $subject = $this->translator->trans($translationKey.'.subject', domain: 'emails');

            $email = (new TemplatedEmail())
                ->from(new Address($this->mailFromAddress, $this->mailFromName))
                ->to($user->getEmail())
                ->subject($subject)
                ->htmlTemplate('emails/verification_code.html.twig')
                ->context([
                    'translation_key' => $translationKey,
                    'code' => $message->code,
                ]);

            $this->mailer->send($email);
        });
    }
}
