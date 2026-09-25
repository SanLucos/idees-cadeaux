<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Message\ExportUserData;
use App\Message\SendAccountEmail;
use App\MessageHandler\ExportUserDataHandler;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Runs the export queued by the last request and downloads it, as the
 * worker and the emailed link would.
 */
trait DataExportTestTrait
{
    /**
     * @return array{link: SendAccountEmail, files: array<string, string>}
     */
    protected function runExport(): array
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $handler = self::getContainer()->get(ExportUserDataHandler::class);
        foreach ($transport->getSent() as $envelope) {
            if ($envelope->getMessage() instanceof ExportUserData) {
                $handler($envelope->getMessage());
            }
        }

        $link = null;
        foreach ($transport->getSent() as $envelope) {
            if ($envelope->getMessage() instanceof SendAccountEmail && 'export_ready' === $envelope->getMessage()->kind) {
                $link = $envelope->getMessage();
            }
        }
        self::assertNotNull($link, 'the export link was emailed');

        return ['link' => $link, 'files' => $this->download((string) $link->actionUrl)];
    }

    /**
     * @return array<string, string> file name → content
     */
    protected function download(string $url): array
    {
        $response = static::createClient()->request('GET', (string) parse_url($url, \PHP_URL_PATH));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/zip', $response->getHeaders()['content-type'][0]);

        $file = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($file, $response->getContent());
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($file));
        $files = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = (string) $zip->getNameIndex($i);
            $files[$name] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($file);

        return $files;
    }
}
