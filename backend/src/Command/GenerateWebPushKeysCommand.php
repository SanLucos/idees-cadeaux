<?php

declare(strict_types=1);

namespace App\Command;

use Minishlink\WebPush\VAPID;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Web Push (spec §11 décision 70): prints a new VAPID key pair to copy
 * into VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY (backend/.env.local, or
 * .env.prod). Generate it once per environment: changing it invalidates
 * every browser subscription (the web app subscribes again at its next
 * start).
 */
#[AsCommand(name: 'app:web-push:generate-keys', description: 'Generates a VAPID key pair for Web Push')]
final class GenerateWebPushKeysCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $keys = VAPID::createVapidKeys();
        $output->writeln('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $output->writeln('VAPID_PRIVATE_KEY='.$keys['privateKey']);

        return Command::SUCCESS;
    }
}
