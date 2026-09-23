<?php

declare(strict_types=1);

namespace App\Notification\Push;

use App\Entity\DeviceToken;

interface PushSender
{
    /**
     * @param DeviceToken[]         $tokens
     * @param array<string, string> $data   deep-link data for the app
     *
     * @return DeviceToken[] tokens the provider reported as invalid (to delete)
     */
    public function send(array $tokens, string $title, string $body, array $data): array;
}
