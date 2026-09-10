<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use NeuroCheckout\Connector\Model\Config;
use NeuroCheckout\Connector\Model\Event\TelemetryEventBuilder;
use NeuroCheckout\Connector\Model\Repository\TelemetryEventRepository;
use Psr\Log\LoggerInterface;

class QueuePaymentTelemetryObserver implements ObserverInterface
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
            $order = $observer->getEvent()->getData('order');
            if (!$order instanceof Order) {
                return;
            }

            $storeId = (int) $order->getStoreId();
            if (!$this->config->isTelemetryEnabled($storeId) || !$this->config->isApiConfigurationReady($storeId)) {
                return;
            }

            $payload = $this->eventBuilder->buildPaymentFailed($order);
            if ($payload === null) {
                return;
            }

            $this->repository->enqueue($storeId, $payload);
        } catch (\Throwable $e) {
            $this->logger->warning('[NC] QueuePaymentTelemetryObserver error: ' . $e->getMessage());
        }
    }
}
