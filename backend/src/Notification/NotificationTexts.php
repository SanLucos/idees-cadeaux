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
            '%owner%' => $this->names($payload['recipients'] ?? (isset($payload['owner']) ? [$payload['owner']] : []), $notification->getUser()->getLocale()),
            '%friend%' => $payload['friend']['displayName'] ?? '',
            '%child%' => $payload['child']['displayName'] ?? '',
            '%suggestedBy%' => $payload['suggestedBy']['displayName'] ?? '',
            '%days%' => (string) ($payload['days'] ?? ''),
            '%count%' => (int) ($payload['days'] ?? 0),
        ];

        $key = $notification->getType()->value;
        if (isset($payload['actor']['managedBy'])) {
            $key .= '_managed';
        } elseif (isset($payload['suggestedBy'])) {
            $key .= '_suggested';
        }

        $text = $this->translator->trans($key, $params, 'notifications', $notification->getUser()->getLocale());

        if (null !== $subject = $payload['subject']['displayName'] ?? null) {
            $text = $this->translator->trans('for_subject', ['%subject%' => $subject, '%text%' => $text], 'notifications', $notification->getUser()->getLocale());
        }

        return $text;
    }

    /**
     * "Anne", "Anne et Luc", "Anne, Luc et Marc" — an idea's recipients
     * (décision 69).
     *
     * @param list<array{displayName?: ?string}> $people
     */
    private function names(array $people, string $locale): string
    {
        $names = array_values(array_map(static fn (array $p): string => (string) ($p['displayName'] ?? ''), $people));
        if (\count($names) < 2) {
            return $names[0] ?? '';
        }
        $last = array_pop($names);

        return $this->translator->trans('names_and', ['%first%' => implode(', ', $names), '%last%' => $last], 'notifications', $locale);
    }

    public function title(Notification $notification): string
    {
        return $this->translator->trans('title.'.$notification->getType()->value, [], 'notifications', $notification->getUser()->getLocale());
    }
}
