<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Webapi;

use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable as ConfigurableType;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\DataObject;
use Magento\Framework\UrlInterface;
use Magento\Framework\Webapi\Exception;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\ResourceModel\Quote as QuoteResource;
use Magento\Store\Model\StoreManagerInterface;
use NeuroCheckout\Connector\Api\CartRestoreManagementInterface;
use NeuroCheckout\Connector\Model\Security\CartFingerprintService;
use NeuroCheckout\Connector\Model\Security\RecoveryLinkService;
use NeuroCheckout\Connector\Model\Security\RequestSecurityValidator;
use Psr\Log\LoggerInterface;

class CartRestoreManagement extends AbstractEndpoint implements CartRestoreManagementInterface
{
    private RequestSecurityValidator $securityValidator;
    private CartRepositoryInterface $quoteRepository;
    private QuoteResource $quoteResource;
    private QuoteFactory $quoteFactory;
    private ProductRepositoryInterface $productRepository;
    private CustomerRepositoryInterface $customerRepository;
    private AddressRepositoryInterface $addressRepository;
    private ConfigurableType $configurableType;
    private ResourceConnection $resource;
    private State $appState;
    private CartFingerprintService $cartFingerprintService;
    private RecoveryLinkService $recoveryLinkService;
    private LoggerInterface $logger;

