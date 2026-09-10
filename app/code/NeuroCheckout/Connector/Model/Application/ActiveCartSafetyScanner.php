<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Application;

use Magento\Framework\App\ResourceConnection;
use NeuroCheckout\Connector\Model\Repository\EventQueueRepository;
use Psr\Log\LoggerInterface;

class ActiveCartSafetyScanner
{
    private const LOOKBACK_HOURS = 24;
    private const DEFAULT_LIMIT = 50;

    private ResourceConnection $resource;
    private EventQueueRepository $repository;
    private LoggerInterface $logger;

    public function __construct(
        ResourceConnection $resource,
        EventQueueRepository $repository,
        LoggerInterface $logger
    ) {
        $this->resource = $resource;
        $this->repository = $repository;
        $this->logger = $logger;
    }

    public function queueRecentlyChangedActiveCarts(int $storeId, int $limit = self::DEFAULT_LIMIT): int
    {
        $storeId = max(0, $storeId);
        if ($storeId <= 0) {
            return 0;
        }

        try {
            $cartIds = $this->findRecentlyChangedCartIds($storeId, $limit);
            foreach ($cartIds as $cartId) {
                $this->repository->touchCartEventRow($storeId, $cartId);
            }

            if ($cartIds) {
                $this->logger->info(sprintf(
                    '[NC] Safety scanner queued %d active cart event(s) for store %d',
                    count($cartIds),
                    $storeId
                ));
            }

            return count($cartIds);
        } catch (\Throwable $e) {
            $this->logger->warning('[NC] Active cart safety scan failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * @return list<string>
     */
    private function findRecentlyChangedCartIds(int $storeId, int $limit): array
    {
        $connection = $this->resource->getConnection();
        $quoteTable = $this->resource->getTableName('quote');
        $eventTable = $this->resource->getTableName('neurocheckout_event');

        $select = $connection->select()
            ->from(['q' => $quoteTable], ['cart_id' => 'entity_id'])
            ->joinLeft(
                ['e' => $eventTable],
                'e.store_id = q.store_id AND e.cart_id = CAST(q.entity_id AS CHAR)',
                []
            )
            ->where('q.store_id = ?', $storeId)
            ->where('q.is_active = ?', 1)
            ->where('q.items_count > ?', 0)
            ->where('q.updated_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? HOUR)', self::LOOKBACK_HOURS)
            ->where(
                '(e.id IS NULL OR (e.status IN (?) AND q.updated_at >= e.created_at))',
                ['sent', 'cleared', 'dead']
            )
            ->order('q.updated_at ASC')
            ->limit(max(1, $limit));

        $rows = $connection->fetchCol($select);
        if (!is_array($rows)) {
            return [];
        }

        $cartIds = [];
        foreach ($rows as $row) {
            $cartId = trim((string) $row);
            if ($cartId !== '') {
                $cartIds[] = $cartId;
            }
        }

        return $cartIds;
    }
}
