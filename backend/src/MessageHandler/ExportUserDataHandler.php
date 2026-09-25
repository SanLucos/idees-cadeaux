<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\DataExport;
use App\Message\ExportUserData;
use App\Message\SendAccountEmail;
use App\Repository\DataExportRepository;
use App\Service\DataExportBuilder;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds the ZIP (JSON files + uploaded images, spec §5.13), stores it
 * privately, then emails its 48-hour link to whoever asked.
 */
#[AsMessageHandler]
final class ExportUserDataHandler
{
    public function __construct(
        private readonly DataExportRepository $exports,
        private readonly DataExportBuilder $builder,
        private readonly EntityManagerInterface $em,
        #[Autowire(service: 'default.storage')]
        private readonly FilesystemOperator $storage,
        private readonly MessageBusInterface $bus,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExportUserData $message): void
    {
        $export = $this->exports->find($message->exportId);
        if (null === $export || DataExport::PENDING !== $export->getStatus()) {
            return;
        }

        $zipFile = tempnam(sys_get_temp_dir(), 'export');
        try {
            $this->writeZip($zipFile, $this->builder->build($export->getSubject()));
            $path = 'exports/'.$export->getId()->toRfc4122().'.zip';
            $stream = fopen($zipFile, 'r');
            $this->storage->writeStream($path, $stream, ['visibility' => 'private', 'mimetype' => 'application/zip']);
            \is_resource($stream) && fclose($stream);
        } catch (\Throwable $e) {
            $this->logger->error('Data export failed.', ['export' => $message->exportId, 'exception' => $e]);
            $export->markFailed();
            $this->em->flush();

            return;
        } finally {
            @unlink($zipFile);
        }

        $token = $export->markReady($path);
        $this->em->flush();

        $recipient = $export->getRequestedBy();
        $subject = $export->getSubject();
        if (null === $recipient->getEmail()) {
            return;
        }
        $this->bus->dispatch(new SendAccountEmail(
            'export_ready',
            $recipient->getEmail(),
            $recipient->getLocale(),
            ['%profile%' => (string) $subject->getDisplayName()],
            $this->urls->generate('data_export_download', ['id' => $export->getId()->toRfc4122(), 'token' => $token], UrlGeneratorInterface::ABSOLUTE_URL),
            $subject !== $recipient,
        ));
    }

    /**
     * @param array{json: array<string, mixed>, images: array<string, string>} $content
     */
    private function writeZip(string $file, array $content): void
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException('Cannot create the export archive.');
        }

        foreach ($content['json'] as $name => $data) {
            $zip->addFromString($name, json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
        }
        foreach ($content['images'] as $name => $storagePath) {
            try {
                $zip->addFromString($name, $this->storage->read($storagePath));
            } catch (FilesystemException $e) {
                // A missing image doesn't cancel the export.
                $this->logger->warning('Image missing from a data export.', ['path' => $storagePath, 'exception' => $e]);
            }
        }

        $zip->close();
    }
}
