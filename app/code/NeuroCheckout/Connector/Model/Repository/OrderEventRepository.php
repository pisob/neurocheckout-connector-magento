<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Repository;

use Magento\Framework\App\ResourceConnection;
use Zend_Db_Expr;

class OrderEventRepository
{
    private ResourceConnection $resource;

    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    public function queue(int $storeId, string $orderId, string $cartId, array $payload, string $lastError = ''): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }

        $table = $this->resource->getTableName('neurocheckout_order_event');
        $connection = $this->resource->getConnection();

        $connection->insertOnDuplicate(
            $table,
            [
                'store_id' => max(0, $storeId),
                'order_id' => $orderId,
                'cart_id' => $cartId,
                'payload' => $json,
                'status' => 'pending',
                'attempts' => 0,
                'next_retry_at' => null,
                'last_attempt_at' => null,
                'last_error' => $lastError !== '' ? substr($lastError, 0, 255) : null,
                'created_at' => new Zend_Db_Expr('UTC_TIMESTAMP()'),
                'updated_at' => new Zend_Db_Expr('UTC_TIMESTAMP()'),
            ],
            ['payload', 'status', 'attempts', 'next_retry_at', 'last_attempt_at', 'last_error', 'updated_at']
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchPending(int $storeId, int $limit = 30): array
    {
        $table = $this->resource->getTableName('neurocheckout_order_event');
        $connection = $this->resource->getConnection();

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table)
                ->where('store_id = ?', max(0, $storeId))
                ->where('status = ?', 'pending')
                ->where('(next_retry_at IS NULL OR next_retry_at <= UTC_TIMESTAMP())')
                ->order('created_at ASC')
                ->limit(max(1, $limit))
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function lockBatchAtomic(int $storeId, int $limit = 30): array
    {
        $table = $this->resource->getTableName('neurocheckout_order_event');
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

    public function releaseStuckProcessing(int $storeId, int $minutes = 10): int
    {
        $table = $this->resource->getTableName('neurocheckout_order_event');
        $connection = $this->resource->getConnection();

        return $connection->update(
            $table,
            [
                'status' => 'pending',
                'next_retry_at' => null,
                'last_error' => 'released_stale_processing',
                'updated_at' => new Zend_Db_Expr('UTC_TIMESTAMP()'),
            ],
            [
                'store_id = ?' => max(0, $storeId),
                'status = ?' => 'processing',
                'COALESCE(last_attempt_at, updated_at, created_at) < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . max(1, $minutes) . ' MINUTE)',
            ]
        );
    }

    public function markSent(int $storeId, int $rowId): void
    {
        $table = $this->resource->getTableName('neurocheckout_order_event');
        $connection = $this->resource->getConnection();

        $connection->update(
            $table,
            [
                'status' => 'sent',
                'sent_at' => new Zend_Db_Expr('UTC_TIMESTAMP()'),
                'updated_at' => new Zend_Db_Expr('UTC_TIMESTAMP()'),
                'last_attempt_at' => new Zend_Db_Expr('UTC_TIMESTAMP()'),
                'last_error' => null,
                'next_retry_at' => null,
            ],
            [
                'id = ?' => $rowId,
                'store_id = ?' => max(0, $storeId),
                'status = ?' => 'processing',
            ]
        );
    }

    public function markFailed(int $storeId, int $rowId, int $attempts, string $error): void
    {
        $table = $this->resource->getTableName('neurocheckout_order_event');
        $connection = $this->resource->getConnection();

        $nextAttempts = max(0, $attempts + 1);
        if ($nextAttempts >= 8) {
            $connection->update(
                $table,
                [
                    'status' => 'dead',
                    'attempts' => $nextAttempts,
                    'last_error' => substr($error, 0, 255),
                    'next_retry_at' => null,
                    'last_attempt_at' => new Zend_Db_Expr('UTC_TIMESTAMP()'),
                    'updated_at' => new Zend_Db_Expr('UTC_TIMESTAMP()'),
                ],
                [
                    'id = ?' => $rowId,
                    'store_id = ?' => max(0, $storeId),
                    'status = ?' => 'processing',
                ]
            );
            return;
        }

        $delaySeconds = min(3600, (int)pow(2, $nextAttempts) * 10);

        $connection->update(
            $table,
            [
                'status' => 'pending',
                'attempts' => $nextAttempts,
                'last_error' => substr($error, 0, 255),
                'next_retry_at' => new Zend_Db_Expr(sprintf('DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND)', $delaySeconds)),
                'last_attempt_at' => new Zend_Db_Expr('UTC_TIMESTAMP()'),
                'updated_at' => new Zend_Db_Expr('UTC_TIMESTAMP()'),
            ],
            [
                'id = ?' => $rowId,
                'store_id = ?' => max(0, $storeId),
                'status = ?' => 'processing',
            ]
        );
    }
}
