<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Application;

use NeuroCheckout\Connector\Model\Config;
use NeuroCheckout\Connector\Model\Http\SecureHttpClient;
use NeuroCheckout\Connector\Model\Repository\CronLogRepository;
use NeuroCheckout\Connector\Model\Repository\CustomerJourneyEventRepository;
use NeuroCheckout\Connector\Model\Repository\OrderEventRepository;
use NeuroCheckout\Connector\Model\Repository\TelemetryEventRepository;
use Psr\Log\LoggerInterface;

class CronExecutor
{
    /**
     * @var resource|null
     */
    private $activeLockHandle = null;

    private EventDispatcher $dispatcher;
    private OrderEventRepository $orderEventRepository;
    private TelemetryEventRepository $telemetryEventRepository;
    private CustomerJourneyEventRepository $customerJourneyEventRepository;
    private SecureHttpClient $httpClient;
    private CronLogRepository $cronLogRepository;
    private Config $config;
    private LoggerInterface $logger;

    public function __construct(
        EventDispatcher $dispatcher,
        OrderEventRepository $orderEventRepository,
        TelemetryEventRepository $telemetryEventRepository,
        CustomerJourneyEventRepository $customerJourneyEventRepository,
        SecureHttpClient $httpClient,
        CronLogRepository $cronLogRepository,
        Config $config,
        LoggerInterface $logger
    ) {
        $this->dispatcher = $dispatcher;
        $this->orderEventRepository = $orderEventRepository;
        $this->telemetryEventRepository = $telemetryEventRepository;
        $this->customerJourneyEventRepository = $customerJourneyEventRepository;
        $this->httpClient = $httpClient;
        $this->cronLogRepository = $cronLogRepository;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * @return array<string, mixed>
     */
    public function executeStore(int $storeId, bool $isCronTest = false, bool $isDebugForce = false, ?int $batchLimit = null): array
    {
        $started = microtime(true);

        if (!$this->acquireLock($storeId)) {
            return $this->result(false, 429, 'Cron deja en cours', $storeId, 0, 0, 0, 0, 0, 0, null, null, $started, $isCronTest, $isDebugForce);
        }

        try {
            $processed = $this->dispatcher->dispatch($storeId, $batchLimit === null ? 100 : max(1, min(100, $batchLimit)), $isCronTest);
            $dispatchStats = $this->dispatcher->getLastRunStats();
            $cartFailures = (int) ($dispatchStats['failed'] ?? 0);
            $cleared = (int) ($dispatchStats['cleared'] ?? 0);
            $dispatchReason = isset($dispatchStats['reason']) ? (string) $dispatchStats['reason'] : null;
            $fatal = !empty($dispatchStats['fatal']);

            $ordersProcessed = 0;
            $orderFailures = 0;
            $telemetryProcessed = 0;
            $telemetryFailures = 0;
            $customerJourneyProcessed = 0;
            $customerJourneyFailures = 0;

            if (!$isCronTest) {
                $this->orderEventRepository->releaseStuckProcessing($storeId, $this->staleProcessingMinutes());
                $pendingOrders = $this->orderEventRepository->lockBatchAtomic($storeId, $batchLimit === null ? 30 : max(1, min(30, $batchLimit)));
                foreach ($pendingOrders as $row) {
                    $rowId = (int) ($row['id'] ?? 0);
                    $attempts = (int) ($row['attempts'] ?? 0);
                    $payload = json_decode((string) ($row['payload'] ?? ''), true);

                    if (!is_array($payload)) {
                        $this->orderEventRepository->markFailed($storeId, $rowId, $attempts, 'invalid_payload');
                        $orderFailures++;
                        continue;
                    }

                    $result = $this->httpClient->sendOrderCompleted($payload);
                    if (!empty($result['success'])) {
                        $this->orderEventRepository->markSent($storeId, $rowId);
                        $ordersProcessed++;
                        continue;
                    }

                    $this->orderEventRepository->markFailed(
                        $storeId,
                        $rowId,
                        $attempts,
                        (string) ($result['error'] ?? 'send_failed')
                    );
                    $orderFailures++;
                }

                if ($this->config->isTelemetryEnabled($storeId)) {
                    $this->telemetryEventRepository->releaseStuckProcessing($storeId, 5);
                    $pendingTelemetry = $this->telemetryEventRepository->lockBatchAtomic($storeId, $batchLimit === null ? 50 : max(1, min(50, $batchLimit)));
                    foreach ($pendingTelemetry as $row) {
                        $rowId = (int) ($row['id'] ?? 0);
                        $attempts = (int) ($row['attempts'] ?? 0);
                        $payload = json_decode((string) ($row['payload'] ?? ''), true);

                        if (!is_array($payload)) {
                            $this->telemetryEventRepository->markFailed($storeId, $rowId, $attempts, 'invalid_payload');
                            $telemetryFailures++;
                            continue;
                        }

                        $result = $this->httpClient->sendTelemetryEvent($payload);
                        if (!empty($result['success'])) {
                            $this->telemetryEventRepository->markSent($storeId, $rowId);
                            $telemetryProcessed++;
                            continue;
                        }

                        $this->telemetryEventRepository->markFailed(
                            $storeId,
                            $rowId,
                            $attempts,
                            (string) ($result['error'] ?? 'telemetry_send_failed')
                        );
                        $telemetryFailures++;
                    }

                    $this->telemetryEventRepository->purgeTerminalBatch($storeId, 14, 300);
                }

                if ($this->config->isCustomerJourneyEnabled($storeId)) {
                    $this->customerJourneyEventRepository->releaseStuckProcessing($storeId, 5);
                    $pendingJourneyEvents = $this->customerJourneyEventRepository->lockBatchAtomic($storeId, $batchLimit === null ? 75 : max(1, min(75, $batchLimit)));
                    foreach ($pendingJourneyEvents as $row) {
                        $rowId = (int) ($row['id'] ?? 0);
                        $attempts = (int) ($row['attempts'] ?? 0);
                        $payload = json_decode((string) ($row['payload'] ?? ''), true);

                        if (!is_array($payload)) {
                            $this->customerJourneyEventRepository->markFailed($storeId, $rowId, $attempts, 'invalid_payload');
                            $customerJourneyFailures++;
                            continue;
                        }

                        $result = $this->httpClient->sendCustomerJourneyEvent($payload);
                        if (!empty($result['success'])) {
                            $this->customerJourneyEventRepository->markSent($storeId, $rowId);
                            $customerJourneyProcessed++;
                            continue;
                        }

                        $this->customerJourneyEventRepository->markFailed(
                            $storeId,
                            $rowId,
                            $attempts,
                            (string) ($result['error'] ?? 'customer_journey_send_failed')
                        );
                        $customerJourneyFailures++;
                    }

                    $this->customerJourneyEventRepository->purgeTerminalBatch($storeId, 14, 300);
                }
            }

            $elapsedMs = (int) round((microtime(true) - $started) * 1000);
            $failedEvents = $cartFailures + $orderFailures + $telemetryFailures + $customerJourneyFailures;
            $processedEvents = $processed + $ordersProcessed + $telemetryProcessed + $customerJourneyProcessed + $cleared;
            $health = $this->dispatcher->getHealthReport($storeId);
            $error = $this->resolveErrorMessage($fatal, $dispatchReason, $failedEvents);
            $success = $error === null;
            $statusCode = $this->resolveStatusCode($success, $dispatchReason, $failedEvents);
            $logStatus = $success ? 'success' : 'error';
            $logMessage = $error;

            $this->config->setValue(Config::XML_PATH_LAST_RUN, (string) time(), $storeId);
            $this->cronLogRepository->log($storeId, $logStatus, $processedEvents, $elapsedMs, $logMessage);

            return [
                'success' => $success,
                'status' => $statusCode,
                'error' => $error,
                'message' => $success ? $this->resolveSuccessMessage($processedEvents, $dispatchReason) : $error,
                'timestamp' => gmdate('Y-m-d H:i:s'),
                'store_id' => $storeId,
                'processed_events' => $processedEvents,
                'cart_events_processed' => $processed,
                'cleared_events' => $cleared,
                'orders_processed' => $ordersProcessed,
                'telemetry_processed' => $telemetryProcessed,
                'customer_journey_processed' => $customerJourneyProcessed,
                'failed_events' => $failedEvents,
                'reason' => $dispatchReason,
                'duration_ms' => $elapsedMs,
                'health' => $health,
                'mode' => $isCronTest ? 'test' : ($isDebugForce ? 'force' : 'normal'),
            ];
        } catch (\Throwable $e) {
            $elapsedMs = (int) round((microtime(true) - $started) * 1000);
            $message = $e->getMessage();
            $this->cronLogRepository->log($storeId, 'error', 0, $elapsedMs, $message);
            $this->logger->error('[NC] Cron executor fatal: ' . $message);

            return [
                'success' => false,
                'status' => 500,
                'error' => $message,
                'message' => $message,
                'timestamp' => gmdate('Y-m-d H:i:s'),
                'store_id' => $storeId,
                'processed_events' => 0,
                'cart_events_processed' => 0,
                'cleared_events' => 0,
                'orders_processed' => 0,
                'telemetry_processed' => 0,
                'customer_journey_processed' => 0,
                'failed_events' => 0,
                'reason' => 'fatal_error',
                'duration_ms' => $elapsedMs,
                'health' => $this->dispatcher->getHealthReport($storeId),
                'mode' => $isCronTest ? 'test' : ($isDebugForce ? 'force' : 'normal'),
            ];
        } finally {
            $this->releaseLock();
        }
    }

    private function staleProcessingMinutes(): int
    {
        return 3;
    }

    /**
     * @return array<string, mixed>
     */
    private function result(
        bool $success,
        int $status,
        string $message,
        int $storeId,
        int $processedEvents,
        int $cartEventsProcessed,
        int $clearedEvents,
        int $ordersProcessed,
        int $failedEvents,
        int $durationMs,
        ?string $reason,
        ?array $health,
        float $started,
        bool $isCronTest,
        bool $isDebugForce
    ): array {
        $elapsedMs = $durationMs > 0 ? $durationMs : (int) round((microtime(true) - $started) * 1000);

        return [
            'success' => $success,
            'status' => $status,
            'error' => $success ? null : $message,
            'message' => $message,
            'timestamp' => gmdate('Y-m-d H:i:s'),
            'store_id' => $storeId,
            'processed_events' => $processedEvents,
            'cart_events_processed' => $cartEventsProcessed,
            'cleared_events' => $clearedEvents,
            'orders_processed' => $ordersProcessed,
            'telemetry_processed' => 0,
            'customer_journey_processed' => 0,
            'failed_events' => $failedEvents,
            'reason' => $reason,
            'duration_ms' => $elapsedMs,
            'health' => $health ?? $this->dispatcher->getHealthReport($storeId),
            'mode' => $isCronTest ? 'test' : ($isDebugForce ? 'force' : 'normal'),
        ];
    }

    private function resolveErrorMessage(bool $fatal, ?string $dispatchReason, int $failedEvents): ?string
    {
        if ($fatal) {
            return 'Execution cron interrompue par une erreur fatale';
        }

        if ($failedEvents > 0) {
            return 'Execution terminee avec erreurs';
        }

        return match ($dispatchReason) {
            'missing_ia_configuration' => 'Configuration IA incomplete',
            'missing_api_configuration' => 'Configuration API incomplete',
            'circuit_breaker_open' => 'Circuit breaker ouvert',
            default => null,
        };
    }

    private function resolveSuccessMessage(int $processedEvents, ?string $dispatchReason): string
    {
        if ($processedEvents > 0) {
            return 'Execution terminee avec succes';
        }

        if ($dispatchReason === 'no_pending_events') {
            return 'Aucun evenement en attente';
        }

        return 'Execution terminee';
    }

    private function resolveStatusCode(bool $success, ?string $dispatchReason, int $failedEvents): int
    {
        if ($success) {
            return 200;
        }

        if ($dispatchReason === 'circuit_breaker_open') {
            return 503;
        }

        if (in_array($dispatchReason, ['missing_ia_configuration', 'missing_api_configuration'], true)) {
            return 422;
        }

        if ($failedEvents > 0) {
            return 500;
        }

        return 500;
    }

    private function acquireLock(int $storeId): bool
    {
        $lockFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'neurocheckout_connector_store_' . $storeId . '.lock';
        $handle = @fopen($lockFile, 'c');
        if ($handle === false) {
            return false;
        }

        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            @fclose($handle);
            return false;
        }

        $this->activeLockHandle = $handle;
        return true;
    }

    private function releaseLock(): void
    {
        if (!is_resource($this->activeLockHandle)) {
            return;
        }

        @flock($this->activeLockHandle, LOCK_UN);
        @fclose($this->activeLockHandle);
        $this->activeLockHandle = null;
    }
}
