<?php

declare(strict_types=1);

namespace App\Command;

use App\Model\Question;
use App\Repository\QuestionRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'questions:purge-reported', description: 'Remove questions listed in reported_questions.json from questions.json')]
final class PurgeReportedQuestionsCommand extends Command
{
    private const QUESTIONS_FILE = __DIR__ . '/../../data/questions.json';
    private const REPORTED_FILE  = __DIR__ . '/../../data/reported_questions.json';

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview which questions would be removed without writing anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $reportedIds = $this->loadReportedIds($io);
        if ($reportedIds === null) {
            return Command::FAILURE;
        }

        if (empty($reportedIds)) {
            $io->success('No reported question IDs found — nothing to do.');
            return Command::SUCCESS;
        }

        $repository = new QuestionRepository(self::QUESTIONS_FILE);
        $all        = $repository->load();

        $reportedSet = array_flip($reportedIds);
        $toRemove    = array_values(array_filter($all, fn(Question $q) => isset($reportedSet[$q->id])));
        $toKeep      = array_values(array_filter($all, fn(Question $q) => !isset($reportedSet[$q->id])));
        $notFound    = count($reportedIds) - count($toRemove);

        $io->writeln(sprintf(
            'Reported: <comment>%d</comment>  Found in questions.json: <comment>%d</comment>  Not found (already removed): <info>%d</info>  Remaining after purge: <info>%d</info>',
            count($reportedIds),
            count($toRemove),
            $notFound,
            count($toKeep),
        ));

        if (empty($toRemove)) {
            $io->success('All reported IDs were already absent from questions.json — nothing to do.');
            return Command::SUCCESS;
        }

        if ($dryRun) {
            $io->writeln('Questions that would be removed:');
            foreach ($toRemove as $question) {
                $io->writeln(sprintf('  [%s] <comment>%s</comment>  %s', $question->category, $question->id, $question->question));
            }
            $io->note('Dry run — no changes written.');
            return Command::SUCCESS;
        }

        if (!$io->confirm(sprintf('Remove %d reported question(s) from questions.json?', count($toRemove)), false)) {
            $io->writeln('Aborted.');
            return Command::SUCCESS;
        }

        $repository->save($toKeep);
        $io->success(sprintf('Removed %d question(s). %d remain in questions.json.', count($toRemove), count($toKeep)));

        return Command::SUCCESS;
    }

    /** @return string[]|null */
    private function loadReportedIds(SymfonyStyle $io): ?array
    {
        if (!file_exists(self::REPORTED_FILE)) {
            $io->error(sprintf('Reported questions file not found: %s', self::REPORTED_FILE));
            return null;
        }

        $decoded = json_decode((string) file_get_contents(self::REPORTED_FILE), true);

        if (!is_array($decoded)) {
            $io->error(sprintf('Could not parse %s as a JSON array.', self::REPORTED_FILE));
            return null;
        }

        return array_values(array_filter(array_map('strval', $decoded)));
    }
}
