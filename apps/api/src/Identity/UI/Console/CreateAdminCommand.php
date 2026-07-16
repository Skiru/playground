<?php

declare(strict_types=1);

namespace App\Identity\UI\Console;

use App\Identity\Application\CreateAdmin;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:user:create-admin')]
final class CreateAdminCommand extends Command
{
    public function __construct(
        private CreateAdmin $createAdmin,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'The email of the admin');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');
        $password = (string) $io->askHidden('Password (minimum 12 characters)');
        $displayName = (string) ($io->ask('Display name', $email) ?? $email);

        try {
            $this->createAdmin->execute($email, $password, $displayName);
            $io->success('Administrator created.');

            return Command::SUCCESS;
        } catch (\DomainException|\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
