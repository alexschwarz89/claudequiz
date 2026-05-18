<?php

declare(strict_types=1);

namespace App\Command;

use App\Room\RoomRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'rooms:cleanup', description: 'Delete expired room files older than the configured TTL')]
final class CleanupRoomsCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        (new RoomRepository())->cleanup();

        $io->success('Expired rooms deleted.');

        return Command::SUCCESS;
    }
}
