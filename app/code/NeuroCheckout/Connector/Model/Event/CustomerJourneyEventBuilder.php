<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Event;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use NeuroCheckout\Connector\Model\Config;

class CustomerJourneyEventBuilder
{
    private const EVENT_PREFIX = 'magento.customer_journey.';
    private const MAX_TEXT_LENGTH = 300;

    private const EVENT_SUFFIXES = [
        'page_view',
        'product_view',
        'category_view',
        'cart_view',
        'add_to_cart_intent',
        'checkout_started',
        'checkout_step',
        'form_error',
        'performance',
        'exit_intent',
        'cart_snapshot',
        'order_completed',
    ];

    private const BROWSER_EVENT_SUFFIXES = [
        'page_view',
        'product_view',
        'category_view',
        'cart_view',
        'add_to_cart_intent',
        'checkout_started',
        'checkout_step',
        'form_error',
        'performance',
        'exit_intent',
    ];

    private Config $config;
    private StoreManagerInterface $storeManager;
    private ScopeConfigInterface $scopeConfig;
    private TimezoneInterface $timezone;
    private RequestInterface $request;
    private CheckoutSession $checkoutSession;
    private CustomerSession $customerSession;
    private ProductContextResolver $productContextResolver;

    public function __construct(
        Config $config,
        StoreManagerInterface $storeManager,
        ScopeConfigInterface $scopeConfig,
        TimezoneInterface $timezone,
        RequestInterface $request,
        CheckoutSession $checkoutSession,
        CustomerSession $customerSession,
        ProductContextResolver $productContextResolver
    ) {
        $this->config = $config;
        $this->storeManager = $storeManager;
        $this->scopeConfig = $scopeConfig;
        $this->timezone = $timezone;
        $this->request = $request;
        $this->checkoutSession = $checkoutSession;
        $this->customerSession = $customerSession;
        $this->productContextResolver = $productContextResolver;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public function buildFromBrowserPayload(array $payload, int $storeId): ?array
    {
        $eventType = $this->normalizeEventType($payload['event_type'] ?? null, self::BROWSER_EVENT_SUFFIXES);
        if ($eventType === null) {
            return null;
        }

        $quote = $this->resolveSessionQuote();
        $journey = $this->sanitizeMap($payload['journey'] ?? [], 30);
        $page = $this->sanitizeMap($payload['page'] ?? [], 20);
        $event = $this->sanitizeMap($payload['event'] ?? [], 20);
        $metrics = $this->sanitizeMap($payload['metrics'] ?? [], 30);
        $context = $this->sanitizeMap($payload['context'] ?? [], 30);

        $visitorId = $this->safeIdentifier($journey['visitor_id'] ?? $payload['visitor_id'] ?? $this->readCookie('ncmagento_journey_visitor'), 80);
        $sessionId = $this->safeIdentifier($journey['session_id'] ?? $payload['session_id'] ?? $this->readCookie('ncmagento_journey_session'), 80);
        if ($visitorId !== null) {
            $journey['visitor_id'] = $visitorId;
        }
        if ($sessionId !== null) {
            $journey['session_id'] = $sessionId;
        }

        if ($quote && (int) $quote->getId() > 0) {
            $journey['cart_id'] = (string) (int) $quote->getId();
            $context['cart_id'] = (string) (int) $quote->getId();
        }

        $context['origin'] = 'browser';
        $context['collector'] = 'magento_customer_journey_tracker';
        $context['module_version'] = $this->safeText($context['module_version'] ?? '', 40);
        $context['store_id'] = (string) $storeId;
        $context['request_path'] = $this->safeText($this->request->getRequestUri(), 200);

        return [
            'event_id' => $this->normalizeEventId($payload['event_id'] ?? null),
            'event_type' => $eventType,
            'occurred_at' => $this->normalizeOccurredAt($payload['occurred_at'] ?? null),
            'source' => $this->buildSource($storeId),
            'customer' => $this->buildCustomerFromQuote($quote),
            'cart' => $this->buildCartPayload($quote, false),
            'journey' => $journey,
            'page' => $page,
            'event' => $event,
            'metrics' => $metrics,
            'context' => $context,
            'privacy' => $this->buildPrivacyPayload(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildCartSnapshot(Quote $quote, string $hookName): ?array
    {
        $quoteId = (int) $quote->getId();
        if ($quoteId <= 0) {
            return null;
        }

        $itemsCount = (int) $quote->getItemsCount();
        if ($itemsCount <= 0 && !str_contains(strtolower($hookName), 'remove')) {
            return null;
        }

        $storeId = (int) $quote->getStoreId();

        return [
            'event_id' => $this->deterministicEventId(
                self::EVENT_PREFIX . 'cart_snapshot',
                $this->shopExternalId($storeId),
                (string) $quoteId,
                (string) $itemsCount,
                (string) floor(time() / 300)
            ),
            'event_type' => self::EVENT_PREFIX . 'cart_snapshot',
            'occurred_at' => gmdate('c'),
            'source' => $this->buildSource($storeId),
            'customer' => $this->buildCustomerFromQuote($quote),
            'cart' => $this->buildCartPayload($quote, true),
            'journey' => $this->buildJourneyRuntime($quoteId, 'cart_snapshot', $hookName),
            'event' => [
                'name' => 'cart_snapshot',
                'hook' => $this->safeText($hookName, 120),
                'origin' => 'server',
            ],
            'context' => $this->buildRuntimeContext($storeId, 'magento_cart_snapshot'),
            'privacy' => $this->buildPrivacyPayload(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildOrderCompleted(Order $order, string $hookName): ?array
    {
        $orderId = (int) $order->getEntityId();
        if ($orderId <= 0) {
            return null;
        }

        $storeId = (int) $order->getStoreId();
        $cartId = (int) $order->getQuoteId();
        if ($cartId <= 0) {
            $cartId = $orderId;
        }

        $items = $this->buildOrderItems($order, $storeId);
        $discounts = $this->buildOrderDiscountPayload($order);

        return [
            'event_id' => $this->deterministicEventId(
                self::EVENT_PREFIX . 'order_completed',
                $this->shopExternalId($storeId),
                (string) $orderId,
                (string) $cartId
            ),
            'event_type' => self::EVENT_PREFIX . 'order_completed',
            'occurred_at' => $this->normalizeOccurredAt($order->getCreatedAt() ?: null),
            'source' => $this->buildSource($storeId),
            'customer' => $this->buildCustomerFromOrder($order),
            'cart' => [
                'id' => (string) $cartId,
                'uid' => 'magento_' . $cartId,
                'total' => round((float) $order->getGrandTotal(), 2),
                'currency_code' => (string) $order->getOrderCurrencyCode(),
                'items' => $items,
                'coupon_codes' => $discounts['coupon_codes'],
                'total_discounts' => $discounts['total_discounts'],
            ],
            'order' => [
                'id' => (string) $orderId,
                'increment_id' => $this->safeText((string) $order->getIncrementId(), 80),
                'status' => $this->safeText((string) $order->getStatus(), 80),
                'total' => round((float) $order->getGrandTotal(), 2),
                'currency_code' => (string) $order->getOrderCurrencyCode(),
                'items' => $items,
                'coupon_codes' => $discounts['coupon_codes'],
                'total_discounts' => $discounts['total_discounts'],
            ],
            'journey' => $this->buildJourneyRuntime($cartId, 'order_completed', $hookName) + [
                'products_purchased' => $items,
            ],
            'event' => [
                'name' => 'order_completed',
                'hook' => $this->safeText($hookName, 120),
                'origin' => 'server',
            ],
            'context' => $this->buildRuntimeContext($storeId, 'magento_order_completed'),
            'privacy' => $this->buildPrivacyPayload(),
        ];
    }

    /**
     * @param list<string> $allowedSuffixes
     */
    private function normalizeEventType($value, array $allowedSuffixes): ?string
    {
        $raw = strtolower(trim((string) $value));
        if ($raw === '') {
            return null;
        }

        $suffix = str_starts_with($raw, self::EVENT_PREFIX)
            ? substr($raw, strlen(self::EVENT_PREFIX))
            : $raw;

        if (!in_array($suffix, $allowedSuffixes, true) || !in_array($suffix, self::EVENT_SUFFIXES, true)) {
            return null;
        }

        return self::EVENT_PREFIX . $suffix;
    }

    private function buildSource(int $storeId): array
    {
        $shopExternalId = $this->shopExternalId($storeId);
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
            'shop_id' => $shopExternalId,
            'shop_slug' => $shopExternalId,
            'store_id' => (string) $storeId,
            'magento_store_id' => (string) $storeId,
            'shop_name' => $this->safeText($shopName, 120),
            'language' => $this->resolveLanguageCode($locale),
        ];
    }

    private function shopExternalId(int $storeId): string
    {
        $shopExternalId = $this->config->getString(Config::XML_PATH_SHOP_EXTERNAL_ID, $storeId);

        return $shopExternalId !== '' ? $shopExternalId : (string) $storeId;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRuntimeContext(int $storeId, string $collector): array
    {
        $locale = (string) $this->scopeConfig->getValue(
            'general/locale/code',
            ScopeInterface::SCOPE_STORES,
            $storeId
        );

        return [
            'origin' => 'server',
            'collector' => $collector,
            'store_id' => (string) $storeId,
            'shop_locale' => $locale,
            'shop_language' => $this->resolveLanguageCode($locale),
            'shop_timezone' => (string) $this->timezone->getConfigTimezone(ScopeInterface::SCOPE_STORES, $storeId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildJourneyRuntime(int $cartId, string $eventName, string $hookName): array
    {
        $visitorId = $this->safeIdentifier($this->readCookie('ncmagento_journey_visitor'), 80);
        $sessionId = $this->safeIdentifier($this->readCookie('ncmagento_journey_session'), 80);

        return [
            'visitor_id' => $visitorId,
            'session_id' => $sessionId,
            'cart_id' => $cartId > 0 ? (string) $cartId : null,
            'event' => [
                'name' => $eventName,
                'hook' => $this->safeText($hookName, 120),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCustomerFromQuote(?Quote $quote): array
    {
        $email = null;
        $customerId = null;
        $isGuest = true;
        $nameHint = null;

        if ($quote) {
            $email = trim((string) $quote->getCustomerEmail()) ?: null;
            $customerId = (int) $quote->getCustomerId() > 0 ? (string) (int) $quote->getCustomerId() : null;
            $isGuest = !$customerId;
            $nameHint = $this->buildNameHint($quote->getCustomerFirstname(), $quote->getCustomerLastname());
        }

        if ($email === null) {
            try {
                if ($this->customerSession->isLoggedIn()) {
                    $customer = $this->customerSession->getCustomer();
                    $email = trim((string) $customer->getEmail()) ?: null;
                    $customerId = (string) (int) $customer->getId();
                    $isGuest = false;
                    $nameHint = $this->buildNameHint($customer->getFirstname(), $customer->getLastname());
                }
            } catch (\Throwable $e) {
            }
        }

        return [
            'id' => $customerId,
            'email_hash' => $this->emailHash($email),
            'masked_email' => $this->maskEmail($email),
            'name_hint' => $nameHint,
            'is_guest' => $isGuest,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCustomerFromOrder(Order $order): array
    {
        $email = trim((string) $order->getCustomerEmail()) ?: null;

        return [
            'id' => $order->getCustomerId() ? (string) $order->getCustomerId() : null,
            'email_hash' => $this->emailHash($email),
            'masked_email' => $this->maskEmail($email),
            'name_hint' => $this->buildNameHint($order->getCustomerFirstname(), $order->getCustomerLastname()),
            'is_guest' => (bool) $order->getCustomerIsGuest(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCartPayload(?Quote $quote, bool $includeItems): array
    {
        if (!$quote || (int) $quote->getId() <= 0) {
            return [
                'id' => null,
                'uid' => null,
                'total' => null,
                'items_count' => 0,
            ];
        }

        $quoteId = (int) $quote->getId();
        $items = $includeItems ? $this->buildQuoteItems($quote, (int) $quote->getStoreId()) : [];
        $discounts = $this->buildQuoteDiscountPayload($quote);

        return [
            'id' => (string) $quoteId,
            'uid' => 'magento_' . $quoteId,
            'total' => round($this->safeQuoteAmount($quote, 'grand_total'), 2),
            'subtotal' => round($this->safeQuoteAmount($quote, 'subtotal'), 2),
            'currency_code' => (string) $quote->getQuoteCurrencyCode(),
            'items_count' => (int) $quote->getItemsCount(),
            'items' => $items,
            'coupon_codes' => $discounts['coupon_codes'],
            'total_discounts' => $discounts['total_discounts'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildQuoteItems(Quote $quote, int $storeId): array
    {
        $items = [];
        foreach ($quote->getAllVisibleItems() as $index => $item) {
            $product = $item->getProduct();
            $productUrl = $product && method_exists($product, 'getProductUrl') ? (string) $product->getProductUrl() : null;
            $productContext = $this->productContextResolver->resolveForCartItem($item, $storeId, $productUrl, null);
            $orderOptions = method_exists($item, 'getProductOrderOptions') ? $item->getProductOrderOptions() : null;

            $items[] = [
                'product_id' => $item->getProductId() ? (string) $item->getProductId() : null,
                'attribute_id' => (int) ($productContext['attribute_id'] ?? 0) > 0 ? (string) $productContext['attribute_id'] : null,
                'sku' => $this->safeText((string) $item->getSku(), 120),
                'name' => $this->safeText((string) $item->getName(), 180),
                'variant_label' => $this->productContextResolver->buildVariantLabelFromOptions($orderOptions),
                'category_path' => $productContext['category_path'] ?? null,
                'brand_name' => $productContext['brand_name'] ?? null,
                'quantity' => (int) $item->getQty(),
                'unit_price' => round((float) $item->getPriceInclTax(), 2),
                'line_total' => round((float) $item->getRowTotalInclTax(), 2),
                'product_url' => $productContext['product_url'] ?? null,
                'image_url' => $productContext['image_url'] ?? null,
                'line_number' => $index + 1,
            ];
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildOrderItems(Order $order, int $storeId): array
    {
        $items = [];
        foreach ($order->getAllVisibleItems() as $index => $item) {
            $productContext = $this->productContextResolver->resolveForOrderItem($item, $storeId);
            $variantLabel = $this->productContextResolver->buildVariantLabelFromOptions($item->getProductOptions());

            $items[] = [
                'product_id' => $item->getProductId() ? (string) $item->getProductId() : null,
                'attribute_id' => (int) ($productContext['attribute_id'] ?? 0) > 0 ? (string) $productContext['attribute_id'] : null,
                'sku' => $this->safeText((string) $item->getSku(), 120),
                'name' => $this->safeText((string) $item->getName(), 180),
                'variant_label' => $variantLabel,
                'category_path' => $productContext['category_path'] ?? null,
                'brand_name' => $productContext['brand_name'] ?? null,
                'quantity' => (int) $item->getQtyOrdered(),
                'unit_price' => round((float) $item->getPriceInclTax(), 2),
                'line_total' => round((float) $item->getRowTotalInclTax(), 2),
                'product_url' => $productContext['product_url'] ?? null,
                'image_url' => $productContext['image_url'] ?? null,
                'line_number' => $index + 1,
            ];
        }

        return $items;
    }

    /**
     * @return array{coupon_codes: list<string>, total_discounts: float}
     */
    private function buildQuoteDiscountPayload(Quote $quote): array
    {
        $couponCode = strtoupper(trim((string) $quote->getCouponCode()));
        $subtotal = (float) $quote->getSubtotal();
        $subtotalWithDiscount = (float) ($quote->getSubtotalWithDiscount() ?: $subtotal);
        $discountTotal = max(0.0, $subtotal - $subtotalWithDiscount);

        return [
            'coupon_codes' => $couponCode !== '' ? [$couponCode] : [],
            'total_discounts' => round($discountTotal, 2),
        ];
    }

    /**
     * @return array{coupon_codes: list<string>, total_discounts: float}
     */
    private function buildOrderDiscountPayload(Order $order): array
    {
        $couponCode = strtoupper(trim((string) $order->getCouponCode()));

        return [
            'coupon_codes' => $couponCode !== '' ? [$couponCode] : [],
            'total_discounts' => round(abs((float) $order->getDiscountAmount()), 2),
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

            return max(0.0, (float) $quote->getGrandTotal());
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    private function normalizeEventId($value): string
    {
        $eventId = trim((string) $value);
        if (preg_match('/^[a-f0-9-]{32,120}$/i', $eventId)) {
            return substr($eventId, 0, 120);
        }

        return $this->generateUuidV4();
    }

    private function normalizeOccurredAt($value): string
    {
        $raw = trim((string) $value);
        if ($raw !== '') {
            $timestamp = strtotime($raw);
            if ($timestamp !== false && abs(time() - $timestamp) < 604800) {
                return gmdate('c', $timestamp);
            }
        }

        return gmdate('c');
    }

    /**
     * @param mixed $value
     */
    private function safeText($value, int $limit = self::MAX_TEXT_LENGTH): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        return substr($this->scrubText($text, $limit), 0, $limit);
    }

    private function safeIdentifier($value, int $limit): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        $text = preg_replace('/[^A-Za-z0-9_.:-]/', '', $text) ?: '';

        return $text !== '' ? substr($text, 0, $limit) : null;
    }

    /**
     * @param mixed $value
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
            $safeKey = preg_replace('/[^A-Za-z0-9_.:-]/', '', (string) $key) ?: '';
            if ($safeKey === '' || $this->isSensitiveKey($safeKey)) {
                $index++;
                continue;
            }
            $result[substr($safeKey, 0, 80)] = $this->sanitizeValue($item, 3);
            $index++;
        }

        return $result;
    }

    private function sanitizeValue($value, int $depth)
    {
        if ($depth <= 0) {
            return '[truncated]';
        }
        if (is_array($value)) {
            $result = [];
            $index = 0;
            foreach ($value as $key => $item) {
                if ($index >= 20) {
                    $result['_truncated'] = true;
                    break;
                }
                $safeKey = preg_replace('/[^A-Za-z0-9_.:-]/', '', (string) $key) ?: '';
                if ($safeKey === '' || $this->isSensitiveKey($safeKey)) {
                    $index++;
                    continue;
                }
                $result[substr($safeKey, 0, 80)] = $this->sanitizeValue($item, $depth - 1);
                $index++;
            }
            return $result;
        }
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        return $this->safeText($value, self::MAX_TEXT_LENGTH);
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);
        foreach (['authorization', 'card', 'cookie', 'cvc', 'cvv', 'password', 'payment', 'secret', 'token'] as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function scrubText(string $text, int $limit): string
    {
        $text = preg_replace('/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/', '[email]', $text) ?: $text;
        $text = preg_replace('/https?:\/\/[^\s"\'<>]+/i', '[url]', $text) ?: $text;
        $text = preg_replace('/\b(?:sk|pk|rk|whsec|secret|token|api[_-]?key)[_-]?[A-Za-z0-9]{12,}\b/i', '[secret]', $text) ?: $text;

        return substr($text, 0, $limit);
    }

    private function readCookie(string $name): ?string
    {
        return isset($_COOKIE[$name]) ? (string) $_COOKIE[$name] : null;
    }

    private function emailHash(?string $email): ?string
    {
        $normalized = strtolower(trim((string) $email));
        if ($normalized === '' || !str_contains($normalized, '@')) {
            return null;
        }

        return hash('sha256', $normalized);
    }

    private function maskEmail(?string $email): ?string
    {
        $normalized = strtolower(trim((string) $email));
        if ($normalized === '' || !str_contains($normalized, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', $normalized, 2);
        $prefix = substr($local, 0, max(1, min(3, strlen($local))));

        return $prefix . '***@' . $domain;
    }

    private function buildNameHint($firstName, $lastName): ?string
    {
        $initials = '';
        foreach ([$firstName, $lastName] as $part) {
            $text = trim((string) $part);
            if ($text !== '') {
                $initials .= strtoupper(substr($text, 0, 1));
            }
        }

        return $initials !== '' ? substr($initials, 0, 4) : null;
    }

    private function resolveLanguageCode(string $locale): string
    {
        $locale = trim($locale);
        if ($locale === '') {
            return 'en';
        }

        return strtolower(substr($locale, 0, 2));
    }

    private function buildPrivacyPayload(): array
    {
        return [
            'contains_raw_server_logs' => false,
            'contains_payment_provider_logs' => false,
            'contains_form_values' => false,
            'contains_payment_data' => false,
            'contains_cookie_values' => false,
            'contains_session_ids' => false,
        ];
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
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
