<?php

declare(strict_types=1);

namespace App\ArgumentResolver;

use App\Entity\User;
use App\Security\ActingContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsTargetedValueResolver;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[AsTargetedValueResolver]
final class ActingUserResolver implements ValueResolverInterface
{
    public function __construct(private readonly ActingContext $acting)
    {
    }

    /**
     * @return iterable<User>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        return [$this->acting->actor() ?? throw new AccessDeniedException()];
    }
}
