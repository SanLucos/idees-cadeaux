<?php

declare(strict_types=1);

namespace App\Controller\ShareLink;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Spec §5.16 « Mode invité dans l'appli »: the files that let
 * `https://<domaine>/u/…` open the installed app — Android App Links
 * and iOS Universal Links. Served only once configured
 * (ANDROID_CERT_FINGERPRINTS, APPLE_TEAM_ID); 404 until then.
 */
final class AppLinksController
{
    public function __construct(
        private readonly string $appId,
        private readonly string $androidCertFingerprints,
        private readonly string $appleTeamId,
    ) {
    }

    #[Route('/.well-known/assetlinks.json', name: 'app_links_android', methods: ['GET'])]
    public function android(): Response
    {
        $fingerprints = array_values(array_filter(array_map('trim', explode(',', $this->androidCertFingerprints))));
        if ([] === $fingerprints) {
            return new Response('', 404);
        }

        return new JsonResponse([[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => ['namespace' => 'android_app', 'package_name' => $this->appId, 'sha256_cert_fingerprints' => $fingerprints],
        ]]);
    }

    #[Route('/.well-known/apple-app-site-association', name: 'app_links_ios', methods: ['GET'])]
    public function ios(): Response
    {
        if ('' === trim($this->appleTeamId)) {
            return new Response('', 404);
        }

        return new JsonResponse(['applinks' => ['details' => [[
            'appIDs' => [trim($this->appleTeamId).'.'.$this->appId],
            'components' => [['/' => '/u/*']],
        ]]]]);
    }
}
