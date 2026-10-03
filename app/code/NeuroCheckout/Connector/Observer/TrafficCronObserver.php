<?php
declare(strict_types=1);

namespace NeuroCheckout\Connector\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\StoreManagerInterface;
use NeuroCheckout\Connector\Model\Application\TrafficCronRunner;
use Psr\Log\LoggerInterface;

class TrafficCronObserver implements ObserverInterface
{
    private bool $registered = false;

    public function __construct(
        private StoreManagerInterface $stores,
        private TrafficCronRunner $runner,
        private LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        // Without FastCGI response completion, network work would hold up checkout.
        // Such installations must use the documented Magento server cron.
        if ($this->registered || PHP_SAPI === 'cli' || !function_exists('fastcgi_finish_request')) {
            return;
        }
        try {
            $storeId = (int) $this->stores->getStore()->getId();
        } catch (\Throwable $e) {
            return;
        }
        if ($storeId <= 0) {
            return;
        }
        $this->registered = true;
        register_shutdown_function(function () use ($storeId): void {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }
            try {
                if (!fastcgi_finish_request()) {
                    return;
                }
                // Free Magento/PHP's customer session before background network I/O.
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }
                $this->runner->run($storeId);
            } catch (\Throwable $e) {
                $this->logger->warning('[NC] Traffic cron unavailable; check Magento cron and connector Monitoring.');
            }
        });
    }
}
