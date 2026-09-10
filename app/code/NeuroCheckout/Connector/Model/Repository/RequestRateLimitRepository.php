<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Repository;

use Magento\Framework\App\ResourceConnection;

class RequestRateLimitRepository
{
    private const WINDOW_SECONDS = 300;
    private const MAX_FAILURES = 10;
    private const BLOCK_SECONDS = 600;

    private ResourceConnection $resource;

    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    public function getRetryAfterSeconds(int $storeId, string $endpoint, string $clientIp): int
    {
        $row = $this->getRow($storeId, $endpoint, $clientIp);
        if (!is_array($row)) {
            return 0;
        }

        $blockedUntil = trim((string) ($row['blocked_until'] ?? ''));
        if ($blockedUntil === '') {
            return 0;
        }

        $blockedUntilTs = strtotime($blockedUntil) ?: 0;
        return $blockedUntilTs > time() ? max(1, $blockedUntilTs - time()) : 0;
    }

    public function recordFailure(int $storeId, string $endpoint, string $clientIp, string $reason = ''): int
    {
        $table = $this->resource->getTableName('neurocheckout_security_rate_limit');
        $connection = $this->resource->getConnection();
        $nowTs = time();
        $now = gmdate('Y-m-d H:i:s', $nowTs);
        $windowResetThreshold = gmdate('Y-m-d H:i:s', $nowTs - self::WINDOW_SECONDS);
        $row = $this->getRow($storeId, $endpoint, $clientIp);

        $attemptCount = 1;
        $windowStartedAt = $now;
        $blockedUntil = null;

        if (is_array($row)) {
            $currentBlockedUntil = trim((string) ($row['blocked_until'] ?? ''));
            $currentBlockedUntilTs = $currentBlockedUntil !== '' ? (strtotime($currentBlockedUntil) ?: 0) : 0;
            if ($currentBlockedUntilTs > $nowTs) {
                return max(1, $currentBlockedUntilTs - $nowTs);
            }

            $storedWindowStartedAt = trim((string) ($row['window_started_at'] ?? ''));
            $windowStillOpen = $storedWindowStartedAt !== '' && $storedWindowStartedAt >= $windowResetThreshold;
            $attemptCount = $windowStillOpen ? ((int) ($row['attempt_count'] ?? 0) + 1) : 1;
            $windowStartedAt = $windowStillOpen && $storedWindowStartedAt !== '' ? $storedWindowStartedAt : $now;
        }

        if ($attemptCount > self::MAX_FAILURES) {
            $blockedUntil = gmdate('Y-m-d H:i:s', $nowTs + self::BLOCK_SECONDS);
        }

        $data = [
            'store_id' => max(0, $storeId),
            'endpoint' => substr($endpoint, 0, 32),
            'client_ip' => substr($clientIp, 0, 64),
            'attempt_count' => max(1, $attemptCount),
            'window_started_at' => $windowStartedAt,
            'blocked_until' => $blockedUntil,
            'last_error' => $reason !== '' ? substr($reason, 0, 255) : null,
        ];

        if (is_array($row) && isset($row['id'])) {
            $connection->update($table, $data, ['id = ?' => (int) $row['id']]);
        } else {
            $connection->insert($table, $data);
        }

        if ($blockedUntil === null) {
            return 0;
        }

        $blockedUntilTs = strtotime($blockedUntil) ?: 0;
        return $blockedUntilTs > $nowTs ? max(1, $blockedUntilTs - $nowTs) : 0;
    }

    public function clear(int $storeId, string $endpoint, string $clientIp): void
    {
        $table = $this->resource->getTableName('neurocheckout_security_rate_limit');
        $this->resource->getConnection()->delete($table, [
            'store_id = ?' => max(0, $storeId),
            'endpoint = ?' => substr($endpoint, 0, 32),
            'client_ip = ?' => substr($clientIp, 0, 64),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecentFailures(int $storeId, int $limit = 10): array
    {
        $table = $this->resource->getTableName('neurocheckout_security_rate_limit');
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['endpoint', 'client_ip', 'attempt_count', 'last_error', 'blocked_until', 'updated_at'])
                ->where('store_id = ?', max(0, $storeId))
                ->order('updated_at DESC')
                ->limit(max(1, $limit))
        );

        if (!is_array($rows)) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $blockedUntil = trim((string) ($row['blocked_until'] ?? ''));
            $isBlocked = $blockedUntil !== '' && (strtotime($blockedUntil) ?: 0) > time();
            $result[] = [
                'updated_at' => (string) ($row['updated_at'] ?? ''),
                'endpoint' => (string) ($row['endpoint'] ?? ''),
                'client_ip' => (string) ($row['client_ip'] ?? ''),
                'attempt_count' => (int) ($row['attempt_count'] ?? 0),
                'last_error' => (string) ($row['last_error'] ?? ''),
                'status' => $isBlocked ? 'blocked' : 'watch',
                'blocked_until' => $blockedUntil,
            ];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getRow(int $storeId, string $endpoint, string $clientIp): ?array
    {
        $table = $this->resource->getTableName('neurocheckout_security_rate_limit');
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($table, ['id', 'attempt_count', 'window_started_at', 'blocked_until'])
                ->where('store_id = ?', max(0, $storeId))
                ->where('endpoint = ?', substr($endpoint, 0, 32))
                ->where('client_ip = ?', substr($clientIp, 0, 64))
                ->limit(1)
        );

        return is_array($row) ? $row : null;
    }
}
