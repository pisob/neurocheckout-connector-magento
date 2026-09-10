<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Repository;

use Magento\Framework\App\ResourceConnection;
use Zend_Db_Expr;

class PayloadAliasRepository
{
    private ResourceConnection $resource;

    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    /**
     * @return array<string, string>
     */
    public function getAllMappings(int $storeId): array
    {
        $table = $this->resource->getTableName('neurocheckout_payload_alias');
        $connection = $this->resource->getConnection();

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['original_key', 'alias_key'])
                ->where('store_id = ?', max(0, $storeId))
        );

        $result = [];
        foreach ($rows as $row) {
            $original = (string)($row['original_key'] ?? '');
            $alias = (string)($row['alias_key'] ?? '');
            if ($original === '' || $alias === '') {
                continue;
            }
            $result[$original] = $alias;
        }

        return $result;
    }

    public function getOrCreateAlias(int $storeId, string $originalKey): string
    {
        $normalizedKey = trim($originalKey);
        if ($normalizedKey === '') {
            throw new \InvalidArgumentException('originalKey is required');
        }

        $table = $this->resource->getTableName('neurocheckout_payload_alias');
        $connection = $this->resource->getConnection();

        $existing = (string) $connection->fetchOne(
            $connection->select()
                ->from($table, ['alias_key'])
                ->where('store_id = ?', max(0, $storeId))
                ->where('original_key = ?', $normalizedKey)
        );

        if ($existing !== '') {
            return $existing;
        }

        $count = (int) $connection->fetchOne(
            $connection->select()
                ->from($table, ['cnt' => 'COUNT(*)'])
                ->where('store_id = ?', max(0, $storeId))
        );

        $alias = 'a' . ($count + 1);

        $connection->insertOnDuplicate(
            $table,
            [
                'store_id' => max(0, $storeId),
                'original_key' => $normalizedKey,
                'alias_key' => $alias,
                'schema_version' => 1,
                'created_at' => new Zend_Db_Expr('UTC_TIMESTAMP()'),
            ],
            ['schema_version']
        );

        return (string) $connection->fetchOne(
            $connection->select()
                ->from($table, ['alias_key'])
                ->where('store_id = ?', max(0, $storeId))
                ->where('original_key = ?', $normalizedKey)
        );
    }

    public function getSchemaVersion(int $storeId): int
    {
        $table = $this->resource->getTableName('neurocheckout_payload_alias');
        $connection = $this->resource->getConnection();
        $value = (int) $connection->fetchOne(
            $connection->select()
                ->from($table, ['v' => 'MAX(schema_version)'])
                ->where('store_id = ?', max(0, $storeId))
        );

        return $value > 0 ? $value : 1;
    }
}
