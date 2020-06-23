<?php

namespace blacksenator;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RunCommand extends Command
{
    use ConfigTrait;

    protected function configure()
    {
        $this->setName('run')
            ->setDescription('perpetual')
            ->addOption('test', 't', InputOption::VALUE_NONE, 'test number(s)');

        $this->addConfig();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->loadConfig($input);
        error_log('Starting FRITZ!Box call router...');
        $testNumbers = [];
        if ($input->getOption('test')) {
            $testNumbers = $this->config['test']['numbers'] ?? [];
        }
        callRouter($this->config, $testNumbers);
    }
}
