<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use NeuroCheckout\Connector\Model\Config;
use NeuroCheckout\Connector\Model\Event\CustomerJourneyEventBuilder;
use NeuroCheckout\Connector\Model\Event\OrderEventBuilder;
use NeuroCheckout\Connector\Model\Http\SecureHttpClient;
use NeuroCheckout\Connector\Model\Repository\CustomerJourneyEventRepository;
use NeuroCheckout\Connector\Model\Repository\OrderEventRepository;
use Psr\Log\LoggerInterface;

class QueueOrderCompletedObserver implements ObserverInterface
{
    /** @var array<string, bool> */
    private array $processedOrders = [];
    /** @var array<string, bool> */
    private array $processedJourneyOrders = [];

    private OrderEventBuilder $orderEventBuilder;
    private CustomerJourneyEventBuilder $customerJourneyEventBuilder;
    private SecureHttpClient $httpClient;
    private OrderEventRepository $repository;
    private CustomerJourneyEventRepository $customerJourneyEventRepository;
    private Config $config;
    private LoggerInterface $logger;

    public function __construct(
        OrderEventBuilder $orderEventBuilder,
        CustomerJourneyEventBuilder $customerJourneyEventBuilder,
        SecureHttpClient $httpClient,
        OrderEventRepository $repository,
        CustomerJourneyEventRepository $customerJourneyEventRepository,
        Config $config,
        LoggerInterface $logger
    ) {
        $this->orderEventBuilder = $orderEventBuilder;
        $this->customerJourneyEventBuilder = $customerJourneyEventBuilder;
        $this->httpClient = $httpClient;
        $this->repository = $repository;
        $this->customerJourneyEventRepository = $customerJourneyEventRepository;
        $this->config = $config;
        $this->logger = $logger;
    }

    public function execute(Observer $observer): void
    {
        try {
            foreach ($this->extractOrders($observer) as $order) {
                $storeId = (int) $order->getStoreId();
                $this->queueCustomerJourneyOrderCompleted($order, $this->resolveHookName($observer));

                if (!$this->config->isIaConfigurationReady($storeId)) {
                    continue;
                }

                $orderId = (int) $order->getEntityId();
                if ($orderId <= 0) {
                    continue;
                }

                $processedKey = $storeId . '|' . $orderId;
                if (isset($this->processedOrders[$processedKey])) {
                    continue;
                }

                $payload = $this->orderEventBuilder->build($order);
                if ($payload === null) {
                    continue;
                }

                $this->processedOrders[$processedKey] = true;

                $result = $this->httpClient->sendOrderCompleted($payload);
                if (!empty($result['success'])) {
                    continue;
                }

                $this->repository->queue(
                    $storeId,
                    (string)($payload['order_id'] ?? ''),
                    (string)($payload['cart_id'] ?? ''),
                    $payload,
                    (string)($result['error'] ?? 'send_failed')
                );
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[NC] QueueOrderCompletedObserver error: ' . $e->getMessage());
        }
    }

    /**
     * @return array<int, Order>
     */
    private function extractOrders(Observer $observer): array
    {
        $event = $observer->getEvent();
        $orders = [];

        $singleOrder = $event->getData('order');
        if ($singleOrder instanceof Order) {
            $orders[] = $singleOrder;
        }

        $eventOrders = $event->getData('orders');
        if (is_array($eventOrders)) {
            foreach ($eventOrders as $candidate) {
                if ($candidate instanceof Order) {
                    $orders[] = $candidate;
                }
            }
        }

        return $orders;
    }

    private function queueCustomerJourneyOrderCompleted(Order $order, string $hookName): void
    {
        try {
            $storeId = (int) $order->getStoreId();
            $orderId = (int) $order->getEntityId();
            if (
                $storeId <= 0
                || $orderId <= 0
                || !$this->config->isCustomerJourneyEnabled($storeId)
                || !$this->config->isApiConfigurationReady($storeId)
            ) {
                return;
            }

            $processedKey = $storeId . '|' . $orderId;
            if (isset($this->processedJourneyOrders[$processedKey])) {
                return;
            }

            $payload = $this->customerJourneyEventBuilder->buildOrderCompleted($order, $hookName);
            if ($payload === null) {
                return;
            }

            $this->processedJourneyOrders[$processedKey] = true;
            $this->customerJourneyEventRepository->enqueue($storeId, $payload);
        } catch (\Throwable $e) {
            $this->logger->warning('[NC] Customer journey order snapshot skipped: ' . $e->getMessage());
        }
    }

    private function resolveHookName(Observer $observer): string
    {
        try {
            $event = $observer->getEvent();
            if ($event && method_exists($event, 'getName')) {
                $name = trim((string) $event->getName());
                if ($name !== '') {
                    return $name;
                }
            }
        } catch (\Throwable $e) {
        }

        return 'order_completed';
    }
}