    public function __construct(
        Request $request,
        StoreManagerInterface $storeManager,
        RequestSecurityValidator $securityValidator,
        CartRepositoryInterface $quoteRepository,
        QuoteResource $quoteResource,
        QuoteFactory $quoteFactory,
        ProductRepositoryInterface $productRepository,
        CustomerRepositoryInterface $customerRepository,
        AddressRepositoryInterface $addressRepository,
        ConfigurableType $configurableType,
        ResourceConnection $resource,
        State $appState,
        CartFingerprintService $cartFingerprintService,
        RecoveryLinkService $recoveryLinkService,
        LoggerInterface $logger
    ) {
        parent::__construct($request, $storeManager);
        $this->securityValidator = $securityValidator;
        $this->quoteRepository = $quoteRepository;
        $this->quoteResource = $quoteResource;
        $this->quoteFactory = $quoteFactory;
        $this->productRepository = $productRepository;
        $this->customerRepository = $customerRepository;
        $this->addressRepository = $addressRepository;
        $this->configurableType = $configurableType;
        $this->resource = $resource;
        $this->appState = $appState;
        $this->cartFingerprintService = $cartFingerprintService;
        $this->recoveryLinkService = $recoveryLinkService;
        $this->logger = $logger;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function execute(array $payload = []): array
    {
        try {
            $payload = $this->normalizePayload($payload);
            $storeId = $this->resolveStoreId($payload);
            $security = $this->securityValidator->validate($this->getRawBody(), $storeId, 'cartrestore', false);
            if (empty($security['success'])) {
                return $this->response(false, (int)($security['status'] ?? 403), (string)($security['error'] ?? 'forbidden'));
            }

            $cartId = (int)($payload['cart_id'] ?? 0);
            $cartUid = trim((string)($payload['cart_uid'] ?? ''));
            $customerEmail = trim((string)($payload['customer_email'] ?? ''));
            $customerId = (int)($payload['customer_id'] ?? 0);
            $targetUrl = trim((string)($payload['target_url'] ?? ''));
            $products = $this->normalizeProducts($payload['products'] ?? []);
            $sessionOnly = $this->isTruthy($payload['session_only'] ?? false);

            if ($sessionOnly) {
                if ($customerEmail === '' || $targetUrl === '') {
                    return $this->response(false, 422, 'Missing required fields');
                }

                $resolvedCustomerId = $this->resolveCustomerId($storeId, $customerId, $customerEmail);
                if ($resolvedCustomerId <= 0 || !$this->customerEmailMatches($resolvedCustomerId, $customerEmail)) {
                    return $this->response(false, 422, 'Customer not found');
                }

                $recoveryUrl = $this->recoveryLinkService->buildCustomerSession(
                    $storeId,
                    $resolvedCustomerId,
                    $customerEmail,
                    $targetUrl
                );

                return $this->response(true, 200, null, [
                    'recovery_url' => $recoveryUrl,
                    'restored_cart_id' => null,
                    'cart_uid' => $cartUid,
                    'recreated' => false,
                    'products_added' => 0,
                    'coupon_preserved' => false,
                    'session_only' => true,
                ]);
            }

            if ($customerEmail === '' || !$products) {
                return $this->response(false, 422, 'Missing required fields');
            }

            $resolved = $this->resolveRecoverableQuote($storeId, $cartId, $customerEmail, $customerId, $products);
            /** @var Quote $quote */
            $quote = $resolved['quote'];
            $recreated = (bool)$resolved['recreated'];
            $productsAdded = (int)$resolved['products_added'];
            $cartFingerprint = $this->cartFingerprintService->fromQuote($quote);

            $couponCode = $this->extractCouponCodeFromTarget($targetUrl);
            $couponPreserved = false;
            if ($couponCode !== '') {
                if ($recreated) {
                    $couponPreserved = $this->cloneCouponMetaForRecoveredCart(
                        $storeId,
                        $couponCode,
                        $customerEmail,
                        (string)$quote->getId(),
                        '',
                        $cartFingerprint
                    );
                    if (!$couponPreserved) {
                        $couponCode = '';
                    }
                } else {
                    $couponPreserved = $this->hasCouponMetaForCart(
                        $storeId,
                        (string) $quote->getId(),
                        $couponCode,
                        $customerEmail,
                        $cartFingerprint
                    );
                    if (!$couponPreserved) {
                        $couponCode = '';
                    }
                }
            }

            if ($couponCode !== '') {
                $quote->setCouponCode($couponCode);
                $quote->collectTotals();
                $this->persistQuote($quote);
            }

            $recoveryUrl = $this->recoveryLinkService->build(
                $storeId,
                (string)$quote->getId(),
                $customerEmail,
                $couponCode,
                $cartFingerprint
            );
            $recoveryUrl = $this->appendTargetUrlToRecoveryUrl($storeId, $recoveryUrl, $targetUrl);
            if ($couponPreserved && $couponCode !== '') {
                $this->updateRecoveredCouponMetaUrl($storeId, $couponCode, (string)$quote->getId(), $recoveryUrl, $cartFingerprint);
            }

            return $this->response(true, 200, null, [
                'recovery_url' => $recoveryUrl,
                'restored_cart_id' => (string)$quote->getId(),
                'cart_uid' => $cartUid,
                'recreated' => $recreated,
                'products_added' => $productsAdded,
                'coupon_preserved' => $couponPreserved,
            ]);
        } catch (Exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('[NC] Cart restore endpoint error: ' . $e->getMessage());
            return $this->response(false, 500, 'Internal error');
        }
    }

    /**
     * @param mixed $products
     * @return array<int, array<string, int>>
     */
    private function normalizeProducts($products): array
    {
        if (!is_array($products)) {
            return [];
        }

        $normalized = [];
        foreach ($products as $item) {
            if (!is_array($item)) {
                continue;
            }

            $productId = (int)($item['product_id'] ?? $item['id_product'] ?? 0);
            $attributeId = (int)($item['attribute_id'] ?? $item['id_product_attribute'] ?? 0);
            $quantity = (int)($item['quantity'] ?? $item['cart_quantity'] ?? 0);
            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }

            $normalized[] = [
                'product_id' => $productId,
                'attribute_id' => max(0, $attributeId),
                'quantity' => $quantity,
            ];
        }

        return $normalized;
    }

