<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum ContributionStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
