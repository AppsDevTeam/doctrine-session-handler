<?php

declare(strict_types=1);

namespace ADT\DoctrineSessionHandler;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Cleans up expired sessions - intended to be run from cron.
 *
 * Replaces PHP's inline garbage collection (`session.gc_probability`), which deletes
 * all expired sessions at once in the middle of a request. Here they are deleted in
 * batches and outside of a request, so neither the database nor PHP-FPM gets flooded.
 *
 * Recommended setup (see README):
 *  - `session.gc_probability = 0` (turn off inline GC),
 *  - cron: `php bin/console session:cleanup`, e.g. every 10 minutes.
 */
#[AsCommand(name: 'session:cleanup', description: 'Deletes expired sessions in batches.')]
class CleanupCommand extends Command
{
	public function __construct(private readonly SessionCleaner $cleaner)
	{
		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->addOption(
				'batch-size',
				null,
				InputOption::VALUE_REQUIRED,
				'Number of sessions deleted in one transaction',
				(string) SessionCleaner::DEFAULT_BATCH_SIZE,
			)
			->addOption(
				'max-batches',
				null,
				InputOption::VALUE_REQUIRED,
				'Stop after this many batches (default: continue until nothing is left)',
			)
			->addOption(
				'sleep',
				null,
				InputOption::VALUE_REQUIRED,
				'Pause between batches in seconds (eases replication load)',
				'0',
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$maxBatches = $input->getOption('max-batches');

		$deleted = $this->cleaner->cleanup(
			(int) $input->getOption('batch-size'),
			$maxBatches !== null ? (int) $maxBatches : null,
			(float) $input->getOption('sleep'),
			$output->isVerbose()
				? static fn(int $inBatch, int $total) => $output->writeln(sprintf('  deleted %d (total %d)', $inBatch, $total))
				: null,
		);

		$output->writeln(sprintf('<info>Deleted %d expired session(s).</info>', $deleted));

		return Command::SUCCESS;
	}
}
