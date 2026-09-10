<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Console\Command;

use Magento\Framework\App\State;
use Magento\Store\Model\StoreManagerInterface;
use NeuroCheckout\Connector\Model\Application\CronExecutor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RunCronCommand extends Command
{
    private State $appState;
    private StoreManagerInterface $storeManager;
    private CronExecutor $cronExecutor;

    public function __construct(
        State $appState,
        StoreManagerInterface $storeManager,
        CronExecutor $cronExecutor
    ) {
        parent::__construct();
        $this->appState = $appState;
        $this->storeManager = $storeManager;
        $this->cronExecutor = $cronExecutor;
    }

    protected function configure(): void
    {
        $this->setName('neurocheckout:connector:cron-run')
            ->setDescription('Execute le cron NeuroCheckout pour un store ou tous les stores')
            ->addOption('store-id', null, InputOption::VALUE_OPTIONAL, 'Store ID cible')
            ->addOption('test', null, InputOption::VALUE_NONE, 'Execution en mode test')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Execution en mode force');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode('adminhtml');
        } catch (\Throwable $e) {
        }

        $storeIdOption = (int) $input->getOption('store-id');
        $isTest = (bool) $input->getOption('test');
        $isForce = (bool) $input->getOption('force');

        $storeIds = [];
        if ($storeIdOption > 0) {
            $storeIds[] = $storeIdOption;
        } else {
            foreach ($this->storeManager->getStores(true) as $store) {
                $resolvedStoreId = (int) $store->getId();
                if ($resolvedStoreId <= 0) {
                    continue;
                }
                $storeIds[] = $resolvedStoreId;
            }
        }

        $globalSuccess = true;
        foreach ($storeIds as $storeId) {
            $result = $this->cronExecutor->executeStore($storeId, $isTest, $isForce);
            $output->writeln(json_encode($result, JSON_UNESCAPED_UNICODE));
            if (empty($result['success'])) {
                $globalSuccess = false;
            }
        }

        return $globalSuccess ? Command::SUCCESS : Command::FAILURE;
    }
}
