<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Entity\Enum\SocialProvider;
use App\Entity\SocialIdentity;
use App\Exception\ApiProblemException;
use App\Security\AuthTokenIssuer;
use App\Security\Social\AppleIdTokenVerifier;
use App\Security\Social\GoogleIdTokenVerifier;
use App\Security\Social\IdTokenVerificationException;
use App\Service\ManagedProfileInvitations;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "J'ai une invitation" (spec §5.15): the invitee takes over a managed
 * profile with the emailed code, then either a password or a Google/
 * Apple sign-in for that same email — and is signed straight in.
 * Public, rate-limited per IP on top of the per-invitation attempt cap.
 */
final class ManagedInvitationController
{
    public function __construct(
        private readonly ManagedProfileInvitations $invitations,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly GoogleIdTokenVerifier $google,
        private readonly AppleIdTokenVerifier $apple,
        private readonly AuthTokenIssuer $tokens,
        private readonly EntityManagerInterface $em,
        #[Autowire(service: 'limiter.invitation_accept')]
        private readonly RateLimiterFactory $rateLimiter,
    ) {
    }

    #[Route('/api/auth/managed-invitation/accept', name: 'auth_managed_invitation_accept', methods: ['POST'])]
    public function accept(Request $request): JsonResponse
    {
        $this->throttle($request);
        $body = self::decode($request);

        $password = (string) ($body['password'] ?? '');
        if (\strlen($password) < 10) {
            throw new ApiProblemException('validation.password_too_short', 'Password must be at least 10 characters.', 422);
        }

        $user = $this->invitations->redeem(self::email($body), (string) ($body['code'] ?? ''));
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $password));
        $this->em->flush();

        return new JsonResponse($this->tokens->issue($user));
    }

    #[Route('/api/auth/managed-invitation/accept-social/{provider}', name: 'auth_managed_invitation_accept_social', methods: ['POST'], requirements: ['provider' => 'google|apple'])]
    public function acceptSocial(string $provider, Request $request): JsonResponse
    {
        $this->throttle($request);
        $body = self::decode($request);

        $socialProvider = SocialProvider::from($provider);
        try {
            $idToken = (string) ($body['idToken'] ?? '');
            $claims = SocialProvider::Google === $socialProvider ? $this->google->verify($idToken) : $this->apple->verify($idToken);
        } catch (IdTokenVerificationException $e) {
            throw new ApiProblemException('auth.social_token_invalid', $e->getMessage(), 401, $e);
        }

        $email = self::email($body);
        if (!$claims->emailVerified || null === $claims->email || mb_strtolower($claims->email) !== $email) {
            throw new ApiProblemException('invitation.email_mismatch', 'Sign in with the invited email address.', 422);
        }

        $user = $this->invitations->redeem($email, (string) ($body['code'] ?? ''));
        $this->em->persist(new SocialIdentity($user, $socialProvider, $claims->subject));
        $this->em->flush();

        return new JsonResponse($this->tokens->issue($user));
    }

    private function throttle(Request $request): void
    {
        if (!$this->rateLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            throw new ApiProblemException('request.rate_limited', 'Too many attempts.', 429);
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function email(array $body): string
    {
        return mb_strtolower(trim((string) ($body['email'] ?? '')));
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(Request $request): array
    {
        $body = json_decode($request->getContent() ?: '{}', true);
        if (!\is_array($body)) {
            throw new ApiProblemException('validation.invalid_body', 'Malformed JSON body.', 400);
        }

        return $body;
    }
}
