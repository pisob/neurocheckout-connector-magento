<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Application;

use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteFactory;
use NeuroCheckout\Connector\Model\Config;
use NeuroCheckout\Connector\Model\Event\CartEventBuilder;
use NeuroCheckout\Connector\Model\Http\SecureHttpClient;
use NeuroCheckout\Connector\Model\Monitoring\HealthMonitor;
use NeuroCheckout\Connector\Model\Repository\EventQueueRepository;
use NeuroCheckout\Connector\Model\Resilience\CircuitBreaker;
use Psr\Log\LoggerInterface;

class EventDispatcher
{
    private const MAX_RETRIES = 8;

    private EventQueueRepository $repository;
    private SecureHttpClient $httpClient;
    private CircuitBreaker $circuitBreaker;
    private HealthMonitor $monitor;
    private ActiveCartSafetyScanner $activeCartSafetyScanner;
    private CartRepositoryInterface $cartRepository;
    private QuoteFactory $quoteFactory;
    private CartEventBuilder $cartEventBuilder;
    private Config $config;
    private LoggerInterface $logger;

    /**
     * @var array<string, mixed>
     */
    private array $lastRunStats = [
        'processed' => 0,
        'failed' => 0,
        'cleared' => 0,
        'fatal' => false,
        'reason' => null,
    ];

    public function __construct(
        EventQueueRepository $repository,
        SecureHttpClient $httpClient,
        CircuitBreaker $circuitBreaker,
        HealthMonitor $monitor,
        ActiveCartSafetyScanner $activeCartSafetyScanner,
        CartRepositoryInterface $cartRepository,
        QuoteFactory $quoteFactory,
        CartEventBuilder $cartEventBuilder,
        Config $config,
        LoggerInterface $logger
    ) {
        $this->repository = $repository;
        $this->httpClient = $httpClient;
        $this->circuitBreaker = $circuitBreaker;
        $this->monitor = $monitor;
        $this->activeCartSafetyScanner = $activeCartSafetyScanner;
        $this->cartRepository = $cartRepository;
        $this->quoteFactory = $quoteFactory;
        $this->cartEventBuilder = $cartEventBuilder;
        $this->config = $config;
        $this->logger = $logger;
    }

    public function dispatch(int $storeId, int $limit = 100, bool $isCronTest = false): int
    {
        $processed = 0;
        $failed = 0;
        $cleared = 0;

        $this->lastRunStats = [
            'processed' => 0,
            'failed' => 0,
            'cleared' => 0,
            'fatal' => false,
            'reason' => null,
        ];

        if (!$this->config->isIaConfigurationReady($storeId)) {
            $this->lastRunStats['reason'] = 'missing_ia_configuration';
            return 0;
        }

        $endpoint = $this->config->getString(Config::XML_PATH_API_ENDPOINT, $storeId);
        $apiKey = $this->config->getNormalizedApiKey($storeId);
        if ($endpoint === '' || $apiKey === '') {
            $this->lastRunStats['reason'] = 'missing_api_configuration';
            return 0;
        }

        if (!$this->circuitBreaker->isAvailable($storeId)) {
            $this->lastRunStats['reason'] = 'circuit_breaker_open';
            return 0;
        }

        try {
            $this->repository->releaseStuckProcessing($storeId, 3);
            $this->activeCartSafetyScanner->queueRecentlyChangedActiveCarts($storeId);
            $locked = $this->repository->lockBatchAtomic($storeId, $limit);
            if (!$locked) {
                $this->lastRunStats['reason'] = 'no_pending_events';
                return 0;
            }

            foreach ($locked as $event) {
                $eventId = (int)($event['id'] ?? 0);
                $cartId = (string)($event['cart_id'] ?? '');
                $attempts = (int)($event['attempts'] ?? 0);
                $payloadJson = (string)($event['payload'] ?? '');
                $payload = $payloadJson !== '' ? json_decode($payloadJson, true) : null;
                if (!is_array($payload)) {
                    $payload = null;
                }

                try {
                    if ($payload === null) {
                        $quote = $this->loadQuoteById($cartId);
                        if ($quote === null || (int)$quote->getId() <= 0) {
                            $this->repository->markAsCleared($storeId, $eventId);
                            $cleared++;
                            continue;
                        }

                        $payload = $this->cartEventBuilder->buildUpdated($quote);
                        if ($payload === null) {
                            $payload = $this->cartEventBuilder->buildCleared($quote, 'cart_empty');
                        }

                        if ($payload === null) {
                            $this->repository->markAsCleared($storeId, $eventId);
                            $cleared++;
                            continue;
                        }

                        if (!$this->repository->updateProcessingPayload($storeId, $eventId, $payload)) {
                            $this->repository->markAsFailed($storeId, $eventId);
                            $failed++;
                            continue;
                        }
                    }

                    $result = $this->httpClient->send($payload, ['is_cron_test' => $isCronTest]);
                    if (!empty($result['success'])) {
                        if (($payload['event_type'] ?? '') === 'cart.cleared') {
                            $this->repository->markAsCleared($storeId, $eventId);
                            $cleared++;
                        } else {
                            $this->repository->markAsSent($storeId, $eventId);
                            $processed++;
                        }
                        $this->circuitBreaker->recordSuccess($storeId);
                    } else {
                        if ($attempts >= self::MAX_RETRIES) {
                            $this->repository->markAsDead($storeId, $eventId);
                        } else {
                            $this->repository->markAsFailed($storeId, $eventId);
                        }
                        $failed++;
                        $this->circuitBreaker->recordFailure($storeId);
                    }
                } catch (\Throwable $e) {
                    if ($attempts >= self::MAX_RETRIES) {
                        $this->repository->markAsDead($storeId, $eventId);
                    } else {
                        $this->repository->markAsFailed($storeId, $eventId);
                    }
                    $failed++;
                    $this->circuitBreaker->recordFailure($storeId);
                    $this->logger->error('[NC] Dispatch event error: ' . $e->getMessage());
                }
            }

            $retentionDays = max(1, $this->config->getInt(Config::XML_PATH_EVENT_RETENTION_DAYS, $storeId));
            $purgeBatch = max(1, $this->config->getInt(Config::XML_PATH_PURGE_BATCH_SIZE, $storeId));
            $this->repository->purgeSentBatch($storeId, $retentionDays, $purgeBatch);

            $this->lastRunStats = [
                'processed' => $processed,
                'failed' => $failed,
                'cleared' => $cleared,
                'fatal' => false,
                'reason' => null,
            ];

            return $processed;
        } catch (\Throwable $e) {
            $this->lastRunStats['fatal'] = true;
            $this->lastRunStats['reason'] = $e->getMessage();
            $this->logger->error('[NC] Dispatch fatal: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getLastRunStats(): array
    {
        return $this->lastRunStats;
    }

    /**
     * @return array<string, mixed>
     */
    public function getHealthReport(int $storeId): array
    {
        return $this->monitor->getHealthReport($storeId);
    }

    private function loadQuoteById(string $cartId): ?\Magento\Quote\Model\Quote
    {
        $id = (int) $cartId;
        if ($id <= 0) {
            return null;
        }

        try {
            return $this->cartRepository->get($id);
        } catch (\Throwable $e) {
            try {
                $quote = $this->quoteFactory->create()->load($id);
                return $quote->getId() ? $quote : null;
            } catch (\Throwable $inner) {
                return null;
            }
        }
    }
}
