<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Controller\Recover;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ResourceConnection;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteFactory;
use NeuroCheckout\Connector\Model\Security\CartFingerprintService;
use NeuroCheckout\Connector\Model\Security\RecoveryLinkService;
use Psr\Log\LoggerInterface;

class Index extends Action
{
    private CartRepositoryInterface $quoteRepository;
    private QuoteFactory $quoteFactory;
    private CustomerRepositoryInterface $customerRepository;
    private CheckoutSession $checkoutSession;
    private CustomerSession $customerSession;
    private CartFingerprintService $cartFingerprintService;
    private RecoveryLinkService $recoveryLinkService;
    private ResourceConnection $resource;
    private LoggerInterface $logger;

    public function __construct(
        Context $context,
        CartRepositoryInterface $quoteRepository,
        QuoteFactory $quoteFactory,
        CustomerRepositoryInterface $customerRepository,
        CheckoutSession $checkoutSession,
        CustomerSession $customerSession,
        CartFingerprintService $cartFingerprintService,
        RecoveryLinkService $recoveryLinkService,
        ResourceConnection $resource,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->quoteRepository = $quoteRepository;
        $this->quoteFactory = $quoteFactory;
        $this->customerRepository = $customerRepository;
        $this->checkoutSession = $checkoutSession;
        $this->customerSession = $customerSession;
        $this->cartFingerprintService = $cartFingerprintService;
        $this->recoveryLinkService = $recoveryLinkService;
        $this->resource = $resource;
        $this->logger = $logger;
    }

