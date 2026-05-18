<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\QuestionRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'game:stats', description: 'Show question and room statistics')]
final class StatsCommand extends Command
{
    private const DATA_FILE = __DIR__ . '/../../data/questions.json';
    private const ROOMS_DIR = __DIR__ . '/../../data/rooms';
    private const ROOM_TTL  = 7200;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('ClaudeQuiz Statistics');

        $this->printQuestionStats($io);
        $this->printRoomStats($io);

        return Command::SUCCESS;
    }

    private function printQuestionStats(SymfonyStyle $io): void
    {
        $repository = new QuestionRepository(self::DATA_FILE);
        $questions  = $repository->load();
        $total      = count($questions);

        $io->section('Questions');
        $io->writeln(sprintf('  Total: <info>%d</info>', $total));

        if ($total === 0) {
            $io->writeln('  No questions found. Run <comment>aggregate:content</comment> first.');
            return;
        }

        $byCategory = [];
        foreach ($questions as $question) {
            $byCategory[$question->category] = ($byCategory[$question->category] ?? 0) + 1;
        }

        arsort($byCategory);

        $rows = [];
        foreach ($byCategory as $category => $count) {
            $rows[] = [$category, $count, sprintf('%d%%', (int) round($count / $total * 100))];
        }

        $io->table(['Category', 'Count', 'Share'], $rows);
    }

    private function printRoomStats(SymfonyStyle $io): void
    {
        $io->section('Rooms');

        $files = glob(self::ROOMS_DIR . '/*.json') ?: [];

        if (empty($files)) {
            $io->writeln('  No rooms found.');
            return;
        }

        $cutoff    = time() - self::ROOM_TTL;
        $byPhase   = [];
        $activeCount  = 0;
        $expiredCount = 0;

        foreach ($files as $file) {
            $room  = json_decode(file_get_contents($file), true);
            $phase = $room['phase'] ?? 'unknown';
            $byPhase[$phase] = ($byPhase[$phase] ?? 0) + 1;

            if (filemtime($file) >= $cutoff) {
                $activeCount++;
            } else {
                $expiredCount++;
            }
        }

        $io->writeln(sprintf('  Total:   <info>%d</info>', count($files)));
        $io->writeln(sprintf('  Active:  <info>%d</info>  (modified within last 2 h)', $activeCount));
        $io->writeln(sprintf('  Expired: <comment>%d</comment>', $expiredCount));
        $io->newLine();

        ksort($byPhase);

        $rows = [];
        foreach ($byPhase as $phase => $count) {
            $rows[] = [$phase, $count];
        }

        $io->table(['Phase', 'Count'], $rows);
    }
}
