<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Repository;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\AlreadyExistsException;

class NonceRepository
{
    private ResourceConnection $resource;

    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    public function register(string $nonce, int $ttlSeconds, int $storeId): bool
    {
        $nonce = trim($nonce);
        if ($nonce === '') {
            return false;
        }

        $table = $this->resource->getTableName('neurocheckout_nonce');
        $connection = $this->resource->getConnection();

        try {
            $connection->insert($table, [
                'store_id' => max(0, $storeId),
                'nonce' => $nonce,
                'expires_at' => time() + max(1, $ttlSeconds),
            ]);

            return true;
        } catch (AlreadyExistsException $e) {
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function purgeExpired(): void
    {
        $table = $this->resource->getTableName('neurocheckout_nonce');
        $connection = $this->resource->getConnection();
        $connection->delete($table, ['expires_at < ?' => time()]);
    }
}
