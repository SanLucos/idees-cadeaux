<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gesdinet\JWTRefreshTokenBundle\Entity\RefreshToken as BaseRefreshToken;

/**
 * Internal auth artifact (refresh token rotation, spec §5.1) — not part
 * of the synced domain model in spec §6, so it keeps the bundle's own
 * integer id rather than our UUID/timestamp/soft-delete conventions.
 *
 * A long session (sign-in by emailed code, spec §11 décision 68) lasts
 * far longer than the usual ~30 days, and stays long across rotations
 * (App\EventListener\LongSessionRotationListener).
 */
#[ORM\Entity]
#[ORM\Table(name: 'refresh_token')]
class RefreshToken extends BaseRefreshToken
{
    #[ORM\Column(options: ['default' => false])]
    private bool $longSession = false;

    public function isLongSession(): bool
    {
        return $this->longSession;
    }

    public function setLongSession(bool $longSession): void
    {
        $this->longSession = $longSession;
    }
}
