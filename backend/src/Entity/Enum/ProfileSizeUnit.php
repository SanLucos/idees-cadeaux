<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * The units a size can carry (spec §5.2, §11 décision 57). No unit
 * (null) is the default, and what sizes written before units had.
 */
enum ProfileSizeUnit: string
{
    case Centimetre = 'cm';
    case Millimetre = 'mm';
    case Metre = 'm';
    case Gram = 'g';
    case Kilogram = 'kg';
    case Millilitre = 'ml';
    case Litre = 'l';
    case Eu = 'eu';
    case Us = 'us';
    case Uk = 'uk';
}
