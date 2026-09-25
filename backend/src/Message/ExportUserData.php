<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Spec §5.13 "Export de mes données": builds the archive of one
 * account or child profile (a DataExport row), then emails the link.
 */
final class ExportUserData
{
    public function __construct(public readonly string $exportId)
    {
    }
}
