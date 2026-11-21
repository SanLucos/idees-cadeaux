<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The only way to become superadmin (spec §5.17, §11 décision 71): no
 * screen grants the role.
 */
#[AsCommand(name: 'app:admin:grant', description: 'Grants (or with --revoke, removes) the superadmin role to an account')]
final class GrantAdminCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email of an adult account')
            ->addOption('revoke', null, InputOption::VALUE_NONE, 'Remove the role instead');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = mb_strtolower(trim((string) $input->getArgument('email')));
        $user = $this->users->findOneBy(['email' => $email]);
        if (!$user instanceof User || $user->isManaged()) {
            $io->error(sprintf('No adult account with the email %s.', $email));

            return Command::FAILURE;
        }

        $revoke = (bool) $input->getOption('revoke');
        $user->setSuperAdmin(!$revoke);
        $this->em->flush();

        $io->success(sprintf($revoke ? '%s is no longer superadmin.' : '%s is now superadmin.', $email));

        return Command::SUCCESS;
    }
}
