<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Enum\NotificationChannel;
use App\Entity\Enum\NotificationType;
use App\Notification\NotificationSettings;
use App\Notification\UnsubscribeLinks;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * "Désinscription email en un clic" (spec §5.11, §11 décision 30): the
 * link turns off that notification type by email; the page also offers
 * "all notification emails" (the email consent itself). GET only shows
 * the confirmation — link scanners must not unsubscribe anyone — POST
 * applies it (RFC 8058 one-click, or the page's buttons).
 */
final class UnsubscribeController
{
    public function __construct(
        private readonly UnsubscribeLinks $links,
        private readonly UserRepository $users,
        private readonly NotificationSettings $settings,
        private readonly TranslatorInterface $translator,
        private readonly Environment $twig,
    ) {
    }

    #[Route('/unsubscribe/{user}/{type}/{signature}', name: 'email_unsubscribe', methods: ['GET', 'POST'], requirements: ['user' => Requirement::UUID])]
    public function __invoke(string $user, string $type, string $signature, Request $request): Response
    {
        $account = $this->links->isValid($user, $type, $signature) ? $this->users->find($user) : null;
        $notificationType = NotificationType::tryFrom($type);
        if (null === $account || null === $notificationType) {
            return $this->render(['invalid' => true], 404, 'fr');
        }

        $locale = $account->getLocale();
        $typeLabel = $this->translator->trans('title.'.$type, [], 'notifications', $locale);

        if (!$request->isMethod('POST')) {
            return $this->render(['confirm' => true, 'type_label' => $typeLabel], 200, $locale);
        }

        $all = 'all' === $request->request->get('scope');
        if ($all) {
            $this->settings->setConsent($account, NotificationChannel::Email, false);
        } else {
            $this->settings->set($account, NotificationChannel::Email, $notificationType, false);
        }

        return $this->render(['done' => $all ? 'all' : 'type', 'type_label' => $typeLabel], 200, $locale);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(array $context, int $status, string $locale): Response
    {
        $response = new Response($this->twig->render('unsubscribe.html.twig', $context + ['locale' => $locale]), $status);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
