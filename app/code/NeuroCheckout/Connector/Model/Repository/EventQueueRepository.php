<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Repository;

use Magento\Framework\App\ResourceConnection;
use Zend_Db_Expr;

class EventQueueRepository
{
    private ResourceConnection $resource;

    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    public function touchCartEventRow(int $storeId, string $cartId): void
    {
        if ($cartId === '') {
            return;
        }

        $table = $this->resource->getTableName('neurocheckout_event');
        $connection = $this->resource->getConnection();

        $connection->insertOnDuplicate(
            $table,
            [
                'store_id' => max(0, $storeId),
                'cart_id' => $cartId,
                'event_hash' => '',
                'payload' => '',
                'status' => 'pending',
                'attempts' => 0,
                'priority' => 0,
                'created_at' => new Zend_Db_Expr('UTC_TIMESTAMP()'),
                'last_attempt_at' => null,
                'next_retry_at' => null,
            ],
            ['event_hash', 'payload', 'status', 'attempts', 'priority', 'created_at', 'next_retry_at']
        );
    }

    public function upsertPayload(int $storeId, string $cartId, array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }

        $hash = hash('sha256', $json);
        $table = $this->resource->getTableName('neurocheckout_event');
        $connection = $this->resource->getConnection();

        $connection->insertOnDuplicate(
            $table,
            [
                'store_id' => max(0, $storeId),
                'cart_id' => $cartId,
                'event_hash' => $hash,
                'payload' => $json,
                'status' => 'pending',
                'attempts' => 0,
                'priority' => 0,
                'created_at' => new Zend_Db_Expr('UTC_TIMESTAMP()'),
                'last_attempt_at' => null,
                'next_retry_at' => null,
            ],
            ['event_hash', 'payload', 'status', 'attempts', 'priority', 'created_at', 'next_retry_at']
        );
    }

    public function updateProcessingPayload(int $storeId, int $eventId, array $payload): bool
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }

        $hash = hash('sha256', $json);
        $table = $this->resource->getTableName('neurocheckout_event');
        $connection = $this->resource->getConnection();

        $updated = $connection->update(
            $table,
            [
                'event_hash' => $hash,
                'payload' => $json,
            ],
            [
                'id = ?' => $eventId,
                'store_id = ?' => max(0, $storeId),
                'status = ?' => 'processing',
            ]
        );

        return $updated > 0;
    }

    public function hasTrackedCartEvent(int $storeId, string $cartId): bool
    {
        $table = $this->resource->getTableName('neurocheckout_event');
        $connection = $this->resource->getConnection();

        $value = (int) $connection->fetchOne(
            $connection->select()
                ->from($table, [new Zend_Db_Expr('1')])
                ->where('store_id = ?', max(0, $storeId))
                ->where('cart_id = ?', $cartId)
                ->limit(1)
        );

        return $value === 1;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function lockBatchAtomic(int $storeId, int $limit = 100): array
    {
        $table = $this->resource->getTableName('neurocheckout_event');
        $connection = $this->resource->getConnection();
        $limit = max(1, $limit);

        $connection->beginTransaction();

        try {
            $ids = $connection->fetchCol(
                $connection->select()
                    ->from($table, ['id'])
                    ->where('store_id = ?', max(0, $storeId))
                    ->where('status = ?', 'pending')
                    ->where('(next_retry_at IS NULL OR next_retry_at <= UTC_TIMESTAMP())')
                    ->order('priority DESC')
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
                    ->order('priority DESC')
                    ->order('created_at ASC')
            );

            $connection->commit();

            return is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            $connection->rollBack();
            return [];
        }
    }

    public function releaseStuckProcessing(int $storeId, int $minutes = 10): int
    {
        $table = $this->resource->getTableName('neurocheckout_event');
        $connection = $this->resource->getConnection();

        return $connection->update(
            $table,
            [
                'status' => 'pending',
                'next_retry_at' => null,
            ],
            [
                'store_id = ?' => max(0, $storeId),
                'status = ?' => 'processing',
                'last_attempt_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . max(1, $minutes) . ' MINUTE)',
            ]
        );
    }

    public function markAsSent(int $storeId, int $eventId): bool
    {
        return $this->updateStatus(
            $storeId,
            $eventId,
            [
                'status' => 'sent',
                'next_retry_at' => null,
                'last_attempt_at' => new Zend_Db_Expr('UTC_TIMESTAMP()'),
            ]
        );
    }

    public function markAsCleared(int $storeId, int $eventId): bool
    {
        return $this->updateStatus(
            $storeId,
            $eventId,
            [
                'status' => 'cleared',
                'next_retry_at' => null,
                'last_attempt_at' => new Zend_Db_Expr('UTC_TIMESTAMP()'),
            ]
        );
    }

    public function markAsDead(int $storeId, int $eventId): bool
    {
        return $this->updateStatus(
            $storeId,
            $eventId,
            [
                'status' => 'dead',
                'next_retry_at' => null,
                'last_attempt_at' => new Zend_Db_Expr('UTC_TIMESTAMP()'),
            ]
        );
    }

    public function markAsFailed(int $storeId, int $eventId): bool
    {
        $table = $this->resource->getTableName('neurocheckout_event');
        $connection = $this->resource->getConnection();

        $row = $connection->fetchRow(
            $connection->select()
                ->from($table, ['attempts'])
                ->where('id = ?', $eventId)
                ->where('store_id = ?', max(0, $storeId))
                ->limit(1)
        );

        if (!is_array($row)) {
            return false;
        }

        $attempts = ((int)($row['attempts'] ?? 0)) + 1;
        $delaySeconds = min(3600, (int)pow(2, $attempts) * 10);

        $updated = $connection->update(
            $table,
            [
                'attempts' => $attempts,
                'status' => 'pending',
                'last_attempt_at' => new Zend_Db_Expr('UTC_TIMESTAMP()'),
                'next_retry_at' => new Zend_Db_Expr(sprintf('DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND)', $delaySeconds)),
            ],
            [
                'id = ?' => $eventId,
                'store_id = ?' => max(0, $storeId),
                'status = ?' => 'processing',
            ]
        );

        return $updated > 0;
    }

    public function purgeSentBatch(int $storeId, int $retentionDays, int $batchSize = 500): int
    {
        $table = $this->resource->getTableName('neurocheckout_event');
        $connection = $this->resource->getConnection();

        $ids = $connection->fetchCol(
            $connection->select()
                ->from($table, ['id'])
                ->where('store_id = ?', max(0, $storeId))
                ->where('status IN (?)', ['sent', 'cleared', 'dead'])
                ->where('created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . max(1, $retentionDays) . ' DAY)')
                ->limit(max(1, $batchSize))
        );

        if (!$ids) {
            return 0;
        }

        return $connection->delete($table, ['id IN (?)' => $ids]);
    }

    private function updateStatus(int $storeId, int $eventId, array $data): bool
    {
        $table = $this->resource->getTableName('neurocheckout_event');
        $connection = $this->resource->getConnection();

        $updated = $connection->update(
            $table,
            $data,
            [
                'id = ?' => $eventId,
                'store_id = ?' => max(0, $storeId),
                'status = ?' => 'processing',
            ]
        );

        return $updated > 0;
    }
}
