<?php

namespace UltiorganizerStats\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ScrapeCommand extends Command
{
	protected function configure()
	{
		$this
			->setName('scrape')
			->setDescription('Scrape a Ultiorganizer instance');
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$output->writeln('Success!');
	}
}