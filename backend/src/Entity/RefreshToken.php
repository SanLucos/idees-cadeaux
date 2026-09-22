<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gesdinet\JWTRefreshTokenBundle\Entity\RefreshToken as BaseRefreshToken;

/**
 * Internal auth artifact (refresh token rotation, spec §5.1) — not part
 * of the synced domain model in spec §6, so it keeps the bundle's own
 * integer id rather than our UUID/timestamp/soft-delete conventions.
 */
#[ORM\Entity]
#[ORM\Table(name: 'refresh_token')]
class RefreshToken extends BaseRefreshToken
{
}
