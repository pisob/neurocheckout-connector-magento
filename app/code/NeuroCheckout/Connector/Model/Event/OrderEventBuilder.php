<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Event;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use NeuroCheckout\Connector\Model\Config;

class OrderEventBuilder
{
    private const ENGLISH_COUNTRY_LOCALES = [
        'AU' => 'en_AU',
        'CA' => 'en_CA',
        'GB' => 'en_GB',
        'IE' => 'en_IE',
        'NZ' => 'en_NZ',
        'SG' => 'en_SG',
        'US' => 'en_US',
        'ZA' => 'en_ZA',
    ];

    private Config $config;
    private StoreManagerInterface $storeManager;
    private ScopeConfigInterface $scopeConfig;
    private TimezoneInterface $timezone;
    private StorefrontThemeResolver $storefrontThemeResolver;
    private ProductContextResolver $productContextResolver;
    private ResourceConnection $resource;

    public function __construct(
        Config $config,
        StoreManagerInterface $storeManager,
        ScopeConfigInterface $scopeConfig,
        TimezoneInterface $timezone,
        StorefrontThemeResolver $storefrontThemeResolver,
        ProductContextResolver $productContextResolver,
        ResourceConnection $resource
    ) {
        $this->config = $config;
        $this->storeManager = $storeManager;
        $this->scopeConfig = $scopeConfig;
        $this->timezone = $timezone;
        $this->storefrontThemeResolver = $storefrontThemeResolver;
        $this->productContextResolver = $productContextResolver;
        $this->resource = $resource;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function build(Order $order): ?array
    {
        $orderId = (int) $order->getEntityId();
        $cartId = (int) $order->getQuoteId();
        if ($orderId <= 0) {
            return null;
        }
        if ($cartId <= 0) {
            $cartId = $orderId;
        }

        $storeId = (int) $order->getStoreId();
        $store = $this->storeManager->getStore($storeId);
        $shopExternalId = $this->config->getString(Config::XML_PATH_SHOP_EXTERNAL_ID, $storeId);
        if ($shopExternalId === '') {
            $shopExternalId = (string) $storeId;
        }

        $locale = (string) $this->scopeConfig->getValue(
            'general/locale/code',
            ScopeInterface::SCOPE_STORES,
            $storeId
        );
        $shopLanguage = $this->resolveLanguageCode($locale);
        $primaryColor = $this->storefrontThemeResolver->resolvePrimaryColor($storeId, $locale);

        $items = [];
        foreach ($order->getAllVisibleItems() as $index => $item) {
            $productContext = $this->productContextResolver->resolveForOrderItem($item, $storeId);
            $variantLabel = $this->productContextResolver->buildVariantLabelFromOptions(
                $item->getProductOptions()
            );
            $attributeId = (int) ($productContext['attribute_id'] ?? 0);
            $reference = $item->getSku() ?: null;

            $items[] = [
                'product_id' => $item->getProductId() ? (string)$item->getProductId() : null,
                'attribute_id' => $attributeId > 0 ? (string)$attributeId : null,
                'reference' => $reference,
                'name' => $item->getName() ?: null,
                'variant_label' => $variantLabel,
                'category_path' => $productContext['category_path'] ?? null,
                'brand_name' => $productContext['brand_name'] ?? null,
                'quantity' => (int) $item->getQtyOrdered(),
                'unit_price' => round((float) $item->getPriceInclTax(), 2),
                'line_total' => round((float) $item->getRowTotalInclTax(), 2),
                'currency_code' => (string) $order->getOrderCurrencyCode(),
                'product_url' => $productContext['product_url'] ?? null,
                'image_url' => $productContext['image_url'] ?? null,
                'product_snapshot' => [
                    'product_name' => $item->getName() ?: null,
                    'reference' => $reference,
                    'variant_label' => $variantLabel,
                    'product_url' => $productContext['product_url'] ?? null,
                    'image_url' => $productContext['image_url'] ?? null,
                    'category_path' => $productContext['category_path'] ?? null,
                    'brand_name' => $productContext['brand_name'] ?? null,
                ],
                'line_number' => $index + 1,
            ];
        }

        $addressPayload = $this->resolveAddressPayload($order);
        $customerLocale = $this->resolveCustomerLocale($order, $locale);
        $customerLanguage = $this->resolveLanguageCode($customerLocale);
        $customerPhone = null;
        if ($addressPayload && !empty($addressPayload['phone'])) {
            $customerPhone = $addressPayload['phone'];
        } elseif ($order->getBillingAddress()) {
            $customerPhone = $order->getBillingAddress()->getTelephone() ?: null;
        }

        $discountContext = $this->resolveDiscountContext(
            $order,
            $cartId,
            $order->getCustomerEmail() ?: null
        );

        $runtimeContext = [
            'shop_locale' => $locale,
            'shop_language' => $shopLanguage,
            'currency_code' => (string) $order->getOrderCurrencyCode(),
            'currency_precision' => 2,
            'shop_timezone' => (string) $this->timezone->getConfigTimezone(ScopeInterface::SCOPE_STORES, $storeId),
        ];
        if ($primaryColor !== null) {
            $runtimeContext['primary_color'] = $primaryColor;
            $runtimeContext['theme_palette'] = ['primary_color' => $primaryColor];
        }
        $orderIncrementId = trim((string) $order->getIncrementId());
        if ($orderIncrementId === '') {
            $orderIncrementId = null;
        }

        return [
            'event_id' => $this->deterministicEventId($shopExternalId, (string)$orderId, (string)$cartId),
            'event_type' => 'order.completed',
            'occurred_at' => $this->resolveOccurredAt($order),
            'language' => $customerLanguage,
            'order_id' => (string) $orderId,
            'technical_order_id' => (string) $orderId,
            'external_order_id' => $orderIncrementId ?: (string) $orderId,
            'customer_visible_order_id' => $orderIncrementId ?: (string) $orderId,
            'order_reference' => $orderIncrementId,
            'order_increment_id' => $orderIncrementId,
            'cart_id' => (string) $cartId,
            'order_total' => round((float) $order->getGrandTotal(), 2),
            'customer_email' => $order->getCustomerEmail() ?: null,
            'currency' => (string) $order->getOrderCurrencyCode(),
            'total_discounts' => $discountContext['total_discounts'],
            'used_coupon_codes' => $discountContext['used_coupon_codes'],
            'neuro_coupon_used' => $discountContext['neuro_coupon_used'],
            'neuro_coupon_code' => $discountContext['neuro_coupon_code'],
            'neuro_discount_percent' => $discountContext['neuro_discount_percent'],
            'neuro_discount_amount' => $discountContext['neuro_discount_amount'],
            'customer' => [
                'id' => $order->getCustomerId() ? (string)$order->getCustomerId() : null,
                'email' => $order->getCustomerEmail() ?: null,
                'is_guest' => (bool)$order->getCustomerIsGuest(),
                'first_name' => $order->getCustomerFirstname() ?: null,
                'last_name' => $order->getCustomerLastname() ?: null,
                'phone' => $customerPhone,
                'locale' => $customerLocale,
                'language' => $customerLanguage,
                'address' => $addressPayload,
            ],
            'order' => [
                'reference' => $orderIncrementId,
                'increment_id' => $orderIncrementId,
                'status' => $order->getStatus(),
                'items' => $items,
            ],
            'source' => [
                'platform' => 'magento',
                'shop_id' => (string) $storeId,
                'shop_slug' => $shopExternalId,
                'shop_name' => (string) $store->getName(),
                'language' => $shopLanguage,
            ],
            'context' => $runtimeContext,
        ];
    }

    /**
     * @return array{
     *   total_discounts: float,
     *   used_coupon_codes: list<string>,
     *   neuro_coupon_used: bool,
     *   neuro_coupon_code: ?string,
     *   neuro_discount_percent: ?float,
     *   neuro_discount_amount: float
     * }
     */
    private function resolveDiscountContext(Order $order, int $cartId, ?string $customerEmail): array
    {
        $orderCouponCode = strtoupper(trim((string) $order->getCouponCode()));
        $usedCouponCodes = $orderCouponCode !== '' ? [$orderCouponCode] : [];
        $appliedRuleIds = $this->resolveAppliedRuleIds($order);
        $totalDiscounts = round(abs((float) $order->getDiscountAmount()), 2);

        $neuroRows = $this->loadNeuroCouponRows((int) $order->getStoreId(), $cartId, $customerEmail);
        $neuroCouponUsed = false;
        $neuroCouponCode = null;
        $neuroDiscountPercent = null;
        $neuroDiscountAmount = 0.0;

        foreach ($neuroRows as $row) {
            $couponCode = strtoupper(trim((string) ($row['coupon_code'] ?? '')));
            $ruleId = (int) ($row['rule_id'] ?? 0);

            $matched = false;
            if ($couponCode !== '' && $orderCouponCode !== '' && hash_equals($couponCode, $orderCouponCode)) {
                $matched = true;
            } elseif ($ruleId > 0 && in_array($ruleId, $appliedRuleIds, true)) {
                $matched = true;
            }

            if (!$matched) {
                continue;
            }

            $neuroCouponUsed = true;
            $neuroCouponCode = $couponCode !== '' ? $couponCode : $neuroCouponCode;
            if ($neuroDiscountPercent === null && isset($row['discount_percent'])) {
                $neuroDiscountPercent = round((float) $row['discount_percent'], 2);
            }

            if ($orderCouponCode !== '' && $couponCode !== '' && hash_equals($couponCode, $orderCouponCode)) {
                $neuroDiscountAmount = $totalDiscounts;
            } elseif (count($appliedRuleIds) === 1 && $ruleId > 0 && in_array($ruleId, $appliedRuleIds, true)) {
                $neuroDiscountAmount = $totalDiscounts;
            }
            break;
        }

        if (!$neuroCouponUsed && $orderCouponCode !== '' && str_starts_with($orderCouponCode, 'NC-')) {
            $neuroCouponUsed = true;
            $neuroCouponCode = $orderCouponCode;
            $neuroDiscountAmount = $totalDiscounts;
        }

        return [
            'total_discounts' => $totalDiscounts,
            'used_coupon_codes' => $usedCouponCodes,
            'neuro_coupon_used' => $neuroCouponUsed,
            'neuro_coupon_code' => $neuroCouponCode,
            'neuro_discount_percent' => $neuroDiscountPercent,
            'neuro_discount_amount' => round($neuroDiscountAmount, 2),
        ];
    }

    /**
     * @return list<int>
     */
    private function resolveAppliedRuleIds(Order $order): array
    {
        $rawValue = trim((string) $order->getAppliedRuleIds());
        if ($rawValue === '') {
            return [];
        }

        $ruleIds = array_map('intval', array_filter(array_map('trim', explode(',', $rawValue)), static function ($value) {
            return $value !== '';
        }));

        return array_values(array_unique(array_filter($ruleIds, static function ($ruleId) {
            return $ruleId > 0;
        })));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadNeuroCouponRows(int $storeId, int $cartId, ?string $customerEmail): array
    {
        if ($storeId <= 0 || $cartId <= 0) {
            return [];
        }

        $table = $this->resource->getTableName('neurocheckout_coupon');
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($table, ['rule_id', 'coupon_code', 'discount_percent'])
            ->where('store_id = ?', $storeId)
            ->where('cart_id = ?', (string) $cartId)
            ->order('created_at DESC');

        $normalizedEmail = strtolower(trim((string) $customerEmail));
        if ($normalizedEmail !== '') {
            $select->where('LOWER(customer_email) = ?', $normalizedEmail);
        }

        $rows = $connection->fetchAll($select);
        return is_array($rows) ? $rows : [];
    }

    private function resolveCustomerLocale(Order $order, string $fallbackLocale): string
    {
        foreach ($this->resolveCandidateCountryCodes($order) as $countryCode) {
            $resolved = self::ENGLISH_COUNTRY_LOCALES[$countryCode] ?? null;
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return $fallbackLocale;
    }

    private function resolveLanguageCode(string $locale): string
    {
        $normalized = strtolower(str_replace('_', '-', trim($locale)));
        $language = explode('-', $normalized, 2)[0] ?? '';

        return in_array($language, ['fr', 'en', 'es', 'de', 'it'], true) ? $language : 'en';
    }

    /**
     * @return list<string>
     */
    private function resolveCandidateCountryCodes(Order $order): array
    {
        $countryCodes = [];

        foreach ([$order->getShippingAddress(), $order->getBillingAddress()] as $address) {
            if ($address === null) {
                continue;
            }
            $normalized = strtoupper(trim((string) $address->getCountryId()));
            if ($normalized === '') {
                continue;
            }
            $countryCodes[] = $normalized;
        }

        return array_values(array_unique($countryCodes));
    }

    private function resolveOccurredAt(Order $order): string
    {
        $createdAt = (string) $order->getCreatedAt();
        if ($createdAt !== '') {
            $ts = strtotime($createdAt);
            if ($ts !== false) {
                return gmdate('c', $ts);
            }
        }

        return gmdate('c');
    }

    private function deterministicEventId(string $shopId, string $orderId, string $cartId): string
    {
        $hash = md5('order.completed|' . $shopId . '|' . $orderId . '|' . $cartId);
        $timeHi = sprintf(
            '%04x',
            (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x4000
        );
        $clockSeq = sprintf(
            '%04x',
            (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000
        );

        return substr($hash, 0, 8)
            . '-'
            . substr($hash, 8, 4)
            . '-'
            . $timeHi
            . '-'
            . $clockSeq
            . '-'
            . substr($hash, 20, 12);
    }

    /**
     * @return array<string, string|null>|null
     */
    private function resolveAddressPayload(Order $order): ?array
    {
        $address = $order->getShippingAddress() ?: $order->getBillingAddress();
        if (!$address) {
            return null;
        }

        $street = $address->getStreet();
        if (!is_array($street)) {
            $street = [(string) $street];
        }

        $address1 = trim((string) ($street[0] ?? ''));
        $address2 = trim((string) ($street[1] ?? ''));
        $company = trim((string) ($address->getCompany() ?: ''));
        $postcode = trim((string) ($address->getPostcode() ?: ''));
        $city = trim((string) ($address->getCity() ?: ''));
        $countryCode = trim((string) ($address->getCountryId() ?: ''));
        $phone = trim((string) ($address->getTelephone() ?: ''));

        return [
            'company' => $company !== '' ? $company : null,
            'address1' => $address1 !== '' ? $address1 : null,
            'address2' => $address2 !== '' ? $address2 : null,
            'postcode' => $postcode !== '' ? $postcode : null,
            'city' => $city !== '' ? $city : null,
            'country_code' => $countryCode !== '' ? strtoupper($countryCode) : null,
            'phone' => $phone !== '' ? $phone : null,
        ];
    }
}
