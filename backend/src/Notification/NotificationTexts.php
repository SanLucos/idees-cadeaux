<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\Notification;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Email and push wording, in the recipient's language (spec §5.14).
 * The app renders its own text from type + payload; these are for
 * messages leaving the app.
 */
final class NotificationTexts
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function body(Notification $notification): string
    {
        $payload = $notification->getPayload();
        $params = [
            '%actor%' => $payload['actor']['displayName'] ?? '',
            '%manager%' => $payload['actor']['managedBy'] ?? '',
            '%idea%' => $payload['idea']['title'] ?? '',
            '%owner%' => $payload['owner']['displayName'] ?? '',
            '%friend%' => $payload['friend']['displayName'] ?? '',
            '%days%' => (string) ($payload['days'] ?? ''),
            '%count%' => (int) ($payload['days'] ?? 0),
        ];

        $key = $notification->getType()->value;
        if (isset($payload['actor']['managedBy'])) {
            $key .= '_managed';
        }

        $text = $this->translator->trans($key, $params, 'notifications', $notification->getUser()->getLocale());

        if (null !== $subject = $payload['subject']['displayName'] ?? null) {
            $text = $this->translator->trans('for_subject', ['%subject%' => $subject, '%text%' => $text], 'notifications', $notification->getUser()->getLocale());
        }

        return $text;
    }

    public function title(Notification $notification): string
    {
        return $this->translator->trans('title.'.$notification->getType()->value, [], 'notifications', $notification->getUser()->getLocale());
    }
}
