<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Repository;

use Magento\Framework\App\ResourceConnection;
use Zend_Db_Expr;

class CustomerJourneyEventRepository
{
    private const MAX_RETRIES = 8;
    private const MAX_PAYLOAD_BYTES = 98304;

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

        $table = $this->resource->getTableName('neurocheckout_customer_journey_event');
        $connection = $this->resource->getConnection();
        $meta = $this->resolveMeta($payload);

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
                    'event_id' => substr($eventId, 0, 120),
                    'event_type' => substr($eventType, 0, 140),
                    'visitor_id' => $meta['visitor_id'],
                    'session_id' => $meta['session_id'],
                    'cart_id' => $meta['cart_id'],
                    'customer_ref' => $meta['customer_ref'],
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
                [
                    'event_type',
                    'visitor_id',
                    'session_id',
                    'cart_id',
                    'customer_ref',
                    'event_hash',
                    'payload',
                    'status',
                    'attempts',
                    'next_retry_at',
                    'last_attempt_at',
                    'last_error',
                    'updated_at',
                ]
            );

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function lockBatchAtomic(int $storeId, int $limit = 75): array
    {
        $table = $this->resource->getTableName('neurocheckout_customer_journey_event');
        $connection = $this->resource->getConnection();
        $limit = max(1, min(250, $limit));

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
        $table = $this->resource->getTableName('neurocheckout_customer_journey_event');
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
        $shortError = substr(trim($error) !== '' ? trim($error) : 'customer_journey_send_failed', 0, 255);

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
        $table = $this->resource->getTableName('neurocheckout_customer_journey_event');
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
     * @return array{visitor_id: ?string, session_id: ?string, cart_id: ?string, customer_ref: ?string}
     */
    private function resolveMeta(array $payload): array
    {
        $journey = is_array($payload['journey'] ?? null) ? $payload['journey'] : [];
        $cart = is_array($payload['cart'] ?? null) ? $payload['cart'] : [];
        $customer = is_array($payload['customer'] ?? null) ? $payload['customer'] : [];

        $visitorId = $this->safeMeta($journey['visitor_id'] ?? null, 80);
        $sessionId = $this->safeMeta($journey['session_id'] ?? null, 80);
        $cartId = $this->safeMeta($cart['id'] ?? ($journey['cart_id'] ?? null), 64);
        $emailHash = $this->safeMeta($customer['email_hash'] ?? ($payload['email_hash'] ?? null), 80);
        $customerId = $this->safeMeta($customer['id'] ?? null, 80);

        $customerRef = null;
        if ($emailHash !== null) {
            $customerRef = 'email_hash:' . substr($emailHash, 0, 16);
        } elseif ($customerId !== null) {
            $customerRef = 'customer:' . $customerId;
        } elseif ($visitorId !== null) {
            $customerRef = 'visitor:' . $visitorId;
        }

        return [
            'visitor_id' => $visitorId,
            'session_id' => $sessionId,
            'cart_id' => $cartId,
            'customer_ref' => $this->safeMeta($customerRef, 120),
        ];
    }

    private function safeMeta($value, int $limit): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        return substr($text, 0, $limit);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function updateProcessingRow(int $storeId, int $rowId, array $data): void
    {
        $table = $this->resource->getTableName('neurocheckout_customer_journey_event');
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
