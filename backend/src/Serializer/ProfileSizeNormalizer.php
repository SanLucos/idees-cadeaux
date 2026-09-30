<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Entity\ProfileSize;
use App\Security\ActingContext;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Adds `history` to a size for its owner or the manager of a child
 * profile — acting as the child or not — and for nobody else (spec §11
 * décision 51): a friend gets the very same document without the field.
 */
final class ProfileSizeNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const string ALREADY_CALLED = 'profile_size_normalizer_already_called';

    public function __construct(private readonly ActingContext $acting)
    {
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>|string|int|float|bool|\ArrayObject<int|string, mixed>|null
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        $normalized = $this->normalizer->normalize($data, $format, $context + [self::ALREADY_CALLED => true]);

        if (\is_array($normalized) && $data instanceof ProfileSize && ProfileSizeHistoryView::isReadableBy($data, $this->acting->actor(), $this->acting->human())) {
            $normalized['history'] = ProfileSizeHistoryView::entries($data);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof ProfileSize && !isset($context[self::ALREADY_CALLED]);
    }

    /**
     * @return array<class-string, bool>
     */
    public function getSupportedTypes(?string $format): array
    {
        // Depends on the context (ALREADY_CALLED): never cacheable.
        return [ProfileSize::class => false];
    }
}
