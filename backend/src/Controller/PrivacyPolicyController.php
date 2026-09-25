<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * « Politique de confidentialité accessible dans l'appli » (spec §5.13):
 * a static page the app opens, in French or English (`?lang=`, else the
 * browser's language). The text is a draft to be reviewed by a lawyer;
 * `[À COMPLÉTER]` marks what only the publisher can fill in.
 */
final class PrivacyPolicyController
{
    public function __construct(private readonly Environment $twig)
    {
    }

    #[Route('/privacy', name: 'privacy_policy', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $lang = $request->query->get('lang');
        $locale = \in_array($lang, ['fr', 'en'], true) ? $lang : ($request->getPreferredLanguage(['fr', 'en']) ?? 'fr');

        $response = new Response($this->twig->render("legal/privacy.{$locale}.html.twig", ['locale' => $locale]));
        $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'public, max-age=3600');

        return $response;
    }
}