    public function execute()
    {
        $storeId = (int) $this->getRequest()->getParam('store_id');
        $recoveryToken = trim((string) ($this->getRequest()->getParam('rt') ?: $this->getRequest()->getParam('token')));
        $cartId = 0;
        $email = '';
        $couponCode = '';
        $cartFingerprint = '';
        $timestamp = 0;
        $signature = '';
        $targetUrl = $this->sanitizeSameHostTargetUrl(trim((string) $this->getRequest()->getParam('u')));

        $fallbackUrl = $this->recoveryLinkService->buildCartUrl(max(1, $storeId));
        if ($recoveryToken !== '') {
            $opaquePayload = $this->recoveryLinkService->resolveOpaque($recoveryToken);
            if (!is_array($opaquePayload)) {
                $this->logger->warning('[NC] Recover link invalid or expired opaque token');
                return $this->resultRedirectFactory->create()->setUrl($fallbackUrl);
            }

            $storeId = (int) ($opaquePayload['store_id'] ?? $storeId);
            $opaqueMode = trim((string) ($opaquePayload['mode'] ?? 'cart'));
            if ($opaqueMode === 'customer_session') {
                $customerId = (int) ($opaquePayload['customer_id'] ?? 0);
                $email = trim((string) ($opaquePayload['customer_email'] ?? ''));
                $tokenTargetUrl = $this->sanitizeSameHostTargetUrl(trim((string) ($opaquePayload['target_url'] ?? '')));
                $fallbackUrl = $this->recoveryLinkService->buildCartUrl(max(1, $storeId));

                if (!$this->restoreCustomerSessionById($customerId, $email)) {
                    $this->logger->warning('[NC] Recover link customer session restore failed for customer ' . $customerId);
                    return $this->resultRedirectFactory->create()->setUrl($fallbackUrl);
                }

                if (!$this->recoveryLinkService->consumeOpaque($recoveryToken)) {
                    $this->logger->warning('[NC] Recover link opaque token already consumed for customer ' . $customerId);
                    return $this->resultRedirectFactory->create()->setUrl($fallbackUrl);
                }

                $this->emitHostOnlySessionCookie();

                return $this->resultRedirectFactory->create()->setUrl($tokenTargetUrl !== '' ? $tokenTargetUrl : $fallbackUrl);
            }

            $cartId = (int) ($opaquePayload['cart_id'] ?? 0);
            $email = trim((string) ($opaquePayload['customer_email'] ?? ''));
            $couponCode = trim((string) ($opaquePayload['coupon_code'] ?? ''));
            $cartFingerprint = trim((string) ($opaquePayload['cart_fingerprint'] ?? ''));
        } else {
            $cartId = (int) $this->getRequest()->getParam('cart_id');
            $email = trim((string) $this->getRequest()->getParam('email'));
            $couponCode = trim((string) $this->getRequest()->getParam('coupon'));
            $cartFingerprint = trim((string) $this->getRequest()->getParam('fp'));
            $timestamp = (int) $this->getRequest()->getParam('ts');
            $signature = trim((string) $this->getRequest()->getParam('sig'));

            if (!$this->recoveryLinkService->isValid(max(1, $storeId), $cartId, $email, $couponCode, $timestamp, $signature, $cartFingerprint)) {
                $this->logger->warning('[NC] Recover link invalid signature for cart ' . $cartId);
                return $this->resultRedirectFactory->create()->setUrl($fallbackUrl);
            }
        }

        $quote = $this->loadQuote($cartId);
        if ($quote === null || !(int) $quote->getId()) {
            $this->logger->warning('[NC] Recover link quote not found: ' . $cartId);
            return $this->resultRedirectFactory->create()->setUrl($fallbackUrl);
        }

        $storeId = (int) ($quote->getStoreId() ?: $storeId);
        $fallbackUrl = $this->recoveryLinkService->buildCartUrl(max(1, $storeId));

        if ($couponCode !== '' && !$this->validateCouponMeta($storeId, (string) $quote->getId(), $couponCode, $email, $quote, $cartFingerprint)) {
            $this->logger->warning('[NC] Recover link coupon metadata mismatch for cart ' . $cartId);
            return $this->resultRedirectFactory->create()->setUrl($fallbackUrl);
        }

        if (!$this->emailMatchesQuote($quote, $email)) {
            $this->logger->warning('[NC] Recover link customer mismatch for cart ' . $cartId);
            return $this->resultRedirectFactory->create()->setUrl($fallbackUrl);
        }

        if (!$this->quoteContainsProducts($quote)) {
            $this->logger->warning('[NC] Recover link empty quote: ' . $cartId);
            return $this->resultRedirectFactory->create()->setUrl($fallbackUrl);
        }

        if ($cartFingerprint !== '' && !$this->quoteFingerprintMatches($quote, $cartFingerprint)) {
            $this->logger->warning('[NC] Recover link cart fingerprint mismatch for cart ' . $cartId);
            return $this->resultRedirectFactory->create()->setUrl($fallbackUrl);
        }

        $quote->setIsActive(true);
        if ($couponCode !== '') {
            $quote->setCouponCode($couponCode);
            $quote->collectTotals();
        }
        $this->quoteRepository->save($quote);

        if ($recoveryToken !== '' && !$this->recoveryLinkService->consumeOpaque($recoveryToken)) {
            $this->logger->warning('[NC] Recover link opaque token already consumed for cart ' . $cartId);
            return $this->resultRedirectFactory->create()->setUrl($fallbackUrl);
        }

        $this->checkoutSession->replaceQuote($quote);
        $this->checkoutSession->setQuoteId((int) $quote->getId());

        if (!$this->restoreRegisteredCustomerSession($quote, $email) && $this->customerSession->isLoggedIn()) {
            $this->customerSession->logout();
        }

        $this->emitHostOnlySessionCookie();

        return $this->resultRedirectFactory->create()->setUrl($targetUrl !== '' ? $targetUrl : $fallbackUrl);
    }

    private function loadQuote(int $cartId): ?Quote
    {
        if ($cartId <= 0) {
            return null;
        }

        try {
            $quote = $this->quoteRepository->get($cartId);
        } catch (\Throwable $e) {
            $quote = $this->quoteFactory->create()->load($cartId);
        }

        return ($quote instanceof Quote && (int) $quote->getId() > 0) ? $quote : null;
    }