    /**
     * @param mixed $value
     */
    private function isTruthy($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param array<int, array<string, int>> $products
     * @return array<string, mixed>
     */
    private function resolveRecoverableQuote(
        int $storeId,
        int $sourceCartId,
        string $customerEmail,
        int $customerId,
        array $products
    ): array {
        $existing = $this->tryLoadExistingQuote($sourceCartId, $customerEmail);
        if ($existing !== null) {
            return [
                'quote' => $existing,
                'recreated' => false,
                'products_added' => 0,
            ];
        }

        $quote = $this->createQuoteSkeleton($storeId, $customerEmail, $customerId);
        $productsAdded = 0;

        foreach ($products as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            $qty = (int)($item['quantity'] ?? 0);
            if ($productId <= 0 || $qty <= 0) {
                continue;
            }

            $productsAdded += $this->addProductToQuote($quote, $productId, (int) ($item['attribute_id'] ?? 0), $qty, $storeId);
        }

        $quote->collectTotals();
        $this->persistQuote($quote);

        if ((int)$quote->getItemsCount() <= 0) {
            throw new \RuntimeException('Cart recreation produced an empty cart');
        }

        return [
            'quote' => $quote,
            'recreated' => true,
            'products_added' => $productsAdded,
        ];
    }

    private function tryLoadExistingQuote(int $cartId, string $customerEmail): ?Quote
    {
        if ($cartId <= 0) {
            return null;
        }

        try {
            $quote = $this->quoteRepository->get($cartId);
        } catch (\Throwable $e) {
            $quote = $this->quoteFactory->create()->load($cartId);
        }

        if (!$quote || !(int)$quote->getId()) {
            return null;
        }

        if ((int)$quote->getItemsCount() <= 0) {
            return null;
        }

        if (!$this->emailMatchesQuote($quote, $customerEmail)) {
            return null;
        }

        return $quote;
    }

    private function createQuoteSkeleton(int $storeId, string $customerEmail, int $customerId): Quote
    {
        $resolvedCustomerId = $this->resolveCustomerId($storeId, $customerId, $customerEmail);
        $quote = $this->quoteFactory->create();
        $quote->setStoreId($storeId);
        $quote->setIsActive(true);
        $quote->setCustomerEmail($customerEmail);

        if ($resolvedCustomerId > 0) {
            try {
                $customer = $this->customerRepository->getById($resolvedCustomerId);
                $quote->assignCustomer($customer);
                $quote->setCheckoutMethod('customer');
                $quote->setCustomerIsGuest(false);
                if (trim((string) $customer->getEmail()) !== '') {
                    $quote->setCustomerEmail((string) $customer->getEmail());
                }
                $this->hydrateCustomerAddresses($quote, $resolvedCustomerId);
            } catch (\Throwable $e) {
                $quote->setCustomerIsGuest(true);
                $quote->setCheckoutMethod('guest');
            }
        } else {
            $quote->setCustomerIsGuest(true);
            $quote->setCheckoutMethod('guest');
        }

        return $quote;
    }

    private function resolveCustomerId(int $storeId, int $customerId, string $customerEmail): int
    {
        if ($customerId > 0) {
            try {
                $candidate = $this->customerRepository->getById($customerId);
                if ((int) $candidate->getId() > 0) {
                    return (int) $candidate->getId();
                }
            } catch (\Throwable $e) {
            }
        }

        $normalizedEmail = strtolower(trim($customerEmail));
        if ($normalizedEmail === '') {
            return 0;
        }

        try {
            $websiteId = (int) $this->storeManager->getStore($storeId)->getWebsiteId();
            $customer = $this->customerRepository->get($normalizedEmail, $websiteId);
            return (int) $customer->getId();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function customerEmailMatches(int $customerId, string $customerEmail): bool
    {
        if ($customerId <= 0) {
            return false;
        }

        $normalizedEmail = strtolower(trim($customerEmail));
        if ($normalizedEmail === '') {
            return false;
        }

        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (\Throwable $e) {
            return false;
        }

        return hash_equals($normalizedEmail, strtolower(trim((string) $customer->getEmail())));
    }

    private function hydrateCustomerAddresses(Quote $quote, int $customerId): void
    {
        if ($customerId <= 0) {
            return;
        }

        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (\Throwable $e) {
            return;
        }

        $shippingAddressId = (int) ($customer->getDefaultShipping() ?: 0);
        $billingAddressId = (int) ($customer->getDefaultBilling() ?: 0);

        if ($shippingAddressId <= 0) {
            $shippingAddressId = $billingAddressId;
        }
        if ($billingAddressId <= 0) {
            $billingAddressId = $shippingAddressId;
        }

        if ($shippingAddressId > 0) {
            try {
                $shippingAddress = $this->addressRepository->getById($shippingAddressId);
                $quote->getShippingAddress()->importCustomerAddressData($shippingAddress);
                $quote->getShippingAddress()->setCollectShippingRates(true);
            } catch (\Throwable $e) {
            }
        }

        if ($billingAddressId > 0) {
            try {
                $billingAddress = $this->addressRepository->getById($billingAddressId);
                $quote->getBillingAddress()->importCustomerAddressData($billingAddress);
            } catch (\Throwable $e) {
            }
        }
    }

    private function emailMatchesQuote(Quote $quote, string $customerEmail): bool
    {
        $normalizedEmail = strtolower(trim($customerEmail));
        if ($normalizedEmail === '') {
            return false;
        }

        $quoteEmail = strtolower(trim((string) $quote->getCustomerEmail()));
        if ($quoteEmail !== '') {
            return hash_equals($quoteEmail, $normalizedEmail);
        }

        $customerId = (int) $quote->getCustomerId();
        if ($customerId <= 0) {
            return true;
        }

        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (\Throwable $e) {
            return false;
        }

        return hash_equals($normalizedEmail, strtolower(trim((string) $customer->getEmail())));
    }

    private function addProductToQuote(
        Quote $quote,
        int $productId,
        int $attributeId,
        int $qty,
        int $storeId
    ): int {
        if ($productId <= 0 || $qty <= 0) {
            return 0;
        }

        $product = $this->productRepository->getById($productId, false, $storeId, true);

        if ($attributeId > 0) {
            $configurablePayload = $this->buildConfigurableAddPayload($product, $attributeId, $qty, $storeId);
            if (is_array($configurablePayload)) {
                $result = $this->runInFrontendArea(
                    static function () use ($quote, $configurablePayload) {
                        return $quote->addProduct($configurablePayload['product'], $configurablePayload['request']);
                    }
                );
                if (is_string($result)) {
                    throw new \RuntimeException($result);
                }
                return $qty;
            }

            if ($attributeId !== $productId) {
                try {
                    $variantProduct = $this->productRepository->getById($attributeId, false, $storeId, true);
                    $result = $this->runInFrontendArea(
                        static function () use ($quote, $variantProduct, $qty) {
                            return $quote->addProduct($variantProduct, new DataObject(['qty' => $qty]));
                        }
                    );
                    if (!is_string($result)) {
                        return $qty;
                    }
                } catch (\Throwable $e) {
                }
            }
        }

        $result = $this->runInFrontendArea(
            static function () use ($quote, $product, $qty) {
                return $quote->addProduct($product, new DataObject(['qty' => $qty]));
            }
        );
        if (is_string($result)) {
            throw new \RuntimeException($result);
        }

        return $qty;
    }

    /**
     * @return array{product: object, request: DataObject}|null
     */
    private function buildConfigurableAddPayload($product, int $variantProductId, int $qty, int $storeId): ?array
    {
        if (!is_object($product) || !method_exists($product, 'getTypeId')) {
            return null;
        }

        $parentProduct = $product;
        if ((string) $parentProduct->getTypeId() !== 'configurable') {
            $parentIds = $this->configurableType->getParentIdsByChild($variantProductId);
            if (!$parentIds) {
                return null;
            }

            $parentProduct = $this->productRepository->getById((int) $parentIds[0], false, $storeId, true);
            if (!is_object($parentProduct) || !method_exists($parentProduct, 'getTypeId') || (string) $parentProduct->getTypeId() !== 'configurable') {
                return null;
            }
        }

        try {
            $variantProduct = $this->productRepository->getById($variantProductId, false, $storeId, true);
        } catch (\Throwable $e) {
            return null;
        }

        $superAttributes = $this->buildSuperAttributeMap($parentProduct, $variantProduct);
        if ($superAttributes === []) {
            return null;
        }

        return [
            'product' => $parentProduct,
            'request' => new DataObject([
                'product' => (int) $parentProduct->getId(),
                'item' => (int) $parentProduct->getId(),
                'selected_configurable_option' => $variantProductId,
                'super_attribute' => $superAttributes,
                'qty' => $qty,
            ]),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function buildSuperAttributeMap($parentProduct, $variantProduct): array
    {
        if (!is_object($parentProduct) || !is_object($variantProduct)) {
            return [];
        }

        try {
            $configurableAttributes = $parentProduct->getTypeInstance()->getConfigurableAttributes($parentProduct);
        } catch (\Throwable $e) {
            return [];
        }

        if (!is_iterable($configurableAttributes)) {
            return [];
        }

        $map = [];
        foreach ($configurableAttributes as $attribute) {
            if (!is_object($attribute)) {
                continue;
            }

            $productAttribute = $attribute->getProductAttribute();
            if (!is_object($productAttribute)) {
                continue;
            }

            $attributeId = (int) ($productAttribute->getAttributeId() ?: $productAttribute->getData('attribute_id'));
            $attributeCode = trim((string) ($productAttribute->getAttributeCode() ?: $productAttribute->getData('attribute_code')));
            if ($attributeId <= 0 || $attributeCode === '') {
                continue;
            }

            $value = $variantProduct->getData($attributeCode);
            if (is_array($value) || is_object($value) || $value === null) {
                continue;
            }

            $normalizedValue = trim((string) $value);
            if ($normalizedValue === '') {
                continue;
            }

            $map[$attributeId] = ctype_digit($normalizedValue) ? (int) $normalizedValue : $normalizedValue;
        }

        return $map;
    }

    private function extractCouponCodeFromTarget(string $targetUrl): string
    {
        if ($targetUrl === '') {
            return '';
        }

        $parts = parse_url($targetUrl);
        if (!is_array($parts)) {
            return '';
        }

        $query = [];
        if (!empty($parts['query'])) {
            parse_str((string)$parts['query'], $query);
        }

        foreach (['coupon', 'coupon_code', 'nc_coupon'] as $key) {
            $value = trim((string)($query[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        if (preg_match('/NC-[A-Z0-9-]+/i', $targetUrl, $matches) === 1) {
            return strtoupper((string)$matches[0]);
        }

        return '';
    }

    private function appendTargetUrlToRecoveryUrl(int $storeId, string $recoveryUrl, string $targetUrl): string
    {
        $safeTargetUrl = $this->sanitizeSameStoreTargetUrl($storeId, $targetUrl);
        if ($safeTargetUrl === '') {
            return $recoveryUrl;
        }

        $separator = strpos($recoveryUrl, '?') === false ? '?' : '&';
        return $recoveryUrl . $separator . http_build_query(['u' => $safeTargetUrl]);
    }

    private function sanitizeSameStoreTargetUrl(int $storeId, string $targetUrl): string
    {
        $candidate = trim($targetUrl);
        if ($candidate === '') {
            return '';
        }

        $parts = parse_url($candidate);
        if (!is_array($parts)) {
            return '';
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $targetHost = strtolower((string)($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $targetHost === '') {
            return '';
        }

        try {
            $store = $this->storeManager->getStore($storeId);
            $baseUrl = (string)$store->getBaseUrl(UrlInterface::URL_TYPE_WEB);
        } catch (\Throwable $e) {
            return '';
        }

        $baseParts = parse_url($baseUrl);
        $baseHost = strtolower((string)($baseParts['host'] ?? ''));

        return ($baseHost !== '' && hash_equals($baseHost, $targetHost)) ? $candidate : '';
    }

    private function cloneCouponMetaForRecoveredCart(
        int $storeId,
        string $couponCode,
        string $customerEmail,
        string $newCartId,
        string $recoveryUrl,
        string $cartFingerprint = ''
    ): bool
    {
        $code = trim($couponCode);
        $email = strtolower(trim($customerEmail));
        if ($code === '' || $email === '' || $newCartId === '') {
            return false;
        }

        $table = $this->resource->getTableName('neurocheckout_coupon');
        $connection = $this->resource->getConnection();

        $existingId = (int) $connection->fetchOne(
            $connection->select()
                ->from($table, ['id'])
                ->where('store_id = ?', max(0, $storeId))
                ->where('cart_id = ?', $newCartId)
                ->where('coupon_code = ?', $code)
                ->limit(1)
        );

        if ($existingId > 0) {
            $connection->update(
                $table,
                [
                    'recovery_url' => $recoveryUrl,
                    'cart_fingerprint' => $cartFingerprint !== '' ? $cartFingerprint : null,
                ],
                ['id = ?' => $existingId]
            );
            return true;
        }

        $row = $connection->fetchRow(
            $connection->select()
                ->from($table)
                ->where('store_id = ?', max(0, $storeId))
                ->where('coupon_code = ?', $code)
                ->where('LOWER(customer_email) = ?', $email)
                ->order('id DESC')
                ->limit(1)
        );

        if (!is_array($row)) {
            return false;
        }

        $expiresAt = trim((string) ($row['expires_at'] ?? ''));
        if ($expiresAt !== '' && strtotime($expiresAt) < time()) {
            return false;
        }

        $connection->insert($table, [
            'store_id' => max(0, $storeId),
            'request_uid' => 'restore:' . sha1($code . '|' . $newCartId . '|' . $email . '|' . microtime(true)),
            'decision_id' => (string) ($row['decision_id'] ?? 'restore'),
            'action_id' => (string) ($row['action_id'] ?? 'restore'),
            'cart_id' => $newCartId,
            'customer_email' => $email,
            'rule_id' => (int) ($row['rule_id'] ?? 0),
            'coupon_code' => $code,
            'discount_percent' => round((float) ($row['discount_percent'] ?? 0), 2),
            'cart_fingerprint' => $cartFingerprint !== '' ? $cartFingerprint : null,
            'recovery_url' => $recoveryUrl,
            'expires_at' => $expiresAt !== '' ? $expiresAt : gmdate('Y-m-d H:i:s', time() + 3600),
        ]);

        return true;
    }

    private function updateRecoveredCouponMetaUrl(
        int $storeId,
        string $couponCode,
        string $cartId,
        string $recoveryUrl,
        string $cartFingerprint = ''
    ): void
    {
        if ($couponCode === '' || $cartId === '' || $recoveryUrl === '') {
            return;
        }

        $table = $this->resource->getTableName('neurocheckout_coupon');
        $connection = $this->resource->getConnection();
        $connection->update(
            $table,
            [
                'recovery_url' => $recoveryUrl,
                'cart_fingerprint' => $cartFingerprint !== '' ? $cartFingerprint : null,
            ],
            [
                'store_id = ?' => max(0, $storeId),
                'cart_id = ?' => $cartId,
                'coupon_code = ?' => $couponCode,
            ]
        );
    }

    private function hasCouponMetaForCart(
        int $storeId,
        string $cartId,
        string $couponCode,
        string $customerEmail,
        string $cartFingerprint = ''
    ): bool
    {
        if ($cartId === '' || $couponCode === '' || $customerEmail === '') {
            return false;
        }

        $table = $this->resource->getTableName('neurocheckout_coupon');
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($table, ['expires_at', 'cart_fingerprint'])
                ->where('store_id = ?', max(0, $storeId))
                ->where('cart_id = ?', $cartId)
                ->where('coupon_code = ?', $couponCode)
                ->where('LOWER(customer_email) = ?', strtolower(trim($customerEmail)))
                ->order('id DESC')
                ->limit(1)
        );

        if (!is_array($row)) {
            return false;
        }

        $expiresAt = trim((string) ($row['expires_at'] ?? ''));
        if ($expiresAt !== '' && strtotime($expiresAt) < time()) {
            return false;
        }

        $storedFingerprint = trim((string) ($row['cart_fingerprint'] ?? ''));
        if ($storedFingerprint !== '' && $cartFingerprint !== '' && !hash_equals($storedFingerprint, $cartFingerprint)) {
            return false;
        }

        return true;
    }

    private function persistQuote(Quote $quote): void
    {
        $this->quoteResource->save($quote);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function runInFrontendArea(callable $callback)
    {
        return $this->appState->emulateAreaCode('frontend', $callback);
    }
}
