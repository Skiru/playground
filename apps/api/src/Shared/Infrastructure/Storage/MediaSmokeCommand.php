<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Storage;

use App\Shared\Application\Storage\StorageInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:media-smoke',
    description: 'Runs runtime/smoke tests for the shared media storage.',
)]
final class MediaSmokeCommand extends Command
{
    private const SOURCE_PATH = 'places/00000000-0000-0000-0000-000000000000/photos/00000000-0000-0000-0000-000000000000/source';
    private const VARIANT_PATH = 'places/00000000-0000-0000-0000-000000000000/photos/00000000-0000-0000-0000-000000000000/variants/1/hero.webp';
    private const SENTINEL_CONTENT = 'sentinel-media-smoke-test';
    private const VARIANT_CONTENT = 'sentinel-variant-smoke-test';

    public function __construct(private readonly StorageInterface $storage)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('write-sentinel', null, InputOption::VALUE_NONE, 'API writes the sentinel to private source')
            ->addOption('process-sentinel', null, InputOption::VALUE_NONE, 'Worker reads the sentinel and writes public variant')
            ->addOption('verify-sentinel', null, InputOption::VALUE_NONE, 'API reads the public variant');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('write-sentinel')) {
            $output->writeln('Writing private sentinel to storage...');
            $this->storage->write(self::SOURCE_PATH, self::SENTINEL_CONTENT);
            $output->writeln('Private sentinel written successfully.');

            return Command::SUCCESS;
        }

        if ($input->getOption('process-sentinel')) {
            $output->writeln('Reading private sentinel from storage...');
            $content = $this->storage->read(self::SOURCE_PATH);
            if (self::SENTINEL_CONTENT !== $content) {
                $output->writeln(\sprintf('<error>Sentinel content mismatch! Expected "%s", got "%s"</error>', self::SENTINEL_CONTENT, $content));

                return Command::FAILURE;
            }
            $output->writeln('Private sentinel content verified. Writing public variant...');
            $this->storage->write(self::VARIANT_PATH, self::VARIANT_CONTENT);
            $output->writeln('Public variant written successfully.');

            return Command::SUCCESS;
        }

        if ($input->getOption('verify-sentinel')) {
            $output->writeln('Reading public variant from storage...');
            $content = $this->storage->read(self::VARIANT_PATH);
            if (self::VARIANT_CONTENT !== $content) {
                $output->writeln(\sprintf('<error>Variant content mismatch! Expected "%s", got "%s"</error>', self::VARIANT_CONTENT, $content));

                return Command::FAILURE;
            }
            $output->writeln('Public variant content verified successfully.');

            return Command::SUCCESS;
        }

        $output->writeln('Please specify one of the options: --write-sentinel, --process-sentinel, or --verify-sentinel');

        return Command::FAILURE;
    }
}