    private function validateCouponMeta(
        int $storeId,
        string $cartId,
        string $couponCode,
        string $email,
        Quote $quote,
        string $providedFingerprint = ''
    ): bool
    {
        $table = $this->resource->getTableName('neurocheckout_coupon');
        $connection = $this->resource->getConnection();

        $row = $connection->fetchRow(
            $connection->select()
                ->from($table, ['customer_email', 'expires_at', 'cart_fingerprint'])
                ->where('store_id = ?', max(0, $storeId))
                ->where('cart_id = ?', $cartId)
                ->where('coupon_code = ?', $couponCode)
                ->order('id DESC')
                ->limit(1)
        );

        if (!is_array($row)) {
            return false;
        }

        $metaEmail = strtolower(trim((string) ($row['customer_email'] ?? '')));
        if ($metaEmail === '' || !hash_equals($metaEmail, strtolower(trim($email)))) {
            return false;
        }

        $expiresAt = trim((string) ($row['expires_at'] ?? ''));
        if ($expiresAt !== '' && strtotime($expiresAt) < time()) {
            return false;
        }

        $storedFingerprint = trim((string) ($row['cart_fingerprint'] ?? ''));
        if ($storedFingerprint !== '') {
            if (!$this->quoteFingerprintMatches($quote, $storedFingerprint)) {
                return false;
            }

            if ($providedFingerprint !== '' && !hash_equals($storedFingerprint, $providedFingerprint)) {
                return false;
            }
        }

        return true;
    }

    private function emailMatchesQuote(Quote $quote, string $email): bool
    {
        $normalized = strtolower(trim($email));
        if ($normalized === '') {
            return false;
        }

        $quoteEmail = strtolower(trim((string) $quote->getCustomerEmail()));
        if ($quoteEmail !== '') {
            return hash_equals($quoteEmail, $normalized);
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

        return hash_equals($normalized, strtolower(trim((string) $customer->getEmail())));
    }

    private function quoteContainsProducts(Quote $quote): bool
    {
        return (int) $quote->getItemsCount() > 0;
    }

    private function quoteFingerprintMatches(Quote $quote, string $expectedFingerprint): bool
    {
        $normalizedExpected = trim($expectedFingerprint);
        if ($normalizedExpected === '') {
            return true;
        }

        $currentFingerprint = $this->cartFingerprintService->fromQuote($quote);
        return $currentFingerprint !== '' && hash_equals($normalizedExpected, $currentFingerprint);
    }

    private function restoreRegisteredCustomerSession(Quote $quote, string $email): bool
    {
        $customerId = (int) $quote->getCustomerId();
        if ($customerId <= 0) {
            return false;
        }

        if (!$this->emailMatchesQuote($quote, $email)) {
            return false;
        }

        try {
            $customer = $this->customerRepository->getById($customerId);
            $this->customerSession->setCustomerDataAsLoggedIn($customer);
            $this->customerSession->setCustomerData($customer);
            $this->customerSession->setCustomerId($customerId);
            $this->logger->info('[NC] Recover link restored customer session for customer ' . $customerId);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function restoreCustomerSessionById(int $customerId, string $email): bool
    {
        if ($customerId <= 0) {
            return false;
        }

        $normalizedEmail = strtolower(trim($email));
        if ($normalizedEmail === '') {
            return false;
        }

        try {
            $customer = $this->customerRepository->getById($customerId);
            if (!hash_equals($normalizedEmail, strtolower(trim((string) $customer->getEmail())))) {
                return false;
            }

            $this->customerSession->setCustomerDataAsLoggedIn($customer);
            $this->customerSession->setCustomerData($customer);
            $this->customerSession->setCustomerId($customerId);
            $this->logger->info('[NC] Recover link restored customer-only session for customer ' . $customerId);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function emitHostOnlySessionCookie(): void
    {
        $sessionId = trim((string) $this->customerSession->getSessionId());
        if ($sessionId === '') {
            return;
        }

        $cookie = sprintf(
            'PHPSESSID=%s; expires=%s; Max-Age=3600; path=/; HttpOnly; SameSite=Lax',
            rawurlencode($sessionId),
            gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT'
        );

        $this->getResponse()->setHeader('Set-Cookie', $cookie, false);
    }

    private function sanitizeSameHostTargetUrl(string $targetUrl): string
    {
        $candidate = trim($targetUrl);
        if ($candidate === '') {
            return '';
        }

        $parts = parse_url($candidate);
        if (!is_array($parts)) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $targetHost = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $targetHost === '') {
            return '';
        }

        $currentHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $currentHost = preg_replace('/:\d+$/', '', $currentHost) ?: '';

        return ($currentHost !== '' && hash_equals($currentHost, $targetHost)) ? $candidate : '';
    }
}
