<?php

declare(strict_types = 1);

namespace Mistrfilda\Redis\Bridges\Symfony\Console;

use Mistrfilda\Redis\Bridges\Nette\Caching\RedisStorage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('redis:cache:gc', 'Remove cache journal metadata whose cached values no longer exist.')]
final class CacheGarbageCollectCommand extends Command
{

	public function __construct(private readonly RedisStorage $storage)
	{
		parent::__construct();
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$result = $this->storage->collectGarbage();
		$output->writeln(sprintf(
			'Checked: %d; removed: %d; duration: %.3f s.',
			$result->checked,
			$result->removed,
			$result->durationSeconds,
		));

		return Command::SUCCESS;
	}

}
