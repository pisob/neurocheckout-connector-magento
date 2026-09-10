<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use NeuroCheckout\Connector\Model\Config;
use NeuroCheckout\Connector\Model\Event\TelemetryEventBuilder;
use NeuroCheckout\Connector\Model\Repository\TelemetryEventRepository;
use Psr\Log\LoggerInterface;

class QueueCheckoutTelemetryObserver implements ObserverInterface
{
    private TelemetryEventBuilder $eventBuilder;
    private TelemetryEventRepository $repository;
    private Config $config;
    private LoggerInterface $logger;

    public function __construct(
        TelemetryEventBuilder $eventBuilder,
        TelemetryEventRepository $repository,
        Config $config,
        LoggerInterface $logger
    ) {
        $this->eventBuilder = $eventBuilder;
        $this->repository = $repository;
        $this->config = $config;
        $this->logger = $logger;
    }

    public function execute(Observer $observer): void
    {
        try {
            $quote = $this->extractQuote($observer);
            if (!$quote || (int) $quote->getId() <= 0) {
                return;
            }

            $storeId = (int) $quote->getStoreId();
            if (!$this->config->isTelemetryEnabled($storeId) || !$this->config->isApiConfigurationReady($storeId)) {
                return;
            }

            $payload = $this->eventBuilder->buildShippingCostSnapshot($quote);
            if ($payload === null) {
                return;
            }

            $this->repository->enqueue($storeId, $payload);
        } catch (\Throwable $e) {
            $this->logger->warning('[NC] QueueCheckoutTelemetryObserver error: ' . $e->getMessage());
        }
    }

    private function extractQuote(Observer $observer): ?Quote
    {
        $quote = $observer->getEvent()->getData('quote');
        if ($quote instanceof Quote) {
            return $quote;
        }

        return null;
    }
}
