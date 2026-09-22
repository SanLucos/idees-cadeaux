<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Entity\Enum\SocialProvider;
use App\Entity\Enum\UserType;
use App\Entity\SocialIdentity;
use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Repository\SocialIdentityRepository;
use App\Repository\UserRepository;
use App\Security\AuthTokenIssuer;
use App\Security\Social\AppleIdTokenVerifier;
use App\Security\Social\GoogleIdTokenVerifier;
use App\Security\Social\IdTokenClaims;
use App\Security\Social\IdTokenVerificationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /api/auth/social/{provider} (spec §5.1): exchanges a Google or
 * Apple ID token, verified server-side, for our own access + refresh
 * token pair — auto-linking to an existing account when the verified
 * email matches (spec: "rattachement automatique à un compte existant
 * si l'email est vérifié et identique").
 */
final class SocialAuthController
{
    public function __construct(
        private readonly GoogleIdTokenVerifier $google,
        private readonly AppleIdTokenVerifier $apple,
        private readonly SocialIdentityRepository $socialIdentities,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly AuthTokenIssuer $tokenIssuer,
    ) {
    }

    #[Route('/api/auth/social/{provider}', name: 'auth_social_login', methods: ['POST'], requirements: ['provider' => 'google|apple'])]
    public function __invoke(string $provider, Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        $idToken = \is_array($body) ? (string) ($body['idToken'] ?? '') : '';
        if ('' === $idToken) {
            throw new ApiProblemException('validation.id_token_required', 'idToken is required.', 422);
        }

        $socialProvider = SocialProvider::from($provider);

        try {
            $claims = $socialProvider === SocialProvider::Google ? $this->google->verify($idToken) : $this->apple->verify($idToken);
        } catch (IdTokenVerificationException $e) {
            throw new ApiProblemException('auth.social_token_invalid', $e->getMessage(), 401, $e);
        }

        $user = $this->resolveUser($socialProvider, $claims);

        return new JsonResponse($this->tokenIssuer->issue($user));
    }

    private function resolveUser(SocialProvider $provider, IdTokenClaims $claims): User
    {
        $identity = $this->socialIdentities->findOneByProviderAndSubject($provider, $claims->subject);
        if (null !== $identity) {
            return $identity->getUser();
        }

        $user = null;
        if (null !== $claims->email && $claims->emailVerified) {
            $existing = $this->users->findOneBy(['email' => mb_strtolower($claims->email)]);
            if (null !== $existing && null !== $existing->getEmailVerifiedAt()) {
                $user = $existing;
            }
        }

        if (null === $user) {
            if (null === $claims->email) {
                throw new ApiProblemException('auth.social_email_missing', 'This provider did not share an email address.', 422);
            }

            $user = new User(UserType::Regular, displayName: null);
            $user->setEmail(mb_strtolower($claims->email));
            if ($claims->emailVerified) {
                $user->setEmailVerifiedAt(new \DateTimeImmutable());
            }
            $this->em->persist($user);
        }

        $this->em->persist(new SocialIdentity($user, $provider, $claims->subject));
        $this->em->flush();

        return $user;
    }
}
