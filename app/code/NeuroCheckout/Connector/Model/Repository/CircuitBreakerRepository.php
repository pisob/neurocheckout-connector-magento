<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Repository;

use Magento\Framework\App\ResourceConnection;
use Zend_Db_Expr;

class CircuitBreakerRepository
{
    public const STATE_CLOSED = 'closed';
    public const STATE_OPEN = 'open';
    public const STATE_HALF_OPEN = 'half_open';

    private ResourceConnection $resource;

    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    public function getState(int $storeId): array
    {
        $table = $this->resource->getTableName('neurocheckout_circuit_breaker');
        $connection = $this->resource->getConnection();

        $row = $connection->fetchRow(
            $connection->select()
                ->from($table, ['state', 'failure_count', 'updated_at'])
                ->where('store_id = ?', max(0, $storeId))
        );

        if (is_array($row) && $row) {
            return [
                'state' => (string)($row['state'] ?? self::STATE_CLOSED),
                'failure_count' => (int)($row['failure_count'] ?? 0),
                'updated_at' => (string)($row['updated_at'] ?? gmdate('Y-m-d H:i:s')),
            ];
        }

        $this->setState($storeId, self::STATE_CLOSED, 0);

        return [
            'state' => self::STATE_CLOSED,
            'failure_count' => 0,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    public function setState(int $storeId, string $state, int $failureCount): void
    {
        $table = $this->resource->getTableName('neurocheckout_circuit_breaker');
        $connection = $this->resource->getConnection();

        $connection->insertOnDuplicate(
            $table,
            [
                'store_id' => max(0, $storeId),
                'state' => $state,
                'failure_count' => max(0, $failureCount),
                'updated_at' => new Zend_Db_Expr('UTC_TIMESTAMP()'),
            ],
            ['state', 'failure_count', 'updated_at']
        );
    }
}
