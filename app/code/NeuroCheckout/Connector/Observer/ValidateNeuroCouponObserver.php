<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Observer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;

class ValidateNeuroCouponObserver implements ObserverInterface
{
    private ResourceConnection $resource;
    private CustomerRepositoryInterface $customerRepository;
    private LoggerInterface $logger;

    public function __construct(
        ResourceConnection $resource,
        CustomerRepositoryInterface $customerRepository,
        LoggerInterface $logger
    ) {
        $this->resource = $resource;
        $this->customerRepository = $customerRepository;
        $this->logger = $logger;
    }

    public function execute(Observer $observer): void
    {
        $quote = $observer->getEvent()->getQuote();
        if (!$quote instanceof Quote || !(int) $quote->getId()) {
            return;
        }

        $couponCode = strtoupper(trim((string) $quote->getCouponCode()));
        if ($couponCode === '') {
            return;
        }

        $storeId = (int) $quote->getStoreId();
        $meta = $this->loadCouponMeta($storeId, $couponCode);
        if (!is_array($meta)) {
            if (strpos($couponCode, 'NC-') === 0) {
                $this->clearCoupon($quote, $couponCode, 'metadata_missing');
            }
            return;
        }

        $expiresAt = trim((string) ($meta['expires_at'] ?? ''));
        if ($expiresAt !== '' && strtotime($expiresAt) < time()) {
            $this->clearCoupon($quote, $couponCode, 'expired');
            return;
        }

        $metaEmail = strtolower(trim((string) ($meta['customer_email'] ?? '')));
        $quoteEmail = $this->resolveQuoteEmail($quote);
        if ($metaEmail === '' || $quoteEmail === '' || !hash_equals($metaEmail, $quoteEmail)) {
            $this->clearCoupon($quote, $couponCode, 'customer_mismatch');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadCouponMeta(int $storeId, string $couponCode): ?array
    {
        $table = $this->resource->getTableName('neurocheckout_coupon');
        $connection = $this->resource->getConnection();

        $row = $connection->fetchRow(
            $connection->select()
                ->from($table, ['customer_email', 'expires_at'])
                ->where('store_id = ?', max(0, $storeId))
                ->where('coupon_code = ?', $couponCode)
                ->order('id DESC')
                ->limit(1)
        );

        return is_array($row) ? $row : null;
    }

    private function resolveQuoteEmail(Quote $quote): string
    {
        $email = strtolower(trim((string) $quote->getCustomerEmail()));
        if ($email !== '') {
            return $email;
        }

        $customerId = (int) $quote->getCustomerId();
        if ($customerId <= 0) {
            return '';
        }

        try {
            return strtolower(trim((string) $this->customerRepository->getById($customerId)->getEmail()));
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function clearCoupon(Quote $quote, string $couponCode, string $reason): void
    {
        $quote->setCouponCode('');
        $quote->setTotalsCollectedFlag(false);

        foreach ($quote->getAllItems() as $item) {
            if (method_exists($item, 'setAppliedRuleIds')) {
                $item->setAppliedRuleIds(null);
            }
            if (method_exists($item, 'setDiscountAmount')) {
                $item->setDiscountAmount(0);
            }
            if (method_exists($item, 'setBaseDiscountAmount')) {
                $item->setBaseDiscountAmount(0);
            }
        }

        $this->logger->info(
            sprintf(
                '[NC] Removed coupon %s from quote %s (%s)',
                $couponCode,
                (string) $quote->getId(),
                $reason
            )
        );
    }
}
