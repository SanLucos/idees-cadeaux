<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Repository\DataExportRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Spec §5.13: an export's link lasts 48 h; the archive (personal data)
 * doesn't outlive it. Hourly, so it's gone soon after.
 */
#[AsPeriodicTask(frequency: '1 hour')]
final class PurgeExpiredExportsTask
{
    public function __construct(
        private readonly DataExportRepository $exports,
        private readonly EntityManagerInterface $em,
        #[Autowire(service: 'default.storage')]
        private readonly FilesystemOperator $storage,
    ) {
    }

    public function __invoke(): int
    {
        $expired = $this->exports->findExpired(new \DateTimeImmutable());
        foreach ($expired as $export) {
            if (null !== $export->getPath()) {
                try {
                    $this->storage->delete($export->getPath());
                } catch (FilesystemException) {
                    // Already gone: the row goes anyway.
                }
            }
            $this->em->remove($export);
        }
        $this->em->flush();

        return \count($expired);
    }
}
