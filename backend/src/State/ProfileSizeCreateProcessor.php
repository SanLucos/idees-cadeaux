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

        if ($this->repository->countForUser($user) >= self::MAX_PER_USER) {
            throw new ApiProblemException('profile_size.limit_reached', 'Maximum of 100 sizes reached.', 422);
        }

        $entity = new ProfileSize($user, $data->label, $data->value, $data->note, $data->sortOrder);

        $this->em->persist($entity);
        $this->em->flush();

        return $entity;
    }
}
