<?php

declare(strict_types=1);

namespace App\ApiResource\Input;

use App\Entity\Enum\ProfilePreferenceCategory;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class ProfilePreferenceInput
{
    #[Groups(['profile_preference:write'])]
    public ProfilePreferenceCategory $category = ProfilePreferenceCategory::Other;

    #[Groups(['profile_preference:write'])]
    #[Assert\NotBlank]
    #[Assert\Length(max: 60)]
    public string $label = '';

    #[Groups(['profile_preference:write'])]
    #[Assert\NotBlank]
    #[Assert\Length(max: 200)]
    public string $value = '';
}
