<?php
declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Application;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Lock\LockManagerInterface;
use NeuroCheckout\Connector\Model\Config;
use Psr\Log\LoggerInterface;

/** Traffic fallback, not a replacement for the no-traffic Magento scheduler. */
class TrafficCronRunner
{
    public function __construct(
        private Config $config,
        private CacheInterface $cache,
        private LockManagerInterface $locks,
        private CronExecutor $executor,
        private LoggerInterface $logger
    ) {
    }

    public function run(int $storeId): void
    {
        if ($storeId <= 0 || $this->config->getExecutionMode($storeId) !== 'cron_module'
            || !$this->config->isApiConfigurationReady($storeId)
            || !$this->config->isIaConfigurationReady($storeId)
            || !$this->config->isApiTestValidationCurrent($storeId)) {
            return;
        }

        $key = 'nc_traffic_cron_' . $storeId;
        if (!$this->locks->lock($key, 0)) {
            return;
        }
        try {
            $now = time();
            $interval = max(60, $this->config->getInt(Config::XML_PATH_CRON_INTERVAL_SECONDS, $storeId));
            $last = max((int) $this->cache->load($key), $this->config->getInt(Config::XML_PATH_LAST_RUN, $storeId));
            if ($last <= $now && $last > 0 && $now - $last < $interval) {
                return;
            }
            // Throttle attempts too, including failures. Fail closed if cache is unavailable.
            if (!$this->cache->save((string) $now, $key, [], $interval)) {
                return;
            }
            // At most one record per queue; keep the normal executor's auth,
            // queue retries and shared execution lock. Never force an API test.
            $result = $this->executor->executeStore($storeId, false, false, 1);
            if (empty($result['success'])) {
                $this->logger->warning('[NC] Traffic cron failed for store ' . $storeId . '; check connector Monitoring.');
            }
        } finally {
            $this->locks->unlock($key);
        }
    }
}
