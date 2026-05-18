<?php

declare(strict_types=1);

namespace App\Command;

use App\Model\Question;
use App\Repository\QuestionRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'questions:cleanup', description: 'Delete questions by category, or truncate everything with --all')]
final class CleanupQuestionsCommand extends Command
{
    private const DATA_FILE  = __DIR__ . '/../../data/questions.json';
    private const PUBLIC_DIR = __DIR__ . '/../../public';

    protected function configure(): void
    {
        $this
            ->addArgument('category', InputArgument::OPTIONAL, 'Category to delete (e.g. song_guess, flag_mc, location)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Delete ALL questions and their local images')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview changes without writing anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        if ($input->getOption('all')) {
            return $this->truncateAll($io, $dryRun);
        }

        $category = (string) $input->getArgument('category');
        if ($category === '') {
            $io->error('Provide a category name, or use --all to delete everything.');
            return Command::FAILURE;
        }

        return $this->deleteCategory($io, $category, $dryRun);
    }

    private function truncateAll(SymfonyStyle $io, bool $dryRun): int
    {
        $repository = new QuestionRepository(self::DATA_FILE);
        $questions  = $repository->load();
        $images     = $this->gatherLocalImagePaths($questions);

        $io->writeln(sprintf(
            'This will delete ALL <comment>%d</comment> questions and <comment>%d</comment> local image(s).',
            count($questions),
            count($images),
        ));

        if ($dryRun) {
            $this->printDryRun($io, $images);
            $io->note('Dry run — no changes written.');
            return Command::SUCCESS;
        }

        if (!$io->confirm('Delete ALL questions? This cannot be undone.', false)) {
            $io->writeln('Aborted.');
            return Command::SUCCESS;
        }

        $deletedImages = $this->deleteImages($images);
        $repository->save([]);

        $io->success(sprintf('Deleted all %d questions and %d image(s).', count($questions), $deletedImages));

        return Command::SUCCESS;
    }

    private function deleteCategory(SymfonyStyle $io, string $category, bool $dryRun): int
    {
        $repository = new QuestionRepository(self::DATA_FILE);
        $all        = $repository->load();

        $toDelete = array_values(array_filter($all, fn(Question $question) => $question->category === $category));
        $toKeep   = array_values(array_filter($all, fn(Question $question) => $question->category !== $category));

        if (empty($toDelete)) {
            $io->warning(sprintf('No questions found for category "%s".', $category));
            return Command::SUCCESS;
        }

        $orphanedImages = $this->collectOrphanedImages($toDelete, $toKeep);

        $io->writeln(sprintf(
            'Category: <info>%s</info>  To delete: <comment>%d</comment>  Remaining: <info>%d</info>  Images to unlink: <comment>%d</comment>',
            $category,
            count($toDelete),
            count($toKeep),
            count($orphanedImages),
        ));

        if ($dryRun) {
            $this->printDryRun($io, $orphanedImages);
            $io->note('Dry run — no changes written.');
            return Command::SUCCESS;
        }

        if (!$io->confirm(sprintf('Delete %d questions of category "%s"?', count($toDelete), $category), false)) {
            $io->writeln('Aborted.');
            return Command::SUCCESS;
        }

        $repository->save($toKeep);
        $deletedImages = $this->deleteImages($orphanedImages);

        $io->success(sprintf('Deleted %d questions. %d image(s) unlinked.', count($toDelete), $deletedImages));

        return Command::SUCCESS;
    }

    /** @param Question[] $toDelete @param Question[] $toKeep @return string[] */
    private function collectOrphanedImages(array $toDelete, array $toKeep): array
    {
        $deletedPaths = $this->gatherLocalImagePaths($toDelete);

        if (empty($deletedPaths)) {
            return [];
        }

        $keptPaths = $this->gatherLocalImagePaths($toKeep);

        return array_values(array_diff($deletedPaths, $keptPaths));
    }

    /** @param Question[] $questions @return string[] */
    private function gatherLocalImagePaths(array $questions): array
    {
        $paths = [];

        foreach ($questions as $question) {
            if ($question->imagePath !== null && !str_starts_with($question->imagePath, 'http')) {
                $paths[] = $question->imagePath;
            }

            foreach ($question->options ?? [] as $option) {
                if (isset($option['image_path']) && !str_starts_with($option['image_path'], 'http')) {
                    $paths[] = $option['image_path'];
                }
            }
        }

        return array_unique($paths);
    }

    /** @param string[] $relativePaths @return int deleted count */
    private function deleteImages(array $relativePaths): int
    {
        $deleted = 0;

        foreach ($relativePaths as $path) {
            $absolute = self::PUBLIC_DIR . '/' . ltrim($path, '/');
            if (is_file($absolute) && unlink($absolute)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /** @param string[] $images */
    private function printDryRun(SymfonyStyle $io, array $images): void
    {
        if (empty($images)) {
            return;
        }

        $io->writeln('Images that would be unlinked:');
        foreach ($images as $path) {
            $io->writeln(sprintf('  - %s', $path));
        }
    }
}
