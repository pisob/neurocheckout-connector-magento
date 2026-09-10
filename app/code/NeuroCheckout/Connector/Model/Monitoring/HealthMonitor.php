<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Monitoring;

use Magento\Framework\App\ResourceConnection;
use NeuroCheckout\Connector\Model\Resilience\CircuitBreaker;

class HealthMonitor
{
    private ResourceConnection $resource;
    private CircuitBreaker $circuitBreaker;

    public function __construct(
        ResourceConnection $resource,
        CircuitBreaker $circuitBreaker
    ) {
        $this->resource = $resource;
        $this->circuitBreaker = $circuitBreaker;
    }

    /**
     * @return array<string, mixed>
     */
    public function getHealthReport(int $storeId): array
    {
        $connection = $this->resource->getConnection();
        $eventTable = $this->resource->getTableName('neurocheckout_event');
        $orderEventTable = $this->resource->getTableName('neurocheckout_order_event');
        $cronTable = $this->resource->getTableName('neurocheckout_cron_log');

        $pendingCart = (int) $connection->fetchOne(
            $connection->select()
                ->from($eventTable, ['cnt' => 'COUNT(*)'])
                ->where('store_id = ?', max(0, $storeId))
                ->where('status = ?', 'pending')
        );
        $pendingOrder = (int) $connection->fetchOne(
            $connection->select()
                ->from($orderEventTable, ['cnt' => 'COUNT(*)'])
                ->where('store_id = ?', max(0, $storeId))
                ->where('status = ?', 'pending')
        );
        $processingCart = (int) $connection->fetchOne(
            $connection->select()
                ->from($eventTable, ['cnt' => 'COUNT(*)'])
                ->where('store_id = ?', max(0, $storeId))
                ->where('status = ?', 'processing')
        );
        $processingOrder = (int) $connection->fetchOne(
            $connection->select()
                ->from($orderEventTable, ['cnt' => 'COUNT(*)'])
                ->where('store_id = ?', max(0, $storeId))
                ->where('status = ?', 'processing')
        );
        $pendingCount = $pendingCart + $pendingOrder;
        $processingCount = $processingCart + $processingOrder;
        $staleStats = $this->staleProcessingStats($storeId);
        $backlog = $pendingCount + $processingCount;

        $totalRuns = (int) $connection->fetchOne(
            $connection->select()
                ->from($cronTable, ['cnt' => 'COUNT(*)'])
                ->where('store_id = ?', max(0, $storeId))
                ->where('executed_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)')
        );

        $errorRuns = (int) $connection->fetchOne(
            $connection->select()
                ->from($cronTable, ['cnt' => 'COUNT(*)'])
                ->where('store_id = ?', max(0, $storeId))
                ->where('status = ?', 'error')
                ->where('executed_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)')
        );

        $avgLatency = (int) round((float) $connection->fetchOne(
            $connection->select()
                ->from($cronTable, ['avg_ms' => 'AVG(execution_time_ms)'])
                ->where('store_id = ?', max(0, $storeId))
                ->where('executed_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)')
        ));

        $errorRate = $totalRuns > 0 ? round(($errorRuns / $totalRuns) * 100, 2) : 0.0;
        $circuit = $this->circuitBreaker->getStateDetails($storeId);
        $circuitState = (string)($circuit['state'] ?? 'closed');
        $updatedAtTs = strtotime((string) ($circuit['updated_at'] ?? '')) ?: 0;
        $circuitOpenSeconds = $circuitState === 'open' && $updatedAtTs > 0
            ? max(0, time() - $updatedAtTs)
            : 0;

        $score = 100;
        if ($backlog > 50) {
            $score -= 20;
        }
        if ($backlog > 200) {
            $score -= 30;
        }
        if ($processingCount > 0) {
            $score -= 10;
        }
        if ((int) $staleStats['stale_processing_count'] > 0) {
            $score -= 45;
        }
        if ((int) $staleStats['oldest_processing_age_seconds'] > ($this->staleProcessingThresholdSeconds() * 2)) {
            $score -= 20;
        }
        if ($errorRate > 10) {
            $score -= 20;
        }
        if ($errorRate > 30) {
            $score -= 30;
        }
        if ($avgLatency > 10000) {
            $score -= 10;
        }
        if ($avgLatency > 30000) {
            $score -= 20;
        }
        if ($avgLatency > 120000) {
            $score -= 20;
        }
        if ($circuitState === 'open') {
            $score -= 40;
        }

        $score = max(0, $score);

        return [
            'score' => $score,
            'status' => $score >= 80 ? 'healthy' : ($score >= 50 ? 'degraded' : 'critical'),
            'backlog' => $backlog,
            'pending_count' => $pendingCount,
            'processing_count' => $processingCount,
            'stale_processing_count' => (int) $staleStats['stale_processing_count'],
            'oldest_processing_age_seconds' => (int) $staleStats['oldest_processing_age_seconds'],
            'stale_processing_threshold_seconds' => $this->staleProcessingThresholdSeconds(),
            'error_rate' => $errorRate,
            'avg_latency' => $avgLatency,
            'circuit_state' => $circuitState,
            'circuit_open_seconds' => $circuitOpenSeconds,
            'timestamp' => gmdate('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function staleProcessingStats(int $storeId): array
    {
        $connection = $this->resource->getConnection();
        $eventTable = $this->resource->getTableName('neurocheckout_event');
        $orderEventTable = $this->resource->getTableName('neurocheckout_order_event');
        $thresholdMinutes = (int) max(1, $this->staleProcessingThresholdSeconds() / 60);
        $oldestAge = 0;

        $cartStale = (int) $connection->fetchOne(
            $connection->select()
                ->from($eventTable, ['cnt' => 'COUNT(*)'])
                ->where('store_id = ?', max(0, $storeId))
                ->where('status = ?', 'processing')
                ->where('COALESCE(last_attempt_at, created_at) < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . $thresholdMinutes . ' MINUTE)')
        );
        $orderStale = (int) $connection->fetchOne(
            $connection->select()
                ->from($orderEventTable, ['cnt' => 'COUNT(*)'])
                ->where('store_id = ?', max(0, $storeId))
                ->where('status = ?', 'processing')
                ->where('COALESCE(last_attempt_at, updated_at, created_at) < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . $thresholdMinutes . ' MINUTE)')
        );

        $safeStoreId = (int) max(0, $storeId);
        $oldestAttempt = $connection->fetchOne(
            'SELECT MIN(attempted_at) FROM ('
            . ' SELECT COALESCE(last_attempt_at, created_at) AS attempted_at FROM ' . $eventTable . ' WHERE store_id = ' . $safeStoreId . " AND status = 'processing'"
            . ' UNION ALL '
            . ' SELECT COALESCE(last_attempt_at, updated_at, created_at) AS attempted_at FROM ' . $orderEventTable . ' WHERE store_id = ' . $safeStoreId . " AND status = 'processing'"
            . ') q'
        );
        $oldestTs = strtotime((string) $oldestAttempt) ?: 0;
        if ($oldestTs > 0) {
            $oldestAge = max(0, time() - $oldestTs);
        }

        return [
            'stale_processing_count' => $cartStale + $orderStale,
            'oldest_processing_age_seconds' => $oldestAge,
        ];
    }

    private function staleProcessingThresholdSeconds(): int
    {
        return 180;
    }
}
