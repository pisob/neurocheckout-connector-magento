<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Event;

use Magento\Customer\Model\AddressFactory;
use Magento\Customer\Model\CustomerFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use NeuroCheckout\Connector\Model\Config;

class CartEventBuilder
{
    private const RESERVED_ITEM_KEYS = [
        'product_id' => true,
        'attribute_id' => true,
        'name' => true,
        'variant_label' => true,
        'category_path' => true,
        'brand_name' => true,
        'quantity' => true,
        'unit_price' => true,
        'line_total' => true,
        'availability' => true,
        'in_stock' => true,
        'stock' => true,
        'product_url' => true,
        'image_url' => true,
    ];

    private const EXCLUDED_PRODUCT_ATTRIBUTE_CODES = [
        'attribute_set_id' => true,
        'category_ids' => true,
        'cost' => true,
        'country_of_manufacture' => true,
        'created_at' => true,
        'custom_design' => true,
        'custom_design_from' => true,
        'custom_design_to' => true,
        'custom_layout_update' => true,
        'description' => true,
        'gallery' => true,
        'gift_message_available' => true,
        'image' => true,
        'media_gallery' => true,
        'meta_description' => true,
        'meta_keyword' => true,
        'meta_title' => true,
        'msrp' => true,
        'msrp_display_actual_price_type' => true,
        'name' => true,
        'news_from_date' => true,
        'news_to_date' => true,
        'options_container' => true,
        'price' => true,
        'price_type' => true,
        'required_options' => true,
        'shipment_type' => true,
        'short_description' => true,
        'small_image' => true,
        'special_from_date' => true,
        'special_price' => true,
        'special_to_date' => true,
        'tax_class_id' => true,
        'thumbnail' => true,
        'tier_price' => true,
        'updated_at' => true,
        'url_key' => true,
        'url_path' => true,
        'visibility' => true,
        'weight' => true,
    ];

    private const MAX_DYNAMIC_ITEM_ATTRIBUTES = 24;
    private const MAX_DYNAMIC_VALUE_LENGTH = 255;
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
    private RequestInterface $request;
    private StorefrontThemeResolver $storefrontThemeResolver;
    private ProductContextResolver $productContextResolver;
    private CustomerFactory $customerFactory;
    private AddressFactory $addressFactory;

