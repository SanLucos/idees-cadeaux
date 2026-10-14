<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * Spec §5.2, §11 décision 57: a size has an optional unit from a fixed
 * list, none by default; the history keeps each value's unit.
 */
final class ProfileSizeUnitTest extends AuthTestCase
{
    use ApiRequestTrait;

    private string $nora;

    protected function setUp(): void
    {
        parent::setUp();
        $this->nora = $this->registerVerifyAndLogin('psu-nora@example.com');
    }

    public function testNoUnitByDefaultAndOneChosenLater(): void
    {
        $size = $this->expect(201, $this->nora, 'POST', '/api/profile_sizes', ['label' => 'Pointure', 'value' => '42']);
        self::assertNull($size['unit']);

        $patched = $this->expect(200, $this->nora, 'PATCH', $size['@id'], ['unit' => 'eu']);
        self::assertSame(['42', 'eu'], [$patched['value'], $patched['unit']], 'the value is kept');
        self::assertSame([['42', 'eu']], array_map(static fn (array $e) => [$e['value'], $e['unit']], $patched['history']), 'a new unit alone is no new value');

        self::assertNull($this->expect(200, $this->nora, 'PATCH', $size['@id'], ['unit' => null])['unit'], 'back to no unit');
    }

    public function testTheHistoryKeepsEachValuesUnit(): void
    {
        $size = $this->expect(201, $this->nora, 'POST', '/api/profile_sizes', ['label' => 'Tour de taille', 'value' => '80', 'unit' => 'cm']);
        $history = $this->expect(200, $this->nora, 'PATCH', $size['@id'], ['value' => '0.85', 'unit' => 'm'])['history'];

        self::assertSame([['80', 'cm'], ['0.85', 'm']], array_map(static fn (array $e) => [$e['value'], $e['unit']], $history));
    }

    public function testAnUnknownUnitIsRefused(): void
    {
        self::assertSame('profile_size.unit_invalid', $this->expect(422, $this->nora, 'POST', '/api/profile_sizes', ['label' => 'Pointure', 'value' => '42', 'unit' => 'inch'])['code']);

        $size = $this->expect(201, $this->nora, 'POST', '/api/profile_sizes', ['label' => 'Pointure', 'value' => '42']);
        self::assertSame('profile_size.unit_invalid', $this->expect(422, $this->nora, 'PATCH', $size['@id'], ['unit' => 'KG'])['code']);
        self::assertNull($this->expect(200, $this->nora, 'GET', $size['@id'])['unit']);
    }

    public function testTheUnitReachesTheDevices(): void
    {
        $this->expect(201, $this->nora, 'POST', '/api/profile_sizes', ['label' => 'Poids', 'value' => '3', 'unit' => 'kg']);

        $sync = $this->expect(200, $this->nora, 'POST', '/api/sync', []);
        self::assertSame('kg', $sync['changes']['profile_size'][0]['unit']);
    }
}
