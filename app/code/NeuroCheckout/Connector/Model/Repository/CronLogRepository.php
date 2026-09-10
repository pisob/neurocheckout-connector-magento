<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Repository;

use Magento\Framework\App\ResourceConnection;
use NeuroCheckout\Connector\Model\Config;
use NeuroCheckout\Connector\Model\Security\IpResolver;

class CronLogRepository
{
    private ResourceConnection $resource;
    private Config $config;
    private IpResolver $ipResolver;

    public function __construct(
        ResourceConnection $resource,
        Config $config,
        IpResolver $ipResolver
    )
    {
        $this->resource = $resource;
        $this->config = $config;
        $this->ipResolver = $ipResolver;
    }

    public function log(
        int $storeId,
        string $status,
        int $processedEvents,
        int $executionMs,
        ?string $errorMessage = null
    ): void {
        $table = $this->resource->getTableName('neurocheckout_cron_log');
        $connection = $this->resource->getConnection();
        $trustedProxyRules = $this->ipResolver->parseRules(
            $this->config->getString(Config::XML_PATH_TRUSTED_PROXY_IPS, $storeId)
        );
        $clientIp = $this->ipResolver->resolve($_SERVER, $trustedProxyRules);

        $connection->insert($table, [
            'store_id' => max(0, $storeId),
            'status' => substr($status, 0, 16),
            'processed_events' => max(0, $processedEvents),
            'execution_time_ms' => max(0, $executionMs),
            'ip_address' => substr($clientIp, 0, 64),
            'error_message' => $errorMessage ? substr($errorMessage, 0, 255) : null,
        ]);
    }

    public function countRecentErrors(int $storeId, int $minutes = 10): int
    {
        $table = $this->resource->getTableName('neurocheckout_cron_log');
        $connection = $this->resource->getConnection();

        $select = $connection->select()
            ->from($table, ['cnt' => 'COUNT(*)'])
            ->where('store_id = ?', max(0, $storeId))
            ->where('status = ?', 'error')
            ->where('executed_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE)', max(1, $minutes));

        return (int) $connection->fetchOne($select);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getLastLogs(int $storeId, int $limit = 5): array
    {
        $table = $this->resource->getTableName('neurocheckout_cron_log');
        $connection = $this->resource->getConnection();

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['executed_at', 'status', 'processed_events', 'execution_time_ms', 'ip_address', 'error_message'])
                ->where('store_id = ?', max(0, $storeId))
                ->order('executed_at DESC')
                ->limit(max(1, $limit))
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getLastLog(int $storeId): ?array
    {
        $rows = $this->getLastLogs($storeId, 1);
        return $rows[0] ?? null;
    }
}
