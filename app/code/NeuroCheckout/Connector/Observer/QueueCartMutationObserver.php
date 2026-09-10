<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use NeuroCheckout\Connector\Model\Config;
use NeuroCheckout\Connector\Model\Event\CartEventBuilder;
use NeuroCheckout\Connector\Model\Event\CustomerJourneyEventBuilder;
use NeuroCheckout\Connector\Model\Repository\CustomerJourneyEventRepository;
use NeuroCheckout\Connector\Model\Repository\EventQueueRepository;
use Psr\Log\LoggerInterface;

class QueueCartMutationObserver implements ObserverInterface
{
    private EventQueueRepository $repository;
    private CartEventBuilder $eventBuilder;
    private CustomerJourneyEventBuilder $customerJourneyEventBuilder;
    private CustomerJourneyEventRepository $customerJourneyEventRepository;
    private Config $config;
    private LoggerInterface $logger;

    public function __construct(
        EventQueueRepository $repository,
        CartEventBuilder $eventBuilder,
        CustomerJourneyEventBuilder $customerJourneyEventBuilder,
        CustomerJourneyEventRepository $customerJourneyEventRepository,
        Config $config,
        LoggerInterface $logger
    ) {
        $this->repository = $repository;
        $this->eventBuilder = $eventBuilder;
        $this->customerJourneyEventBuilder = $customerJourneyEventBuilder;
        $this->customerJourneyEventRepository = $customerJourneyEventRepository;
        $this->config = $config;
        $this->logger = $logger;
    }

    public function execute(Observer $observer): void
    {
        try {
            $quote = $this->extractQuote($observer);
            if (!$quote || !(int)$quote->getId()) {
                return;
            }

            $storeId = (int)$quote->getStoreId();
            $this->queueCustomerJourneyCartSnapshot($quote, $this->resolveHookName($observer));

            if (!$this->config->isIaConfigurationReady($storeId)) {
                return;
            }

            $cartId = (string)$quote->getId();
            $this->repository->touchCartEventRow($storeId, $cartId);

            if ((int)$quote->getItemsCount() <= 0 && $this->repository->hasTrackedCartEvent($storeId, $cartId)) {
                $payload = $this->eventBuilder->buildCleared($quote, 'cart_empty');
                if ($payload !== null) {
                    $this->repository->upsertPayload($storeId, $cartId, $payload);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[NC] QueueCartMutationObserver error: ' . $e->getMessage());
        }
    }

    private function extractQuote(Observer $observer): ?Quote
    {
        $event = $observer->getEvent();

        $quote = $event->getData('quote');
        if ($quote instanceof Quote) {
            return $quote;
        }

        $cart = $event->getData('cart');
        if ($cart && method_exists($cart, 'getQuote')) {
            $maybeQuote = $cart->getQuote();
            if ($maybeQuote instanceof Quote) {
                return $maybeQuote;
            }
        }

        $item = $event->getData('quote_item') ?: $event->getData('item');
        if ($item && method_exists($item, 'getQuote')) {
            $maybeQuote = $item->getQuote();
            if ($maybeQuote instanceof Quote) {
                return $maybeQuote;
            }
        }

        return null;
    }

    private function queueCustomerJourneyCartSnapshot(Quote $quote, string $hookName): void
    {
        try {
            $storeId = (int) $quote->getStoreId();
            if (
                $storeId <= 0
                || !$this->config->isCustomerJourneyEnabled($storeId)
                || !$this->config->isApiConfigurationReady($storeId)
            ) {
                return;
            }

            $payload = $this->customerJourneyEventBuilder->buildCartSnapshot($quote, $hookName);
            if ($payload === null) {
                return;
            }

            $this->customerJourneyEventRepository->enqueue($storeId, $payload);
        } catch (\Throwable $e) {
            $this->logger->warning('[NC] Customer journey cart snapshot skipped: ' . $e->getMessage());
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

        return 'cart_mutation';
    }
}
