<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Event;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use NeuroCheckout\Connector\Model\Config;

class TelemetryEventBuilder
{
    private const MAX_TEXT_LENGTH = 300;

    private const SUPPORTED_EVENT_TYPES = [
        'magento.checkout.performance',
        'magento.checkout.js_error',
        'magento.checkout.request_anomaly',
        'magento.checkout.friction_snapshot',
        'magento.checkout.shipping_cost_snapshot',
        'magento.payment.failed',
        'magento.connector.runtime_error',
    ];

    private Config $config;
    private StoreManagerInterface $storeManager;
    private ScopeConfigInterface $scopeConfig;
    private TimezoneInterface $timezone;
    private RequestInterface $request;
    private CheckoutSession $checkoutSession;

    public function __construct(
        Config $config,
        StoreManagerInterface $storeManager,
        ScopeConfigInterface $scopeConfig,
        TimezoneInterface $timezone,
        RequestInterface $request,
        CheckoutSession $checkoutSession
    ) {
        $this->config = $config;
        $this->storeManager = $storeManager;
        $this->scopeConfig = $scopeConfig;
        $this->timezone = $timezone;
        $this->request = $request;
        $this->checkoutSession = $checkoutSession;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public function buildFromBrowserPayload(array $payload, int $storeId): ?array
    {
        $eventType = $this->safeText($payload['event_type'] ?? '', 120);
        if ($eventType === null || !self::isSupportedEventType($eventType)) {
            return null;
        }

        $quote = $this->resolveSessionQuote();
        $metrics = $this->sanitizeMap($payload['metrics'] ?? [], 30);
        $context = $this->sanitizeMap($payload['context'] ?? [], 30);
        $context['origin'] = 'browser';
        $context['collector'] = 'magento_checkout_telemetry';
        $context['module_version'] = $this->safeText($context['module_version'] ?? '', 40);
        $context['request_path'] = $this->safeText($this->request->getRequestUri(), 200);

        if ($quote && (int) $quote->getId() > 0) {
            $context['cart_id'] = (string) (int) $quote->getId();
        }

        return [
            'event_id' => $this->normalizeEventId($payload['event_id'] ?? null),
            'event_type' => $eventType,
            'occurred_at' => $this->normalizeOccurredAt($payload['occurred_at'] ?? null),
            'source' => $this->buildSource($storeId),
            'cart' => $this->buildCartPayload($quote),
            'metrics' => $metrics,
            'context' => $context,
            'privacy' => $this->buildPrivacyPayload(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildShippingCostSnapshot(Quote $quote): ?array
    {
        $quoteId = (int) $quote->getId();
        if ($quoteId <= 0 || (int) $quote->getItemsCount() <= 0) {
            return null;
        }

        $storeId = (int) $quote->getStoreId();
        $productsTotal = $this->safeQuoteAmount($quote, 'subtotal');
        $shippingTotal = $this->safeQuoteAmount($quote, 'shipping');
        $grandTotal = $this->safeQuoteAmount($quote, 'grand_total');
        $shippingRatio = $productsTotal > 0 ? $shippingTotal / $productsTotal : 0.0;

        return [
            'event_id' => $this->deterministicEventId(
                'magento.checkout.shipping_cost_snapshot',
                (string) $storeId,
                (string) $quoteId,
                (string) floor(time() / 300),
                (string) round($shippingTotal, 2)
            ),
            'event_type' => 'magento.checkout.shipping_cost_snapshot',
            'occurred_at' => gmdate('c'),
            'source' => $this->buildSource($storeId),
            'cart' => $this->buildCartPayload($quote),
            'metrics' => [
                'cart_products_total' => round($productsTotal, 2),
                'shipping_total' => round($shippingTotal, 2),
                'shipping_ratio' => round($shippingRatio, 4),
                'cart_total' => round($grandTotal, 2),
            ],
            'context' => [
                'origin' => 'server',
                'collector' => 'magento_shipping_snapshot',
                'cart_id' => (string) $quoteId,
                'has_shipping_address' => $this->quoteHasShippingAddress($quote),
                'shipping_method' => $this->safeText((string) $quote->getShippingAddress()->getShippingMethod(), 120),
            ],
            'privacy' => $this->buildPrivacyPayload(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildPaymentFailed(Order $order): ?array
    {
        $orderId = (int) $order->getEntityId();
        if ($orderId <= 0 || !$this->isPaymentFailureOrder($order)) {
            return null;
        }

        $storeId = (int) $order->getStoreId();
        $state = strtolower((string) $order->getState());
        $status = strtolower((string) $order->getStatus());
        $cartId = (string) ((int) $order->getQuoteId() ?: $orderId);

        return [
            'event_id' => $this->deterministicEventId(
                'magento.payment.failed',
                (string) $storeId,
                (string) $orderId,
                $state,
                $status
            ),
            'event_type' => 'magento.payment.failed',
            'occurred_at' => gmdate('c'),
            'source' => $this->buildSource($storeId),
            'cart' => [
                'id' => $cartId,
                'uid' => 'magento_' . $cartId,
                'total' => round((float) $order->getGrandTotal(), 2),
            ],
            'metrics' => [
                'order_total' => round((float) $order->getGrandTotal(), 2),
            ],
            'context' => [
                'origin' => 'server',
                'collector' => 'magento_order_status',
                'order_id' => (string) $orderId,
                'cart_id' => $cartId,
                'order_state' => $this->safeText($state, 80),
                'order_status' => $this->safeText($status, 80),
                'payment_method' => $this->safeText($this->resolvePaymentMethod($order), 120),
            ],
            'privacy' => $this->buildPrivacyPayload(),
        ];
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public function buildConnectorRuntimeError(string $message, int $storeId, array $meta = []): array
    {
        return [
            'event_id' => $this->generateUuidV4(),
            'event_type' => 'magento.connector.runtime_error',
            'occurred_at' => gmdate('c'),
            'source' => $this->buildSource($storeId),
            'cart' => $this->buildCartPayload($this->resolveSessionQuote()),
            'metrics' => [],
            'context' => [
                'origin' => 'server',
                'collector' => 'magento_connector_runtime',
                'error_message' => $this->scrubText($message, self::MAX_TEXT_LENGTH),
            ] + $this->sanitizeMap($meta, 12),
            'privacy' => $this->buildPrivacyPayload(),
        ];
    }

    public static function isSupportedEventType(string $eventType): bool
    {
        return in_array($eventType, self::SUPPORTED_EVENT_TYPES, true);
    }

    private function buildSource(int $storeId): array
    {
        $shopExternalId = $this->config->getString(Config::XML_PATH_SHOP_EXTERNAL_ID, $storeId);
        if ($shopExternalId === '') {
            $shopExternalId = (string) $storeId;
        }

        $locale = (string) $this->scopeConfig->getValue(
            'general/locale/code',
            ScopeInterface::SCOPE_STORES,
            $storeId
        );

        $shopName = null;
        try {
            $shopName = (string) $this->storeManager->getStore($storeId)->getName();
        } catch (\Throwable $e) {
            $shopName = null;
        }

        return [
            'platform' => 'magento',
            'shop_id' => (string) $storeId,
            'shop_slug' => $shopExternalId,
            'shop_name' => $this->safeText($shopName, 120),
            'language' => $this->resolveLanguageCode($locale),
        ];
    }

    private function buildCartPayload(?Quote $quote): array
    {
        if (!$quote || (int) $quote->getId() <= 0) {
            return [
                'id' => null,
                'uid' => null,
                'total' => null,
            ];
        }

        $quoteId = (int) $quote->getId();

        return [
            'id' => (string) $quoteId,
            'uid' => 'magento_' . $quoteId,
            'total' => round($this->safeQuoteAmount($quote, 'grand_total'), 2),
        ];
    }

    private function resolveSessionQuote(): ?Quote
    {
        try {
            $quote = $this->checkoutSession->getQuote();
            return $quote && (int) $quote->getId() > 0 ? $quote : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function safeQuoteAmount(Quote $quote, string $scope): float
    {
        try {
            if ($scope === 'subtotal') {
                return max(0.0, (float) ($quote->getSubtotalWithDiscount() ?: $quote->getSubtotal()));
            }
            if ($scope === 'shipping') {
                return max(0.0, (float) $quote->getShippingAddress()->getShippingAmount());
            }

            return max(0.0, (float) $quote->getGrandTotal());
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    private function quoteHasShippingAddress(Quote $quote): bool
    {
        try {
            $address = $quote->getShippingAddress();
            return trim((string) $address->getCountryId()) !== ''
                || trim((string) $address->getPostcode()) !== '';
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function isPaymentFailureOrder(Order $order): bool
    {
        $state = strtolower(trim((string) $order->getState()));
        $status = strtolower(trim((string) $order->getStatus()));
        $text = $state . ' ' . $status;

        foreach (['payment_review', 'holded', 'canceled', 'cancelled', 'fraud', 'failed', 'declined', 'denied', 'error'] as $keyword) {
            if (str_contains($text, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function resolvePaymentMethod(Order $order): string
    {
        try {
            $payment = $order->getPayment();
            if (!$payment) {
                return '';
            }

            return (string) ($payment->getMethod() ?: '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function normalizeEventId($value): string
    {
        $eventId = trim((string) $value);
        if (preg_match('/^[a-f0-9-]{32,80}$/i', $eventId)) {
            return substr($eventId, 0, 80);
        }

        return $this->generateUuidV4();
    }

    private function normalizeOccurredAt($value): string
    {
        $raw = trim((string) $value);
        if ($raw !== '') {
            $timestamp = strtotime($raw);
            if ($timestamp !== false && abs(time() - $timestamp) < 86400) {
                return gmdate('c', $timestamp);
            }
        }

        return gmdate('c');
    }

    private function deterministicEventId(string ...$parts): string
    {
        $hash = hash('sha256', implode('|', $parts));

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 4),
            substr($hash, 16, 4),
            substr($hash, 20, 12)
        );
    }

    private function generateUuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function resolveLanguageCode(string $locale): string
    {
        $normalized = strtolower(str_replace('_', '-', trim($locale)));
        $language = explode('-', $normalized, 2)[0] ?? '';

        return in_array($language, ['fr', 'en', 'es', 'de', 'it'], true) ? $language : 'en';
    }

    /**
     * @return array<string, mixed>
     */
    private function sanitizeMap($value, int $maxItems): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        $index = 0;
        foreach ($value as $key => $item) {
            if ($index >= $maxItems) {
                $result['_truncated'] = true;
                break;
            }

            $normalizedKey = preg_replace('/[^a-zA-Z0-9_.-]/', '_', (string) $key);
            $normalizedKey = substr((string) $normalizedKey, 0, 80);
            if ($normalizedKey === '') {
                continue;
            }

            $result[$normalizedKey] = $this->sanitizeScalar($item);
            $index++;
        }

        return $result;
    }

    private function sanitizeScalar($value)
    {
        if (is_bool($value) || $value === null || is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return is_finite($value) ? round($value, 4) : 0.0;
        }
        if (is_numeric($value)) {
            $numeric = (float) $value;
            return floor($numeric) === $numeric ? (int) $numeric : round($numeric, 4);
        }
        if (is_array($value)) {
            return $this->sanitizeMap($value, 12);
        }

        return $this->scrubText((string) $value, self::MAX_TEXT_LENGTH);
    }

    private function safeText($value, int $maxLength): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        return $this->scrubText($text, $maxLength);
    }

    private function scrubText(string $text, int $maxLength): string
    {
        $text = preg_replace('/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/', '[email]', $text);
        $text = preg_replace('/https?:\/\/[^\s"\']+/i', '[url]', (string) $text);
        $text = preg_replace('/\b(?:sk|pk|rk|whsec|secret|token|api[_-]?key)[_-]?[A-Za-z0-9]{12,}\b/i', '[secret]', (string) $text);
        $text = trim((string) $text);

        return substr($text, 0, $maxLength);
    }

    /**
     * @return array<string, bool>
     */
    private function buildPrivacyPayload(): array
    {
        return [
            'contains_raw_server_logs' => false,
            'contains_payment_provider_logs' => false,
            'contains_form_values' => false,
            'contains_personal_data' => false,
        ];
    }
}