    public function __construct(
        Config $config,
        StoreManagerInterface $storeManager,
        ScopeConfigInterface $scopeConfig,
        TimezoneInterface $timezone,
        RequestInterface $request,
        StorefrontThemeResolver $storefrontThemeResolver,
        ProductContextResolver $productContextResolver,
        CustomerFactory $customerFactory,
        AddressFactory $addressFactory
    ) {
        $this->config = $config;
        $this->storeManager = $storeManager;
        $this->scopeConfig = $scopeConfig;
        $this->timezone = $timezone;
        $this->request = $request;
        $this->storefrontThemeResolver = $storefrontThemeResolver;
        $this->productContextResolver = $productContextResolver;
        $this->customerFactory = $customerFactory;
        $this->addressFactory = $addressFactory;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildUpdated(Quote $quote): ?array
    {
        return $this->build($quote, 'cart.updated', null);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildCleared(Quote $quote, string $reason = 'cart_cleared'): ?array
    {
        return $this->build($quote, 'cart.cleared', $reason);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function build(Quote $quote, string $eventType, ?string $clearReason): ?array
    {
        $quoteId = (int) $quote->getId();
        if ($quoteId <= 0) {
            return null;
        }

        if ($eventType === 'cart.updated') {
            $this->refreshQuoteTotals($quote);
        }

        $storeId = (int) $quote->getStoreId();
        $store = $this->storeManager->getStore($storeId);

        $items = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            $product = $item->getProduct();
            $image = '';
            if ($product) {
                foreach (['image', 'small_image', 'thumbnail'] as $attributeCode) {
                    $candidate = trim((string) $product->getData($attributeCode));
                    if ($candidate !== '' && $candidate !== 'no_selection') {
                        $image = $candidate;
                        break;
                    }
                }
            }
            $imageUrl = null;
            if ($image !== '' && $image !== 'no_selection') {
                $imageUrl = rtrim($store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA), '/')
                    . '/catalog/product/'
                    . ltrim($image, '/');
            }

            $productUrl = $product ? (string)$product->getProductUrl() : null;
            $orderOptions = method_exists($item, 'getProductOrderOptions')
                ? $item->getProductOrderOptions()
                : null;
            if ($this->shouldResolveFallbackOrderOptions($orderOptions)) {
                $orderOptions = $this->resolveFallbackOrderOptions($item);
            }

            $unitPrice = round((float) $item->getPriceInclTax(), 2);
            $lineTotal = (float) $item->getRowTotalInclTax();
            if ($lineTotal <= 0 && $unitPrice > 0) {
                $lineTotal = $unitPrice * (float) $item->getQty();
            }

            $itemPayload = [
                'product_id' => (int) $item->getProductId(),
                'attribute_id' => 0,
                'name' => (string) $item->getName(),
                'variant_label' => $this->productContextResolver->buildVariantLabelFromOptions($orderOptions),
                'quantity' => (int) $item->getQty(),
                'unit_price' => $unitPrice,
                'line_total' => round($lineTotal, 2),
                'product_url' => $productUrl,
                'image_url' => $imageUrl,
            ];

            $productContext = $this->productContextResolver->resolveForCartItem(
                $item,
                $storeId,
                $productUrl,
                $imageUrl
            );

            $itemPayload['attribute_id'] = (int) ($productContext['attribute_id'] ?? 0);
            $itemPayload['category_path'] = $productContext['category_path'] ?? null;
            $itemPayload['brand_name'] = $productContext['brand_name'] ?? null;
            $itemPayload['product_url'] = $productContext['product_url'] ?? $productUrl;
            $itemPayload['image_url'] = $productContext['image_url'] ?? $imageUrl;
            $itemPayload['availability'] = $productContext['availability'] ?? 'unknown';
            $itemPayload['in_stock'] = $productContext['in_stock'] ?? null;
            $itemPayload['stock'] = $productContext['stock'] ?? null;

            foreach ($this->extractDynamicItemAttributes($item) as $key => $value) {
                $itemPayload[$key] = $value;
            }

            $items[] = $itemPayload;
        }

        if ($eventType === 'cart.updated' && !$items) {
            return null;
        }

        $shopExternalId = $this->config->getString(Config::XML_PATH_SHOP_EXTERNAL_ID, $storeId);
        if ($shopExternalId === '') {
            $shopExternalId = (string) $storeId;
        }

        $locale = (string) $this->scopeConfig->getValue(
            'general/locale/code',
            ScopeInterface::SCOPE_STORES,
            $storeId
        );
        $customerLocale = $this->resolveCustomerLocale($quote, $locale);
        $shopLanguage = $this->resolveLanguageCode($locale);
        $customerLanguage = $this->resolveLanguageCode($customerLocale);
        $primaryColor = $this->storefrontThemeResolver->resolvePrimaryColor($storeId, $locale);

        $customerEmail = trim((string)$quote->getCustomerEmail());
        $customerId = (int) $quote->getCustomerId();
        $phone = null;
        $address = $quote->getBillingAddress() ?: $quote->getShippingAddress();
        if ($address) {
            $phone = trim((string) ($address->getTelephone() ?: '')) ?: null;
        }

        $runtimeContext = [
            'shop_timezone' => (string) $this->timezone->getConfigTimezone(ScopeInterface::SCOPE_STORES, $storeId),
            'shop_local_hour' => (int) $this->timezone->date()->format('G'),
            'currency_precision' => 2,
            'shop_locale' => $locale,
            'shop_language' => $shopLanguage,
            'currency_code' => (string) $quote->getQuoteCurrencyCode(),
        ];
        if ($primaryColor !== null) {
            $runtimeContext['primary_color'] = $primaryColor;
            $runtimeContext['theme_palette'] = ['primary_color' => $primaryColor];
        }

        $quoteTotal = round((float) $quote->getGrandTotal(), 2);
        $itemsTotal = $this->calculateItemsTotal($items);
        $cartTotal = round(max($quoteTotal, $itemsTotal), 2);

        $payload = [
            'event_id' => $this->generateUuidV4(),
            'event_type' => $eventType,
            'occurred_at' => gmdate('c'),
            'language' => $customerLanguage,
            'source' => [
                'platform' => 'magento',
                'shop_id' => (string) $storeId,
                'shop_slug' => $shopExternalId,
                'shop_name' => (string) $store->getName(),
                'language' => $shopLanguage,
            ],
            'cart' => [
                'id' => (string) $quoteId,
                'uid' => $this->generateCartUid($quote, $shopExternalId),
                'total' => $cartTotal,
                'items' => $items,
            ],
            'customer' => [
                'id' => $customerId > 0 ? (string)$customerId : null,
                'email' => $customerEmail !== '' ? $customerEmail : null,
                'first_name' => $quote->getCustomerFirstname() ?: null,
                'last_name' => $quote->getCustomerLastname() ?: null,
                'locale' => $customerLocale,
                'language' => $customerLanguage,
                'is_guest' => $customerId <= 0,
                'phone' => $phone,
                'sms_opt_in' => null,
            ],
            'context' => $runtimeContext,
            'rules' => [
                'recovery_enabled' => $this->config->getBool(Config::XML_PATH_RECOVERY_ENABLED, $storeId),
                'allow_discount' => $this->config->getBool(Config::XML_PATH_ENABLE_DISCOUNT, $storeId),
                'min_cart_total' => $this->config->getFloat(Config::XML_PATH_MIN_CART_TOTAL, $storeId),
                'allow_guest' => $this->config->getBool(Config::XML_PATH_ALLOW_GUEST, $storeId),
                'no_discount_max' => $this->config->getFloat(Config::XML_PATH_NO_DISCOUNT_MAX, $storeId),
                'discount_5_min' => $this->config->getFloat(Config::XML_PATH_DISCOUNT_5_MIN, $storeId),
                'discount_5_max' => $this->config->getFloat(Config::XML_PATH_DISCOUNT_5_MAX, $storeId),
                'discount_10_min' => $this->config->getFloat(Config::XML_PATH_DISCOUNT_10_MIN, $storeId),
                'max_discount_percent' => $this->config->getFloat(Config::XML_PATH_MAX_DISCOUNT_PERCENT, $storeId),
            ],
            'meta' => [
                'session' => $this->buildSessionMeta(),
            ],
        ];

        if ($eventType === 'cart.cleared') {
            $payload['meta'] = [
                'clear_reason' => $clearReason ?: 'cart_cleared',
                'session' => $this->buildSessionMeta(),
            ];
            $payload['cart']['total'] = 0.0;
            $payload['cart']['items'] = [];
        }

        return $payload;
    }

    private function refreshQuoteTotals(Quote $quote): void
    {
        try {
            $quote->setTotalsCollectedFlag(false);
            $quote->collectTotals();
        } catch (\Throwable $e) {
            // Keep the event flow alive; the item-sum fallback below protects stale totals.
        }
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private function calculateItemsTotal(array $items): float
    {
        $total = 0.0;
        foreach ($items as $item) {
            $lineTotal = $item['line_total'] ?? null;
            if (is_numeric($lineTotal)) {
                $total += (float) $lineTotal;
                continue;
            }

            $total += (float) ($item['unit_price'] ?? 0) * (int) ($item['quantity'] ?? 0);
        }

        return round($total, 2);
    }

    private function resolveCustomerLocale(Quote $quote, string $fallbackLocale): string
    {
        foreach ($this->resolveCandidateCountryCodes($quote) as $countryCode) {
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
     * @return array<string, string|null>
     */
    private function buildSessionMeta(): array
    {
        $sourcePage = trim((string) ($this->request->getRequestUri() ?: ''));
        if ($sourcePage === '' && isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])) {
            $sourcePage = trim($_SERVER['REQUEST_URI']);
        }

        $referrer = trim((string) ($this->request->getHeader('Referer') ?: ''));
        if ($referrer === '' && isset($_SERVER['HTTP_REFERER']) && is_string($_SERVER['HTTP_REFERER'])) {
            $referrer = trim($_SERVER['HTTP_REFERER']);
        }

        $userAgent = trim((string) ($this->request->getHeader('User-Agent') ?: ''));
        if ($userAgent === '' && isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT'])) {
            $userAgent = trim($_SERVER['HTTP_USER_AGENT']);
        }

        return [
            'source_page' => $this->trimSessionValue($sourcePage),
            'referrer' => $this->trimSessionValue($referrer),
            'user_agent' => $this->trimSessionValue($userAgent),
        ];
    }

    private function trimSessionValue(string $value): ?string
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }
        if (strlen($normalized) > 1024) {
            $normalized = substr($normalized, 0, 1024);
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function resolveCandidateCountryCodes(Quote $quote): array
    {
        $countryCodes = [];

        $shippingAddress = $quote->getShippingAddress();
        if ($shippingAddress) {
            $this->appendCountryCode($countryCodes, $shippingAddress->getCountryId());
        }

        $billingAddress = $quote->getBillingAddress();
        if ($billingAddress) {
            $this->appendCountryCode($countryCodes, $billingAddress->getCountryId());
        }

        $customerId = (int) $quote->getCustomerId();
        if ($customerId <= 0) {
            return array_values(array_unique($countryCodes));
        }

        try {
            $customer = $this->customerFactory->create()->load($customerId);
            if (!$customer->getId()) {
                return array_values(array_unique($countryCodes));
            }

            foreach ([$customer->getDefaultShipping(), $customer->getDefaultBilling()] as $addressId) {
                $addressId = (int) $addressId;
                if ($addressId <= 0) {
                    continue;
                }
                $customerAddress = $this->addressFactory->create()->load($addressId);
                if (!$customerAddress->getId()) {
                    continue;
                }
                $this->appendCountryCode($countryCodes, $customerAddress->getCountryId());
            }
        } catch (\Throwable $exception) {
            return array_values(array_unique($countryCodes));
        }

        return array_values(array_unique($countryCodes));
    }

    /**
     * @param array<int, string> $countryCodes
     */
    private function appendCountryCode(array &$countryCodes, $countryCode): void
    {
        $normalized = strtoupper(trim((string) $countryCode));
        if ($normalized === '') {
            return;
        }
        $countryCodes[] = $normalized;
    }

    private function generateCartUid(Quote $quote, string $shopExternalId): string
    {
        $seed = implode('|', [
            $shopExternalId,
            (string)$quote->getId(),
            (string)$quote->getCustomerEmail(),
            (string)$quote->getCreatedAt(),
        ]);

        return hash('sha256', $seed);
    }

    private function generateUuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Keep Magento dynamic item metadata intentional and compact enough for v4 aliases.
     *
     * @return array<string, string|int|float|bool>
     */
    private function extractDynamicItemAttributes($item): array
    {
        $attributes = [];
        $orderOptions = method_exists($item, 'getProductOrderOptions')
            ? $item->getProductOrderOptions()
            : null;
        if ($this->shouldResolveFallbackOrderOptions($orderOptions)) {
            $orderOptions = $this->resolveFallbackOrderOptions($item);
        }

        if (is_array($orderOptions)) {
            foreach (['attributes_info', 'options', 'additional_options', 'bundle_options'] as $bucketKey) {
                $bucket = $orderOptions[$bucketKey] ?? null;
                if (!is_array($bucket)) {
                    continue;
                }

                foreach ($bucket as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }

                    $label = trim((string) ($entry['label'] ?? $entry['option_label'] ?? ''));
                    $value = $this->normalizeDynamicValue(
                        $entry['value'] ?? $entry['print_value'] ?? $entry['option_value'] ?? null
                    );
                    if ($label === '' || $value === null) {
                        continue;
                    }

                    $this->setDynamicAttribute($attributes, 'option_' . $label, $value);
                }
            }
        }

        $product = $item->getProduct();
        if (!$product) {
            return $attributes;
        }

        foreach ($product->getCustomAttributes() as $attribute) {
            if (!is_object($attribute) || !method_exists($attribute, 'getAttributeCode')) {
                continue;
            }

            $attributeCode = trim((string) $attribute->getAttributeCode());
            if ($attributeCode === '') {
                continue;
            }

            $resource = $product->getResource();
            $attributeModel = is_object($resource) && method_exists($resource, 'getAttribute')
                ? $resource->getAttribute($attributeCode)
                : null;

            if (!$this->shouldExposeProductAttribute($attributeCode, $attributeModel)) {
                continue;
            }

            $value = $this->resolveProductAttributeValue($product, $attributeCode, $attributeModel);
            if ($value === null) {
                continue;
            }

            $this->setDynamicAttribute($attributes, 'attr_' . $attributeCode, $value);
        }

        return $attributes;
    }

    /**
     * @return array<string, string|int|float|bool>
     */
    private function extractSelectedOptions($item): array
    {
        if (!method_exists($item, 'getProductOrderOptions')) {
            return [];
        }

        $orderOptions = $item->getProductOrderOptions();
        if ($this->shouldResolveFallbackOrderOptions($orderOptions)) {
            $orderOptions = $this->resolveFallbackOrderOptions($item);
        }
        if (!is_array($orderOptions)) {
            return [];
        }

        $attributes = [];
        foreach (['attributes_info', 'options', 'additional_options', 'bundle_options'] as $bucketKey) {
            $bucket = $orderOptions[$bucketKey] ?? null;
            if (!is_array($bucket)) {
                continue;
            }

            foreach ($bucket as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $label = trim((string) ($entry['label'] ?? $entry['option_label'] ?? ''));
                $value = $this->normalizeDynamicValue(
                    $entry['value'] ?? $entry['print_value'] ?? $entry['option_value'] ?? null
                );
                if ($label === '' || $value === null) {
                    continue;
                }

                if (count($attributes) >= self::MAX_DYNAMIC_ITEM_ATTRIBUTES) {
                    break 2;
                }

                $key = $this->normalizeDynamicKey('option_' . $label);
                if ($key === '' || isset(self::RESERVED_ITEM_KEYS[$key]) || array_key_exists($key, $attributes)) {
                    continue;
                }

                $attributes[$key] = $value;
            }
        }

        return $attributes;
    }

    private function shouldResolveFallbackOrderOptions($orderOptions): bool
    {
        if (!is_array($orderOptions)) {
            return true;
        }

        foreach (['attributes_info', 'options', 'additional_options', 'bundle_options'] as $bucketKey) {
            if (!empty($orderOptions[$bucketKey]) && is_array($orderOptions[$bucketKey])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Quote items with custom options may not hydrate product_order_options directly.
     *
     * @return array<string, mixed>|null
     */
    private function resolveFallbackOrderOptions($item): ?array
    {
        $resolvedFromQuoteItem = $this->resolveOrderOptionsFromQuoteItem($item);
        if (is_array($resolvedFromQuoteItem)) {
            return $resolvedFromQuoteItem;
        }

        if (!method_exists($item, 'getProduct')) {
            return null;
        }

        $product = $item->getProduct();
        if (!is_object($product) || !method_exists($product, 'getTypeInstance')) {
            return null;
        }

        try {
            $typeInstance = $product->getTypeInstance(true);
            if (!is_object($typeInstance) || !method_exists($typeInstance, 'getOrderOptions')) {
                return null;
            }

            $resolved = $typeInstance->getOrderOptions($product);

            return is_array($resolved) ? $resolved : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Rebuild custom option selections from quote item option_* rows when Magento
     * does not hydrate product_order_options on the quote item itself.
     *
     * @return array<string, mixed>|null
     */
    private function resolveOrderOptionsFromQuoteItem($item): ?array
    {
        if (!method_exists($item, 'getOptions') || !method_exists($item, 'getProduct')) {
            return null;
        }

        $selectedValuesByOptionId = [];
        foreach ($item->getOptions() ?: [] as $option) {
            if (!is_object($option) || !method_exists($option, 'getCode') || !method_exists($option, 'getValue')) {
                continue;
            }

            $code = (string) $option->getCode();
            if (strpos($code, 'option_') !== 0) {
                continue;
            }

            $optionId = (int) substr($code, 7);
            if ($optionId <= 0) {
                continue;
            }

            $selectedValuesByOptionId[$optionId] = (string) $option->getValue();
        }

        if (!$selectedValuesByOptionId) {
            return null;
        }

        $product = $item->getProduct();
        if (!is_object($product) || !method_exists($product, 'getOptions')) {
            return null;
        }

        $entries = [];
        foreach ($product->getOptions() ?: [] as $productOption) {
            if (!is_object($productOption) || !method_exists($productOption, 'getOptionId')) {
                continue;
            }

            $optionId = (int) $productOption->getOptionId();
            $rawValue = $selectedValuesByOptionId[$optionId] ?? null;
            if ($optionId <= 0 || $rawValue === null) {
                continue;
            }

            $label = method_exists($productOption, 'getTitle')
                ? trim((string) $productOption->getTitle())
                : '';
            $resolvedValue = $this->resolveSelectedCustomOptionTitles($productOption, $rawValue) ?? trim($rawValue);
            if ($label === '' || $resolvedValue === '') {
                continue;
            }

            $entries[] = [
                'label' => $label,
                'value' => $resolvedValue,
                'print_value' => $resolvedValue,
                'option_id' => (string) $optionId,
                'option_type' => method_exists($productOption, 'getType')
                    ? (string) $productOption->getType()
                    : null,
            ];
        }

        if (!$entries) {
            return null;
        }

        return ['options' => $entries];
    }

    private function resolveSelectedCustomOptionTitles($productOption, string $rawValue): ?string
    {
        if (!method_exists($productOption, 'getValues')) {
            return null;
        }

        $selectedIds = array_values(array_filter(array_map('trim', explode(',', $rawValue)), static function ($value) {
            return $value !== '';
        }));
        if (!$selectedIds) {
            return null;
        }

        $titles = [];
        foreach ($productOption->getValues() ?: [] as $optionValue) {
            if (!is_object($optionValue) || !method_exists($optionValue, 'getOptionTypeId')) {
                continue;
            }

            $optionTypeId = (string) $optionValue->getOptionTypeId();
            if (!in_array($optionTypeId, $selectedIds, true)) {
                continue;
            }

            $title = method_exists($optionValue, 'getTitle')
                ? trim((string) $optionValue->getTitle())
                : '';
            if ($title !== '') {
                $titles[] = $title;
            }
        }

        if (!$titles) {
            return null;
        }

        return implode(' | ', array_values(array_unique($titles)));
    }

    private function shouldExposeProductAttribute(string $attributeCode, $attributeModel): bool
    {
        $normalizedCode = strtolower(trim($attributeCode));
        if ($normalizedCode === '' || isset(self::EXCLUDED_PRODUCT_ATTRIBUTE_CODES[$normalizedCode])) {
            return false;
        }

        if (!is_object($attributeModel)) {
            return false;
        }

        $isUserDefined = method_exists($attributeModel, 'getIsUserDefined')
            ? (bool) $attributeModel->getIsUserDefined()
            : false;
        $visibleOnFront = method_exists($attributeModel, 'getIsVisibleOnFront')
            ? (bool) $attributeModel->getIsVisibleOnFront()
            : false;
        $usedInListing = method_exists($attributeModel, 'getUsedInProductListing')
            ? (bool) $attributeModel->getUsedInProductListing()
            : false;

        if (!$isUserDefined && !$visibleOnFront && !$usedInListing) {
            return false;
        }

        $frontendInput = method_exists($attributeModel, 'getFrontendInput')
            ? strtolower(trim((string) $attributeModel->getFrontendInput()))
            : '';

        return !in_array($frontendInput, ['gallery', 'image', 'media_image'], true);
    }

    /**
     * @return string|int|float|bool|null
     */
    private function resolveProductAttributeValue($product, string $attributeCode, $attributeModel)
    {
        if (is_object($attributeModel) && method_exists($attributeModel, 'getFrontend')) {
            try {
                $frontend = $attributeModel->getFrontend();
                if (is_object($frontend) && method_exists($frontend, 'getValue')) {
                    $resolved = $this->normalizeDynamicValue($frontend->getValue($product));
                    if ($resolved !== null) {
                        return $resolved;
                    }
                }
            } catch (\Throwable $e) {
                // Fall back to raw product data when frontend value resolution is not available.
            }
        }

        return $this->normalizeDynamicValue($product->getData($attributeCode));
    }

    /**
     * @param array<string, string|int|float|bool> $attributes
     * @param string|int|float|bool $value
     */
    private function setDynamicAttribute(array &$attributes, string $rawKey, $value): void
    {
        if (count($attributes) >= self::MAX_DYNAMIC_ITEM_ATTRIBUTES) {
            return;
        }

        $key = $this->normalizeDynamicKey($rawKey);
        if ($key === '' || isset(self::RESERVED_ITEM_KEYS[$key]) || array_key_exists($key, $attributes)) {
            return;
        }

        $attributes[$key] = $value;
    }

    private function normalizeDynamicKey(string $rawKey): string
    {
        $normalized = strtolower(trim($rawKey));
        if ($normalized === '') {
            return '';
        }

        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized) ?? '';
        $normalized = trim($normalized, '_');

        return $normalized;
    }

    /**
     * @return string|int|float|bool|null
     */
    private function normalizeDynamicValue($value)
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = trim(preg_replace('/\s+/', ' ', $value) ?? '');
            if ($normalized === '' || $normalized === '-- Please Select --') {
                return null;
            }

            if (strlen($normalized) > self::MAX_DYNAMIC_VALUE_LENGTH) {
                return substr($normalized, 0, self::MAX_DYNAMIC_VALUE_LENGTH);
            }

            return $normalized;
        }

        if (!is_array($value)) {
            return null;
        }

        $flattened = [];
        foreach ($value as $entry) {
            $normalized = $this->normalizeDynamicValue(
                is_array($entry)
                    ? ($entry['value'] ?? $entry['label'] ?? $entry['title'] ?? null)
                    : $entry
            );
            if ($normalized === null) {
                continue;
            }
            $flattened[] = (string) $normalized;
        }

        if (!$flattened) {
            return null;
        }

        $joined = implode(' | ', array_unique($flattened));
        if (strlen($joined) > self::MAX_DYNAMIC_VALUE_LENGTH) {
            return substr($joined, 0, self::MAX_DYNAMIC_VALUE_LENGTH);
        }

        return $joined;
    }
}
