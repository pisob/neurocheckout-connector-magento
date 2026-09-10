<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Console\Command;

use Magento\Framework\App\State;
use NeuroCheckout\Connector\Model\Setup\DataPurger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PurgeDataCommand extends Command
{
    private State $appState;
    private DataPurger $dataPurger;

    public function __construct(
        State $appState,
        DataPurger $dataPurger
    ) {
        parent::__construct();
        $this->appState = $appState;
        $this->dataPurger = $dataPurger;
    }

    protected function configure(): void
    {
        $this->setName('neurocheckout:connector:purge-data')
            ->setDescription('Remove all NeuroCheckout connector tables, config rows, setup metadata and scheduled cron rows')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirm destructive purge');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->getOption('force')) {
            $output->writeln('<error>Refusing to purge without --force.</error>');
            return Command::FAILURE;
        }

        try {
            $this->appState->setAreaCode('adminhtml');
        } catch (\Throwable $e) {
        }

        $result = $this->dataPurger->purge();
        $output->writeln(json_encode($result, JSON_UNESCAPED_UNICODE));

        return Command::SUCCESS;
    }
}
