<?php

declare(strict_types=1);

namespace App\ApiResource\Input;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class ProfileSizeInput
{
    /** Client-generated UUID (CLAUDE.md règle 9). Not `id`: API Platform would read that as an update. */
    #[Groups(['profile_size:write'])]
    #[Assert\Uuid]
    public ?string $clientId = null;

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
