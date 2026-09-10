<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Repository;

use Magento\Framework\App\ResourceConnection;
use Zend_Db_Expr;

class TelemetryEventRepository
{
    private const MAX_RETRIES = 8;
    private const MAX_PAYLOAD_BYTES = 65535;

    private ResourceConnection $resource;

    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function enqueue(int $storeId, array $payload): bool
    {
        $eventId = trim((string) ($payload['event_id'] ?? ''));
        $eventType = trim((string) ($payload['event_type'] ?? ''));
        if ($storeId <= 0 || $eventId === '' || $eventType === '') {
            return false;
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === '' || strlen($json) > self::MAX_PAYLOAD_BYTES) {
            return false;
        }

        $table = $this->resource->getTableName('neurocheckout_telemetry_event');
        $connection = $this->resource->getConnection();

        try {
            $existingStatus = $connection->fetchOne(
                $connection->select()
                    ->from($table, ['status'])
                    ->where('store_id = ?', max(0, $storeId))
                    ->where('event_id = ?', $eventId)
                    ->limit(1)
            );

            if ($existingStatus === 'sent') {
                return true;
            }

            $connection->insertOnDuplicate(
                $table,
                [
                    'store_id' => max(0, $storeId),
                    'event_id' => $eventId,
                    'event_type' => $eventType,
                    'cart_id' => $this->resolveCartId($payload),
                    'event_hash' => hash('sha256', $json),
                    'payload' => $json,
                    'status' => 'pending',
                    'attempts' => 0,
                    'next_retry_at' => new Zend_Db_Expr('UTC_TIMESTAMP()'),
                    'last_attempt_at' => null,
                    'last_error' => null,
                    'created_at' => new Zend_Db_Expr('UTC_TIMESTAMP()'),
                    'updated_at' => new Zend_Db_Expr('UTC_TIMESTAMP()'),
                ],
                ['event_hash', 'payload', 'status', 'attempts', 'next_retry_at', 'last_attempt_at', 'last_error', 'updated_at']
            );

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function lockBatchAtomic(int $storeId, int $limit = 50): array
    {
        $table = $this->resource->getTableName('neurocheckout_telemetry_event');
        $connection = $this->resource->getConnection();
        $limit = max(1, min(200, $limit));

        $connection->beginTransaction();

        try {
            $ids = $connection->fetchCol(
                $connection->select()
                    ->from($table, ['id'])
                    ->where('store_id = ?', max(0, $storeId))
                    ->where('status = ?', 'pending')
                    ->where('(next_retry_at IS NULL OR next_retry_at <= UTC_TIMESTAMP())')
                    ->order('created_at ASC')
                    ->limit($limit)
                    ->forUpdate(true)
            );

            if (!$ids) {
                $connection->commit();
                return [];
            }

            $connection->update(
                $table,
                [
                    'status' => 'processing',
                    'last_attempt_at' => new Zend_Db_Expr('UTC_TIMESTAMP()'),
                    'updated_at' => new Zend_Db_Expr('UTC_TIMESTAMP()'),
                ],
                [
                    'id IN (?)' => $ids,
                    'store_id = ?' => max(0, $storeId),
                    'status = ?' => 'pending',
                ]
            );

            $rows = $connection->fetchAll(
                $connection->select()
                    ->from($table)
                    ->where('id IN (?)', $ids)
                    ->where('store_id = ?', max(0, $storeId))
                    ->where('status = ?', 'processing')
                    ->order('created_at ASC')
            );

            $connection->commit();

            return is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            $connection->rollBack();
            return [];
        }
    }

    public function releaseStuckProcessing(int $storeId, int $minutes = 5): int
    {
        $table = $this->resource->getTableName('neurocheckout_telemetry_event');
        $connection = $this->resource->getConnection();

        return $connection->update(
            $table,
            [
                'status' => 'pending',
                'next_retry_at' => new Zend_Db_Expr('UTC_TIMESTAMP()'),
                'updated_at' => new Zend_Db_Expr('UTC_TIMESTAMP()'),
            ],
            [
                'store_id = ?' => max(0, $storeId),
                'status = ?' => 'processing',
                'last_attempt_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . max(1, $minutes) . ' MINUTE)',
            ]
        );
    }

    public function markSent(int $storeId, int $rowId): void
    {
        $this->updateProcessingRow($storeId, $rowId, [
            'status' => 'sent',
            'sent_at' => new Zend_Db_Expr('UTC_TIMESTAMP()'),
            'next_retry_at' => null,
            'last_error' => null,
            'last_attempt_at' => new Zend_Db_Expr('UTC_TIMESTAMP()'),
            'updated_at' => new Zend_Db_Expr('UTC_TIMESTAMP()'),
        ]);
    }

    public function markFailed(int $storeId, int $rowId, int $attempts, string $error): void
    {
        $nextAttempts = max(0, $attempts + 1);
        $shortError = substr(trim($error) !== '' ? trim($error) : 'telemetry_send_failed', 0, 255);

        if ($nextAttempts >= self::MAX_RETRIES) {
            $this->updateProcessingRow($storeId, $rowId, [
                'status' => 'dead',
                'attempts' => $nextAttempts,
                'next_retry_at' => null,
                'last_error' => $shortError,
                'last_attempt_at' => new Zend_Db_Expr('UTC_TIMESTAMP()'),
                'updated_at' => new Zend_Db_Expr('UTC_TIMESTAMP()'),
            ]);
            return;
        }

        $delaySeconds = min(900, max(30, (int) (30 * pow(2, min($nextAttempts - 1, 5)))));

        $this->updateProcessingRow($storeId, $rowId, [
            'status' => 'pending',
            'attempts' => $nextAttempts,
            'next_retry_at' => new Zend_Db_Expr(sprintf('DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND)', $delaySeconds)),
            'last_error' => $shortError,
            'last_attempt_at' => new Zend_Db_Expr('UTC_TIMESTAMP()'),
            'updated_at' => new Zend_Db_Expr('UTC_TIMESTAMP()'),
        ]);
    }

    public function purgeTerminalBatch(int $storeId, int $retentionDays = 14, int $batchSize = 300): int
    {
        $table = $this->resource->getTableName('neurocheckout_telemetry_event');
        $connection = $this->resource->getConnection();
        $retentionDays = max(1, min(90, $retentionDays));
        $batchSize = max(1, min(1000, $batchSize));

        $ids = $connection->fetchCol(
            $connection->select()
                ->from($table, ['id'])
                ->where('store_id = ?', max(0, $storeId))
                ->where('status IN (?)', ['sent', 'dead'])
                ->where('updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . $retentionDays . ' DAY)')
                ->order('updated_at ASC')
                ->limit($batchSize)
        );

        if (!$ids) {
            return 0;
        }

        return $connection->delete($table, ['id IN (?)' => $ids]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveCartId(array $payload): ?string
    {
        $cart = is_array($payload['cart'] ?? null) ? $payload['cart'] : [];
        $cartId = trim((string) ($cart['id'] ?? ''));
        if ($cartId === '') {
            $context = is_array($payload['context'] ?? null) ? $payload['context'] : [];
            $cartId = trim((string) ($context['cart_id'] ?? ''));
        }

        return $cartId !== '' ? substr($cartId, 0, 64) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function updateProcessingRow(int $storeId, int $rowId, array $data): void
    {
        $table = $this->resource->getTableName('neurocheckout_telemetry_event');
        $connection = $this->resource->getConnection();

        $connection->update(
            $table,
            $data,
            [
                'id = ?' => $rowId,
                'store_id = ?' => max(0, $storeId),
                'status = ?' => 'processing',
            ]
        );
    }
}
