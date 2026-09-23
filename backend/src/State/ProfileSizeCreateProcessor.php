<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\ProfileSizeInput;
use App\Entity\ProfileSize;
use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Repository\ProfileSizeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use App\Security\ActingContext;

/**
 * @implements ProcessorInterface<ProfileSizeInput, ProfileSize>
 */
final class ProfileSizeCreateProcessor implements ProcessorInterface
{
    private const int MAX_PER_USER = 100;

    public function __construct(
        private readonly ActingContext $acting,
        private readonly ProfileSizeRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProfileSize
    {
        $user = $this->acting->actor();
        if (!$user instanceof User) {
            throw new ApiProblemException('auth.required', 'Authentication required.', 401);
        }

        $id = null !== $data->clientId ? Uuid::fromString($data->clientId) : null;
        if (null !== $id && null !== $existing = $this->em->find(ProfileSize::class, $id)) {
            // Replayed offline create (spec §8): same entry, not a duplicate.
            if ($existing->getUser() !== $user) {
                throw new ApiProblemException('request.conflict', 'This id is already used.', 409);
            }

            return $existing;
        }

        if ($this->repository->countForUser($user) >= self::MAX_PER_USER) {
            throw new ApiProblemException('profile_size.limit_reached', 'Maximum of 100 sizes reached.', 422);
        }

        $entity = new ProfileSize($user, $data->label, $data->value, $data->note, $data->sortOrder, $id);

        $this->em->persist($entity);
        $this->em->flush();

        return $entity;
    }
}
