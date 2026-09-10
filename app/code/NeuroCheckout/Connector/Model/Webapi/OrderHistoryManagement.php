<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Webapi;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Webapi\Exception;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\StoreManagerInterface;
use NeuroCheckout\Connector\Api\OrderHistoryManagementInterface;
use NeuroCheckout\Connector\Model\Config;
use NeuroCheckout\Connector\Model\Event\OrderEventBuilder;
use NeuroCheckout\Connector\Model\Security\RequestSecurityValidator;
use Psr\Log\LoggerInterface;

class OrderHistoryManagement extends AbstractEndpoint implements OrderHistoryManagementInterface
{
    private const DEFAULT_LIMIT = 100;
    private const MAX_LIMIT = 250;
    private const DEFAULT_LOOKBACK_DAYS = 180;
    private const MAX_REQUEST_BODY_BYTES = 2097152;
    private const MAX_REQUEST_DECOMPRESSED_BYTES = 12582912;
    private const VALIDATED_ORDER_STATUSES = ['processing', 'complete'];

    private RequestSecurityValidator $securityValidator;
    private Config $config;
    private ResourceConnection $resource;
    private OrderRepositoryInterface $orderRepository;
    private OrderEventBuilder $orderEventBuilder;
    private LoggerInterface $logger;

    public function __construct(
        Request $request,
        StoreManagerInterface $storeManager,
        RequestSecurityValidator $securityValidator,
        Config $config,
        ResourceConnection $resource,
        OrderRepositoryInterface $orderRepository,
        OrderEventBuilder $orderEventBuilder,
        LoggerInterface $logger
    ) {
        parent::__construct($request, $storeManager);
        $this->securityValidator = $securityValidator;
        $this->config = $config;
        $this->resource = $resource;
        $this->orderRepository = $orderRepository;
        $this->orderEventBuilder = $orderEventBuilder;
        $this->logger = $logger;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function execute(array $payload = []): array
    {
        try {
            try {
                $payload = $this->normalizeHistoryPayload($payload);
                $window = $this->resolveWindow($payload);
                $cursor = $this->decodeCursor((string)($payload['cursor'] ?? ''));
            } catch (\RuntimeException $e) {
                return $this->response(false, $this->decodeErrorStatus($e->getMessage()), $e->getMessage());
            }

            $storeId = $this->resolveHistoryStoreId($payload);
            $security = $this->securityValidator->validate($this->getRawBody(), $storeId, 'orderhistory', false);
            if (empty($security['success'])) {
                return $this->response(false, (int)($security['status'] ?? 403), (string)($security['error'] ?? 'forbidden'));
            }

            if (!$this->config->isApiTestValidationCurrent($storeId)) {
                return $this->response(false, 409, 'Connector connection not validated');
            }

            $limit = $this->resolveLimit($payload);
            $mode = $this->resolveMode($payload);
            $rows = $this->fetchOrderRows($storeId, $window['since_sql'], $window['until_sql'], $cursor, $limit + 1);
            $hasMore = count($rows) > $limit;
            $pageRows = array_slice($rows, 0, $limit);

            $orders = [];
            $lastCursorRow = null;
            foreach ($pageRows as $row) {
                $lastCursorRow = $row;
                $orderId = (int)($row['entity_id'] ?? 0);
                if ($orderId <= 0) {
                    continue;
                }

                $order = $this->loadOrder($orderId);
                if (!$order) {
                    continue;
                }

                $historyOrder = $this->buildHistoryOrder($order, $mode);
                if ($historyOrder === null) {
                    continue;
                }

                $orders[] = $historyOrder;
            }

            $nextCursor = null;
            if ($hasMore && is_array($lastCursorRow)) {
                $nextCursor = $this->encodeCursor(
                    (string)($lastCursorRow['sync_at'] ?? $lastCursorRow['created_at'] ?? ''),
                    (int)($lastCursorRow['entity_id'] ?? 0)
                );
            }

            return $this->response(true, 200, null, [
                'orders' => $orders,
                'count' => count($orders),
                'scanned' => count($pageRows),
                'limit' => $limit,
                'mode' => $mode,
                'has_more' => $hasMore,
                'next_cursor' => $nextCursor,
                'store_id' => (string)$storeId,
                'window' => [
                    'since' => $window['since_iso'],
                    'until' => $window['until_iso'],
                ],
            ]);
        } catch (Exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('[NC] Order history endpoint error: ' . $e->getMessage());
            return $this->response(false, 500, 'Internal error');
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeHistoryPayload(array $payload): array
    {
        $rawBody = $this->getRawBody();
        if ($rawBody === '') {
            return $payload;
        }

        $body = $this->decodeRequestBody($rawBody);
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('invalid_json_payload');
        }

        return $decoded;
    }

    private function decodeRequestBody(string $rawBody): string
    {
        $contentEncoding = strtolower(trim((string)$this->request->getHeader('Content-Encoding')));
        if (strpos($contentEncoding, 'gzip') === false) {
            if (strlen($rawBody) > self::MAX_REQUEST_DECOMPRESSED_BYTES) {
                throw new \RuntimeException('request_too_large');
            }
            return $rawBody;
        }

        if (strlen($rawBody) > self::MAX_REQUEST_BODY_BYTES) {
            throw new \RuntimeException('gzip_request_too_large');
        }

        $decoded = @gzdecode($rawBody, self::MAX_REQUEST_DECOMPRESSED_BYTES + 1);
        if (!is_string($decoded)) {
            throw new \RuntimeException('invalid_gzip');
        }

        if (strlen($decoded) > self::MAX_REQUEST_DECOMPRESSED_BYTES) {
            throw new \RuntimeException('gzip_request_too_large');
        }

        return $decoded;
    }

    private function decodeErrorStatus(string $reason): int
    {
        if (strpos($reason, 'too_large') !== false) {
            return 413;
        }
        if ($reason === 'invalid_gzip') {
            return 422;
        }
        if ($reason === 'invalid_cursor' || $reason === 'invalid_datetime' || $reason === 'invalid_date_window') {
            return 422;
        }
        return 422;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveHistoryStoreId(array $payload): int
    {
        foreach ($this->shopReferenceCandidates($payload) as $reference) {
            if (ctype_digit($reference)) {
                return (int)$reference;
            }

            $storeId = $this->findStoreIdByShopExternalId($reference);
            if ($storeId > 0) {
                return $storeId;
            }
        }

        return $this->resolveStoreId($payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private function shopReferenceCandidates(array $payload): array
    {
        $candidates = [
            $payload['store_id'] ?? null,
            $payload['shop_id'] ?? null,
            $payload['shop_uuid'] ?? null,
        ];

        $source = $payload['source'] ?? null;
        if (is_array($source)) {
            $candidates[] = $source['store_id'] ?? null;
            $candidates[] = $source['shop_id'] ?? null;
            $candidates[] = $source['shop_uuid'] ?? null;
            $candidates[] = $source['shop_slug'] ?? null;
        }

        $references = [];
        foreach ($candidates as $candidate) {
            $value = trim((string)($candidate ?? ''));
            if ($value !== '' && !in_array($value, $references, true)) {
                $references[] = $value;
            }
        }

        return $references;
    }

    private function findStoreIdByShopExternalId(string $shopExternalId): int
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('core_config_data');
        $row = $connection->fetchRow(
            $connection->select()
                ->from($table, ['scope', 'scope_id'])
                ->where('path = ?', Config::XML_PATH_SHOP_EXTERNAL_ID)
                ->where('value = ?', $shopExternalId)
                ->where('scope = ?', 'stores')
                ->order('scope_id ASC')
                ->limit(1)
        );

        if (!is_array($row)) {
            return 0;
        }

        return max(0, (int)($row['scope_id'] ?? 0));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{since_sql: string, until_sql: string, since_iso: string, until_iso: string}
     */
    private function resolveWindow(array $payload): array
    {
        $until = $this->parseDateTime($payload['until'] ?? null);
        if ($until === null) {
            $until = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }

        $since = $this->parseDateTime($payload['since'] ?? null);
        if ($since === null) {
            $since = $until->modify('-' . self::DEFAULT_LOOKBACK_DAYS . ' days');
        }

        if ($since > $until) {
            throw new \RuntimeException('invalid_date_window');
        }

        return [
            'since_sql' => $since->format('Y-m-d H:i:s'),
            'until_sql' => $until->format('Y-m-d H:i:s'),
            'since_iso' => $since->format('c'),
            'until_iso' => $until->format('c'),
        ];
    }

    private function parseDateTime($value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string)$value);
        if ($text === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($text))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            throw new \RuntimeException('invalid_datetime');
        }
    }

    private function resolveLimit(array $payload): int
    {
        $limit = (int)($payload['limit'] ?? self::DEFAULT_LIMIT);
        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }

        return min(self::MAX_LIMIT, max(1, $limit));
    }

    private function resolveMode(array $payload): string
    {
        $mode = strtolower(trim((string)($payload['mode'] ?? 'slim')));
        return in_array($mode, ['slim', 'full'], true) ? $mode : 'slim';
    }

    /**
     * @return array{created_at: string, order_id: int}|null
     */
    private function decodeCursor(string $cursor): ?array
    {
        $cursor = trim($cursor);
        if ($cursor === '') {
            return null;
        }

        $normalized = strtr($cursor, '-_', '+/');
        $padding = strlen($normalized) % 4;
        if ($padding > 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($normalized, true);
        if (!is_string($decoded) || $decoded === '') {
            throw new \RuntimeException('invalid_cursor');
        }

        $payload = json_decode($decoded, true);
        if (!is_array($payload)) {
            throw new \RuntimeException('invalid_cursor');
        }

        $createdAt = $this->parseDateTime($payload['created_at'] ?? null);
        $orderId = (int)($payload['order_id'] ?? 0);
        if ($createdAt === null || $orderId <= 0) {
            throw new \RuntimeException('invalid_cursor');
        }

        return [
            'created_at' => $createdAt->format('Y-m-d H:i:s'),
            'order_id' => $orderId,
        ];
    }

    private function encodeCursor(string $createdAt, int $orderId): ?string
    {
        $createdAt = trim($createdAt);
        if ($createdAt === '' || $orderId <= 0) {
            return null;
        }

        $payload = json_encode(
            [
                'created_at' => $createdAt,
                'order_id' => $orderId,
            ],
            JSON_UNESCAPED_SLASHES
        );

        return is_string($payload) ? rtrim(strtr(base64_encode($payload), '+/', '-_'), '=') : null;
    }

    /**
     * @param array{created_at: string, order_id: int}|null $cursor
     * @return array<int, array<string, mixed>>
     */
    private function fetchOrderRows(
        int $storeId,
        string $sinceSql,
        string $untilSql,
        ?array $cursor,
        int $limit
    ): array {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('sales_order');
        $syncExpression = 'GREATEST(o.created_at, COALESCE(o.updated_at, o.created_at))';
        $select = $connection->select()
            ->from(
                ['o' => $table],
                [
                    'entity_id',
                    'created_at',
                    'updated_at',
                    'sync_at' => new \Zend_Db_Expr($syncExpression),
                ]
            )
            ->where('o.store_id = ?', max(0, $storeId))
            ->where('o.status IN (?)', self::VALIDATED_ORDER_STATUSES)
            ->where($syncExpression . ' >= ?', $sinceSql)
            ->where($syncExpression . ' <= ?', $untilSql)
            ->order([$syncExpression . ' ASC', 'o.entity_id ASC'])
            ->limit(max(1, $limit));

        if ($cursor !== null) {
            $createdAt = $connection->quote($cursor['created_at']);
            $orderId = (int)$cursor['order_id'];
            $select->where(sprintf('(%s > %s OR (%s = %s AND o.entity_id > %d))', $syncExpression, $createdAt, $syncExpression, $createdAt, $orderId));
        }

        $rows = $connection->fetchAll($select);
        return is_array($rows) ? $rows : [];
    }

    private function loadOrder(int $orderId): ?Order
    {
        try {
            $order = $this->orderRepository->get($orderId);
        } catch (\Throwable $e) {
            $this->logger->warning('[NC] Order history load skipped: ' . $e->getMessage(), ['order_id' => $orderId]);
            return null;
        }

        if (!$order instanceof Order) {
            $this->logger->warning('[NC] Order history load skipped: unsupported order model', ['order_id' => $orderId]);
            return null;
        }

        return $order;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildHistoryOrder(Order $order, string $mode): ?array
    {
        $event = $this->orderEventBuilder->build($order);
        if (!is_array($event)) {
            return null;
        }

        $eventOrder = is_array($event['order'] ?? null) ? $event['order'] : [];
        $items = is_array($eventOrder['items'] ?? null) ? array_values($eventOrder['items']) : [];
        $customer = is_array($event['customer'] ?? null) ? $event['customer'] : [];
        $source = is_array($event['source'] ?? null) ? $event['source'] : [];
        $context = is_array($event['context'] ?? null) ? $event['context'] : [];
        $source['channel'] = 'connector_history_sync';

        $orderId = (string)($event['order_id'] ?? $order->getEntityId());
        $cartId = (string)($event['cart_id'] ?? ($order->getQuoteId() ?: $order->getEntityId()));
        $currency = (string)($event['currency'] ?? $order->getOrderCurrencyCode());
        $eventOccurredAt = is_string($event['occurred_at'] ?? null) ? (string)$event['occurred_at'] : null;
        $occurredAt = $this->resolveHistoryOccurredAt($order, $eventOccurredAt);

        $payload = [
            'id' => $orderId,
            'order_id' => $orderId,
            'reference' => $event['order_reference'] ?? $order->getIncrementId(),
            'increment_id' => $event['order_increment_id'] ?? $order->getIncrementId(),
            'order_reference' => $event['order_reference'] ?? $order->getIncrementId(),
            'cart_id' => $cartId,
            'quote_id' => $cartId,
            'created_at' => $occurredAt,
            'occurred_at' => $occurredAt,
            'updated_at' => $this->normalizeDateForPayload($order->getUpdatedAt()),
            'status' => (string)($eventOrder['status'] ?? $order->getStatus()),
            'state' => (string)$order->getState(),
            'order_total' => (float)($event['order_total'] ?? $order->getGrandTotal()),
            'grand_total' => (float)$order->getGrandTotal(),
            'currency' => $currency,
            'currency_code' => $currency,
            'total_discounts' => (float)($event['total_discounts'] ?? abs((float)$order->getDiscountAmount())),
            'used_coupon_codes' => $event['used_coupon_codes'] ?? [],
            'customer_email' => $event['customer_email'] ?? $order->getCustomerEmail(),
            'customer' => $customer,
            'items' => $items,
            'source' => $source,
            'context' => $context,
        ];

        if ($mode === 'full') {
            $payload['event_payload'] = $event;
        }

        return $payload;
    }

    private function resolveHistoryOccurredAt(Order $order, ?string $eventOccurredAt): ?string
    {
        $createdAt = $this->normalizeDateForPayload($order->getCreatedAt());
        $fallback = $eventOccurredAt !== null && trim($eventOccurredAt) !== '' ? $eventOccurredAt : $createdAt;
        $status = strtolower(trim((string)$order->getStatus()));
        if (in_array($status, self::VALIDATED_ORDER_STATUSES, true)) {
            $updatedAt = $this->normalizeDateForPayload($order->getUpdatedAt());
            $updatedTs = $updatedAt !== null ? strtotime($updatedAt) : false;
            $createdTs = $createdAt !== null ? strtotime($createdAt) : false;
            if ($updatedAt !== null && $updatedTs !== false && ($createdTs === false || $updatedTs > $createdTs)) {
                return $updatedAt;
            }
        }

        return $fallback;
    }

    private function normalizeDateForPayload($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string)$value);
        if ($text === '') {
            return null;
        }

        $ts = strtotime($text);
        if ($ts === false) {
            return $text;
        }

        return gmdate('c', $ts);
    }
}
