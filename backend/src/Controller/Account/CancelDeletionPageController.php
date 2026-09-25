<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Repository\UserRepository;
use App\Service\AccountDeletion;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Twig\Environment;

/**
 * The deletion email's cancel link (spec §5.13 "via le lien de
 * l'email"). GET only asks for confirmation — link scanners must not
 * cancel anything — POST cancels.
 */
final class CancelDeletionPageController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AccountDeletion $deletion,
        private readonly Environment $twig,
    ) {
    }

    #[Route('/account/cancel-deletion/{user}/{signature}', name: 'account_cancel_deletion', methods: ['GET', 'POST'], requirements: ['user' => Requirement::UUID])]
    public function __invoke(string $user, string $signature, Request $request): Response
    {
        $account = $this->users->find($user);
        if (null === $account || !$this->deletion->isValidCancelSignature($account, $signature)) {
            return $this->render(['state' => 'invalid'], 404, $request->getPreferredLanguage(['fr', 'en']) ?? 'fr');
        }

        $locale = $account->getLocale();
        if (!$request->isMethod('POST')) {
            $date = (new \IntlDateFormatter($locale, \IntlDateFormatter::LONG, \IntlDateFormatter::NONE))->format($account->getDeletionScheduledAt());

            return $this->render(['state' => 'confirm', 'date' => $date], 200, $locale);
        }

        $this->deletion->cancel($account);

        return $this->render(['state' => 'done'], 200, $locale);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(array $context, int $status, string $locale): Response
    {
        $response = new Response($this->twig->render('account/cancel_deletion.html.twig', $context + ['locale' => $locale]), $status);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
