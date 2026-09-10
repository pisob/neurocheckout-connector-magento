<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Repository;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\AlreadyExistsException;

class RecoveryTokenRepository
{
    private const TOKEN_BYTES = 32;

    private ResourceConnection $resource;

    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    public function issue(
        int $storeId,
        string $cartId,
        string $customerEmail,
        string $couponCode,
        int $ttlSeconds,
        string $cartFingerprint = ''
    ): ?string {
        $normalizedCartId = trim($cartId);
        $normalizedEmail = strtolower(trim($customerEmail));
        $normalizedFingerprint = trim($cartFingerprint);
        if ($storeId <= 0 || $normalizedCartId === '' || $normalizedEmail === '' || $ttlSeconds <= 0) {
            return null;
        }

        $this->purgeExpired();

        $table = $this->resource->getTableName('neurocheckout_recovery_token');
        $connection = $this->resource->getConnection();
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $token = bin2hex(random_bytes(self::TOKEN_BYTES));
            $tokenHash = hash('sha256', $token);

            try {
                $connection->insert($table, [
                    'store_id' => $storeId,
                    'token_hash' => $tokenHash,
                    'cart_id' => $normalizedCartId,
                    'customer_email' => $normalizedEmail,
                    'coupon_code' => $couponCode !== '' ? $couponCode : null,
                    'cart_fingerprint' => $normalizedFingerprint !== '' ? $normalizedFingerprint : null,
                    'expires_at' => $expiresAt,
                    'used_at' => null,
                ]);

                return $token;
            } catch (AlreadyExistsException $e) {
                continue;
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }

    public function issueCustomerSession(
        int $storeId,
        int $customerId,
        string $customerEmail,
        string $targetUrl,
        int $ttlSeconds
    ): ?string {
        $normalizedEmail = strtolower(trim($customerEmail));
        $normalizedTarget = trim($targetUrl);
        if ($storeId <= 0 || $customerId <= 0 || $normalizedEmail === '' || $normalizedTarget === '' || $ttlSeconds <= 0) {
            return null;
        }

        $this->purgeExpired();
        $this->ensureCustomerSessionColumns();

        $table = $this->resource->getTableName('neurocheckout_recovery_token');
        $connection = $this->resource->getConnection();
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $token = bin2hex(random_bytes(self::TOKEN_BYTES));
            $tokenHash = hash('sha256', $token);

            try {
                $connection->insert($table, [
                    'store_id' => $storeId,
                    'token_hash' => $tokenHash,
                    'cart_id' => 'customer:' . $customerId,
                    'customer_email' => $normalizedEmail,
                    'coupon_code' => null,
                    'cart_fingerprint' => null,
                    'mode' => 'customer_session',
                    'customer_id' => $customerId,
                    'target_url' => $normalizedTarget,
                    'expires_at' => $expiresAt,
                    'used_at' => null,
                ]);

                return $token;
            } catch (AlreadyExistsException $e) {
                continue;
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolveUsable(string $token): ?array
    {
        $tokenHash = $this->hashToken($token);
        if ($tokenHash === '') {
            return null;
        }

        try {
            $table = $this->resource->getTableName('neurocheckout_recovery_token');
            $connection = $this->resource->getConnection();
            $this->ensureCustomerSessionColumns();
            $row = $connection->fetchRow(
                $connection->select()
                    ->from($table, ['store_id', 'cart_id', 'customer_email', 'coupon_code', 'cart_fingerprint', 'mode', 'customer_id', 'target_url', 'expires_at'])
                    ->where('token_hash = ?', $tokenHash)
                    ->where('used_at IS NULL')
                    ->order('id DESC')
                    ->limit(1)
            );
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_array($row)) {
            return null;
        }

        $expiresAt = trim((string) ($row['expires_at'] ?? ''));
        if ($expiresAt !== '' && strtotime($expiresAt) < time()) {
            return null;
        }

        return [
            'store_id' => (int) ($row['store_id'] ?? 0),
            'cart_id' => (string) ($row['cart_id'] ?? ''),
            'customer_email' => (string) ($row['customer_email'] ?? ''),
            'coupon_code' => (string) ($row['coupon_code'] ?? ''),
            'cart_fingerprint' => (string) ($row['cart_fingerprint'] ?? ''),
            'mode' => (string) ($row['mode'] ?? 'cart'),
            'customer_id' => (int) ($row['customer_id'] ?? 0),
            'target_url' => (string) ($row['target_url'] ?? ''),
            'expires_at' => $expiresAt,
        ];
    }

    public function consume(string $token): bool
    {
        $tokenHash = $this->hashToken($token);
        if ($tokenHash === '') {
            return false;
        }

        try {
            $table = $this->resource->getTableName('neurocheckout_recovery_token');
            $connection = $this->resource->getConnection();
            $updatedRows = $connection->update(
                $table,
                ['used_at' => gmdate('Y-m-d H:i:s')],
                [
                    'token_hash = ?' => $tokenHash,
                    'used_at IS NULL',
                    'expires_at >= ?' => gmdate('Y-m-d H:i:s'),
                ]
            );
        } catch (\Throwable $e) {
            return false;
        }

        return $updatedRows > 0;
    }

    public function purgeExpired(): void
    {
        try {
            $table = $this->resource->getTableName('neurocheckout_recovery_token');
            $connection = $this->resource->getConnection();
            $connection->delete($table, ['expires_at < ?' => gmdate('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
        }
    }

    private function ensureCustomerSessionColumns(): void
    {
        try {
            $table = $this->resource->getTableName('neurocheckout_recovery_token');
            $connection = $this->resource->getConnection();

            if (!$connection->tableColumnExists($table, 'mode')) {
                $connection->addColumn($table, 'mode', [
                    'type' => \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                    'length' => 32,
                    'nullable' => false,
                    'default' => 'cart',
                    'comment' => 'Recovery Mode',
                ]);
            }

            if (!$connection->tableColumnExists($table, 'customer_id')) {
                $connection->addColumn($table, 'customer_id', [
                    'type' => \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER,
                    'unsigned' => true,
                    'nullable' => true,
                    'comment' => 'Customer ID',
                ]);
            }

            if (!$connection->tableColumnExists($table, 'target_url')) {
                $connection->addColumn($table, 'target_url', [
                    'type' => \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                    'nullable' => true,
                    'comment' => 'Target URL',
                ]);
            }
        } catch (\Throwable $e) {
        }
    }

    private function hashToken(string $token): string
    {
        $normalized = trim($token);
        if ($normalized === '') {
            return '';
        }

        return hash('sha256', $normalized);
    }
}
