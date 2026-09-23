<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\ProfilePreferenceInput;
use App\Entity\ProfilePreference;
use App\Entity\User;
use App\Exception\ApiProblemException;
use Doctrine\ORM\EntityManagerInterface;
use App\Security\ActingContext;

/**
 * @implements ProcessorInterface<ProfilePreferenceInput, ProfilePreference>
 */
final class ProfilePreferenceCreateProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ActingContext $acting,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProfilePreference
    {
        $user = $this->acting->actor();
        if (!$user instanceof User) {
            throw new ApiProblemException('auth.required', 'Authentication required.', 401);
        }

        $entity = new ProfilePreference($user, $data->category, $data->label, $data->value);

        $this->em->persist($entity);
        $this->em->flush();

        return $entity;
    }
}
