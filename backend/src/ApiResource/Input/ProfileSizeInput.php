<?php

declare(strict_types=1);

namespace App\ApiResource\Input;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class ProfileSizeInput
{
    #[Groups(['profile_size:write'])]
    #[Assert\NotBlank]
    #[Assert\Length(max: 60)]
    public string $label = '';

    #[Groups(['profile_size:write'])]
    #[Assert\NotBlank]
    #[Assert\Length(max: 60)]
    public string $value = '';

    #[Groups(['profile_size:write'])]
    #[Assert\Length(max: 200)]
    public ?string $note = null;

    #[Groups(['profile_size:write'])]
    public int $sortOrder = 0;
}
