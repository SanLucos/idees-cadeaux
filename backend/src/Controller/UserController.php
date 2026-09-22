<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Serializer\UserNormalizer;
use App\Service\AvatarUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class UserController
{
    public function __construct(
        private readonly UserNormalizer $normalizer,
        private readonly EntityManagerInterface $em,
        private readonly AvatarUploadService $avatarUploads,
    ) {
    }

    #[Route('/api/users/me', name: 'users_me_get', methods: ['GET'])]
    public function me(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse($this->normalizer->normalize($user));
    }

    /**
     * Sets the profile fields that make up onboarding (spec §5.1/§5.2):
     * pseudo (2–30 chars), birthday (day+month together, year optional).
     */
    #[Route('/api/users/me', name: 'users_me_patch', methods: ['PATCH'])]
    public function update(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        if (!\is_array($body)) {
            throw new ApiProblemException('validation.invalid_body', 'Malformed JSON body.', 400);
        }

        if (\array_key_exists('displayName', $body)) {
            $displayName = trim((string) $body['displayName']);
            if (mb_strlen($displayName) < 2 || mb_strlen($displayName) > 30) {
                throw new ApiProblemException('validation.display_name_invalid', 'Pseudo must be 2 to 30 characters.', 422);
            }
            $user->setDisplayName($displayName);
        }

        if (\array_key_exists('birthDay', $body) || \array_key_exists('birthMonth', $body) || \array_key_exists('birthYear', $body)) {
            $day = $body['birthDay'] ?? $user->getBirthDay();
            $month = $body['birthMonth'] ?? $user->getBirthMonth();
            $year = \array_key_exists('birthYear', $body) ? $body['birthYear'] : $user->getBirthYear();

            if (null === $day && null === $month && null === $year) {
                $user->setBirthDate(null, null, null);
            } else {
                if (!self::isValidDayMonth($day, $month)) {
                    throw new ApiProblemException('validation.birth_date_invalid', 'birthDay and birthMonth are both required together and must be valid.', 422);
                }
                if (null !== $year && (!\is_int($year) || $year < 1900 || $year > (int) date('Y'))) {
                    throw new ApiProblemException('validation.birth_date_invalid', 'birthYear is out of range.', 422);
                }
                $user->setBirthDate((int) $day, (int) $month, null !== $year ? (int) $year : null);
            }
        }

        if (\array_key_exists('locale', $body)) {
            $locale = (string) $body['locale'];
            if (!\in_array($locale, ['fr', 'en'], true)) {
                throw new ApiProblemException('validation.locale_invalid', 'Unsupported locale.', 422);
            }
            $user->setLocale($locale);
        }

        $this->em->flush();

        return new JsonResponse($this->normalizer->normalize($user));
    }

    #[Route('/api/users/me/avatar', name: 'users_me_avatar', methods: ['POST'])]
    public function uploadAvatar(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $file = $request->files->get('avatar');
        if (null === $file) {
            throw new ApiProblemException('validation.avatar_required', 'The "avatar" file field is required.', 422);
        }

        $path = $this->avatarUploads->upload($user, $file);
        $user->setAvatarPath($path);
        $this->em->flush();

        return new JsonResponse($this->normalizer->normalize($user));
    }

    private static function isValidDayMonth(mixed $day, mixed $month): bool
    {
        if (!\is_int($day) || !\is_int($month)) {
            return false;
        }

        return $month >= 1 && $month <= 12 && $day >= 1 && $day <= 31;
    }
}
