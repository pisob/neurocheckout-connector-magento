<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Security;

use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use NeuroCheckout\Connector\Model\Config;
use NeuroCheckout\Connector\Model\Repository\RecoveryTokenRepository;

class RecoveryLinkService
{
    public const LINK_TTL_SECONDS = 604800;

    private StoreManagerInterface $storeManager;
    private Config $config;
    private RecoveryTokenRepository $recoveryTokenRepository;

    public function __construct(
        StoreManagerInterface $storeManager,
        Config $config,
        RecoveryTokenRepository $recoveryTokenRepository
    ) {
        $this->storeManager = $storeManager;
        $this->config = $config;
        $this->recoveryTokenRepository = $recoveryTokenRepository;
    }

    public function build(
        int $storeId,
        string $cartId,
        string $customerEmail,
        string $couponCode = '',
        ?string $cartFingerprint = null
    ): string
    {
        $store = $this->storeManager->getStore($storeId);
        $normalizedFingerprint = trim((string) $cartFingerprint);

        if ($this->config->isOpaqueRecoveryLinksEnabled($storeId)) {
            $token = $this->recoveryTokenRepository->issue(
                $storeId,
                $cartId,
                $customerEmail,
                $couponCode,
                self::LINK_TTL_SECONDS,
                $normalizedFingerprint
            );
            if ($token !== null && $token !== '') {
                return rtrim($store->getBaseUrl(UrlInterface::URL_TYPE_WEB), '/') . '/neurocheckout/recover/index?' . http_build_query([
                    'rt' => $token,
                    'store_id' => $storeId,
                    '___store' => $store->getCode(),
                ]);
            }
        }

        $secret = $this->config->getOrCreateInternalSecret($storeId);
        if ($secret === '') {
            throw new \RuntimeException('Internal secret missing');
        }

        return $this->buildLegacyUrl($storeId, $cartId, $customerEmail, $couponCode, $secret, $normalizedFingerprint);
    }

    public function buildCustomerSession(
        int $storeId,
        int $customerId,
        string $customerEmail,
        string $targetUrl
    ): string {
        $store = $this->storeManager->getStore($storeId);
        $token = $this->recoveryTokenRepository->issueCustomerSession(
            $storeId,
            $customerId,
            $customerEmail,
            $targetUrl,
            self::LINK_TTL_SECONDS
        );

        if ($token === null || $token === '') {
            throw new \RuntimeException('Unable to issue customer session recovery token');
        }

        return rtrim($store->getBaseUrl(UrlInterface::URL_TYPE_WEB), '/') . '/neurocheckout/recover/index?' . http_build_query([
            'rt' => $token,
            'store_id' => $storeId,
            '___store' => $store->getCode(),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolveOpaque(string $token): ?array
    {
        return $this->recoveryTokenRepository->resolveUsable($token);
    }

    public function consumeOpaque(string $token): bool
    {
        return $this->recoveryTokenRepository->consume($token);
    }

    public function isValid(
        int $storeId,
        int $cartId,
        string $customerEmail,
        string $couponCode,
        int $timestamp,
        string $signature,
        string $cartFingerprint = ''
    ): bool
    {
        if ($storeId <= 0 || $cartId <= 0 || $customerEmail === '' || $timestamp <= 0 || $signature === '') {
            return false;
        }

        if (abs(time() - $timestamp) > self::LINK_TTL_SECONDS) {
            return false;
        }

        $secret = $this->config->getString(Config::XML_PATH_INTERNAL_SECRET, $storeId);
        if ($secret === '') {
            return false;
        }

        $normalizedEmail = strtolower(trim($customerEmail));
        $normalizedFingerprint = trim($cartFingerprint);
        $payload = $cartId . ':' . $normalizedEmail . ':' . $couponCode . ':' . $timestamp . ':' . $normalizedFingerprint;
        $expectedSignature = hash_hmac('sha256', $payload, $secret);
        if (hash_equals($expectedSignature, $signature)) {
            return true;
        }

        if ($normalizedFingerprint !== '') {
            return false;
        }

        $legacyPayload = $cartId . ':' . $normalizedEmail . ':' . $couponCode . ':' . $timestamp;
        $legacySignature = hash_hmac('sha256', $legacyPayload, $secret);

        return hash_equals($legacySignature, $signature);
    }

    public function buildCartUrl(int $storeId): string
    {
        $store = $this->storeManager->getStore($storeId);
        return rtrim($store->getBaseUrl(UrlInterface::URL_TYPE_WEB), '/') . '/checkout/cart/';
    }

    private function buildLegacyUrl(
        int $storeId,
        string $cartId,
        string $customerEmail,
        string $couponCode,
        string $secret,
        string $cartFingerprint = ''
    ): string {
        $store = $this->storeManager->getStore($storeId);
        $timestamp = time();
        $normalizedEmail = strtolower(trim($customerEmail));
        $normalizedFingerprint = trim($cartFingerprint);
        $payload = $cartId . ':' . $normalizedEmail . ':' . $couponCode . ':' . $timestamp . ':' . $normalizedFingerprint;
        $signature = hash_hmac('sha256', $payload, $secret);

        $query = [
            'cart_id' => $cartId,
            'email' => $customerEmail,
            'ts' => $timestamp,
            'sig' => $signature,
            'store_id' => $storeId,
            '___store' => $store->getCode(),
        ];

        if ($normalizedFingerprint !== '') {
            $query['fp'] = $normalizedFingerprint;
        }

        if ($couponCode !== '') {
            $query['coupon'] = $couponCode;
        }

        return rtrim($store->getBaseUrl(UrlInterface::URL_TYPE_WEB), '/') . '/neurocheckout/recover/index?' . http_build_query($query);
    }
}
