<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Cron;

use Magento\Store\Model\StoreManagerInterface;
use NeuroCheckout\Connector\Model\Application\CronExecutor;
use NeuroCheckout\Connector\Model\Config;
use Psr\Log\LoggerInterface;

class ProcessQueue
{
    private StoreManagerInterface $storeManager;
    private CronExecutor $cronExecutor;
    private Config $config;
    private LoggerInterface $logger;

    public function __construct(
        StoreManagerInterface $storeManager,
        CronExecutor $cronExecutor,
        Config $config,
        LoggerInterface $logger
    ) {
        $this->storeManager = $storeManager;
        $this->cronExecutor = $cronExecutor;
        $this->config = $config;
        $this->logger = $logger;
    }

    public function execute(): void
    {
        $stores = $this->storeManager->getStores(true);

        foreach ($stores as $store) {
            $storeId = (int) $store->getId();
            if ($storeId <= 0) {
                continue;
            }

            if ($this->config->getExecutionMode($storeId) !== 'cron_module') {
                continue;
            }

            try {
                $result = $this->cronExecutor->executeStore($storeId, false, false);
                if (empty($result['success'])) {
                    $this->logger->warning('[NC] Automatic cron returned status ' . (int) ($result['status'] ?? 500) . ' for store ' . $storeId);
                }
            } catch (\Throwable $e) {
                $this->logger->error('[NC] Cron process failed: ' . $e->getMessage());
            }
        }
    }
}
