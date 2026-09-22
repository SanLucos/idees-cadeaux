<?php

declare(strict_types=1);

namespace App\Tests\Visibility;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;

/**
 * Base class for the visibility regression suite (CLAUDE.md règle 5,
 * spec §4.4): "pour chaque ressource cachée, un test vérifie qu'un
 * propriétaire ne la voit par aucun endpoint, ni via /sync, ni via
 * notification, ni via export." This suite must stay green at every
 * lot; every new hidden or private resource adds its cases here.
 *
 * Lot 0 only sets up the harness — comptes and authentication land in
 * lot 1, so helpers for authenticated requests are added then.
 */
abstract class VisibilityTestCase extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;
}
