<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Repository;

use Magento\Framework\App\ResourceConnection;

class RecoveryAuditRepository
{
    private ResourceConnection $resource;

    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecentCoupons(int $storeId, int $limit = 5): array
    {
        $table = $this->resource->getTableName('neurocheckout_coupon');
        $rows = $this->resource->getConnection()->fetchAll(
            $this->resource->getConnection()->select()
                ->from($table, ['created_at', 'cart_id', 'customer_email', 'coupon_code', 'discount_percent', 'expires_at'])
                ->where('store_id = ?', max(0, $storeId))
                ->order('created_at DESC')
                ->limit(max(1, $limit))
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecentRecoveryTokens(int $storeId, int $limit = 5): array
    {
        $table = $this->resource->getTableName('neurocheckout_recovery_token');
        $rows = $this->resource->getConnection()->fetchAll(
            $this->resource->getConnection()->select()
                ->from($table, ['created_at', 'cart_id', 'customer_email', 'coupon_code', 'expires_at', 'used_at'])
                ->where('store_id = ?', max(0, $storeId))
                ->order('created_at DESC')
                ->limit(max(1, $limit))
        );

        if (!is_array($rows)) {
            return [];
        }

        foreach ($rows as &$row) {
            $row['status'] = $this->resolveRecoveryStatus($row);
        }
        unset($row);

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveRecoveryStatus(array $row): string
    {
        $usedAt = trim((string) ($row['used_at'] ?? ''));
        if ($usedAt !== '') {
            return 'used';
        }

        $expiresAt = trim((string) ($row['expires_at'] ?? ''));
        if ($expiresAt !== '' && (strtotime($expiresAt) ?: 0) < time()) {
            return 'expired';
        }

        return 'ready';
    }
}
