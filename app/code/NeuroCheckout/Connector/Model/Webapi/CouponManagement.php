<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Webapi;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Customer\Model\ResourceModel\Group\CollectionFactory as GroupCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Webapi\Exception;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteFactory;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\RuleFactory;
use Magento\Store\Model\StoreManagerInterface;
use NeuroCheckout\Connector\Api\CouponManagementInterface;
use NeuroCheckout\Connector\Model\Config;
use NeuroCheckout\Connector\Model\Security\CartFingerprintService;
use NeuroCheckout\Connector\Model\Security\RecoveryLinkService;
use NeuroCheckout\Connector\Model\Security\RequestSecurityValidator;
use Psr\Log\LoggerInterface;

class CouponManagement extends AbstractEndpoint implements CouponManagementInterface
{
    private const DEFAULT_TTL_HOURS = 48;
    private const MAX_TTL_HOURS = 168;

    private RequestSecurityValidator $securityValidator;
    private ResourceConnection $resource;
    private RuleFactory $ruleFactory;
    private GroupCollectionFactory $groupCollectionFactory;
    private CustomerRepositoryInterface $customerRepository;
    private CartRepositoryInterface $quoteRepository;
    private QuoteFactory $quoteFactory;
    private CartFingerprintService $cartFingerprintService;
    private RecoveryLinkService $recoveryLinkService;
    private Config $config;
    private LoggerInterface $logger;

    public function __construct(
        Request $request,
        StoreManagerInterface $storeManager,
        RequestSecurityValidator $securityValidator,
        ResourceConnection $resource,
        RuleFactory $ruleFactory,
        GroupCollectionFactory $groupCollectionFactory,
        CustomerRepositoryInterface $customerRepository,
        CartRepositoryInterface $quoteRepository,
        QuoteFactory $quoteFactory,
        CartFingerprintService $cartFingerprintService,
        RecoveryLinkService $recoveryLinkService,
        Config $config,
        LoggerInterface $logger
    ) {
        parent::__construct($request, $storeManager);
        $this->securityValidator = $securityValidator;
        $this->resource = $resource;
        $this->ruleFactory = $ruleFactory;
        $this->groupCollectionFactory = $groupCollectionFactory;
        $this->customerRepository = $customerRepository;
        $this->quoteRepository = $quoteRepository;
        $this->quoteFactory = $quoteFactory;
        $this->cartFingerprintService = $cartFingerprintService;
        $this->recoveryLinkService = $recoveryLinkService;
        $this->config = $config;
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
            $security = $this->securityValidator->validate($this->getRawBody(), $storeId, 'coupon', false);
            if (empty($security['success'])) {
                return $this->response(false, (int)($security['status'] ?? 403), (string)($security['error'] ?? 'forbidden'));
            }

            $requestUid = trim((string)($payload['request_uid'] ?? ''));
            $decisionId = trim((string)($payload['decision_id'] ?? ''));
            $actionId = trim((string)($payload['action_id'] ?? ''));
            $cartId = trim((string)($payload['cart_id'] ?? ''));
            $customerEmail = trim((string)($payload['customer_email'] ?? ''));
            $discountPercent = min(max((float)($payload['discount_percent'] ?? 0), 0.0), 100.0);
            $ttlHours = min(max((int)($payload['ttl_hours'] ?? self::DEFAULT_TTL_HOURS), 1), self::MAX_TTL_HOURS);

            if ($requestUid === '') {
                $requestUid = trim($decisionId . ':' . $actionId);
            }

            if ($requestUid === '' || $cartId === '' || $customerEmail === '') {
                return $this->response(false, 422, 'Missing required fields');
            }

            $cartFingerprint = $this->resolveCartFingerprint((int) $cartId);
            $existing = $this->getExistingCoupon($storeId, $requestUid);
            if ($existing !== null) {
                return $this->response(true, 200, null, $existing);
            }

            if ($discountPercent <= 0.0) {
                $recoveryUrl = $this->buildRecoveryUrl($storeId, $cartId, $customerEmail, null, $cartFingerprint);
                return $this->response(true, 200, null, [
                    'coupon_code' => null,
                    'discount_percent' => 0,
                    'recovery_url' => $recoveryUrl,
                    'expires_at' => null,
                ]);
            }

            $couponCode = $this->generateCouponCode($cartId);
            $expiresAtTs = time() + ($ttlHours * 3600);
            $expiresAt = gmdate('Y-m-d H:i:s', $expiresAtTs);
            $ruleId = $this->createSalesRule($storeId, $couponCode, $discountPercent, $expiresAtTs, $customerEmail);
            $recoveryUrl = $this->buildRecoveryUrl($storeId, $cartId, $customerEmail, $couponCode, $cartFingerprint);

            $this->persistCoupon(
                $storeId,
                $requestUid,
                $decisionId,
                $actionId,
                $cartId,
                $customerEmail,
                $ruleId,
                $couponCode,
                $discountPercent,
                $cartFingerprint,
                $recoveryUrl,
                $expiresAt
            );

            return $this->response(true, 200, null, [
                'coupon_code' => $couponCode,
                'discount_percent' => round($discountPercent, 2),
                'recovery_url' => $recoveryUrl,
                'expires_at' => $expiresAt,
            ]);
        } catch (Exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('[NC] Coupon endpoint error: ' . $e->getMessage());
            return $this->response(false, 500, 'Internal error');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getExistingCoupon(int $storeId, string $requestUid): ?array
    {
        $table = $this->resource->getTableName('neurocheckout_coupon');
        $connection = $this->resource->getConnection();

        $row = $connection->fetchRow(
            $connection->select()
                ->from($table, ['id', 'cart_id', 'customer_email', 'coupon_code', 'discount_percent', 'cart_fingerprint', 'recovery_url', 'expires_at'])
                ->where('store_id = ?', max(0, $storeId))
                ->where('request_uid = ?', $requestUid)
                ->limit(1)
        );

        if (!is_array($row)) {
            return null;
        }

        $cartId = (string) ($row['cart_id'] ?? '');
        $customerEmail = (string) ($row['customer_email'] ?? '');
        $couponCode = (string) ($row['coupon_code'] ?? '');
        $cartFingerprint = trim((string) ($row['cart_fingerprint'] ?? ''));
        $recoveryUrl = $this->buildRecoveryUrl(
            $storeId,
            $cartId,
            $customerEmail,
            $couponCode !== '' ? $couponCode : null,
            $cartFingerprint
        );
        if ((int) ($row['id'] ?? 0) > 0) {
            $connection->update($table, ['recovery_url' => $recoveryUrl], ['id = ?' => (int) $row['id']]);
        }

        return [
            'coupon_code' => $couponCode,
            'discount_percent' => (float)($row['discount_percent'] ?? 0),
            'recovery_url' => $recoveryUrl,
            'expires_at' => (string)($row['expires_at'] ?? ''),
        ];
    }

    private function generateCouponCode(string $cartId): string
    {
        $suffix = strtoupper(bin2hex(random_bytes(4)));
        return 'NC-' . preg_replace('/[^A-Za-z0-9]/', '', $cartId) . '-' . $suffix;
    }

    private function createSalesRule(
        int $storeId,
        string $couponCode,
        float $discountPercent,
        int $expiresAtTs,
        string $customerEmail
    ): int
    {
        /** @var Rule $rule */
        $rule = $this->ruleFactory->create();

        $store = $this->storeManager->getStore($storeId);
        $websiteId = (int) $store->getWebsiteId();

        $groupIds = $this->resolveTargetCustomerGroupIds($websiteId, $customerEmail);

        $rule->setName('NeuroCheckout Coupon ' . $couponCode);
        $rule->setDescription('Generated by NeuroCheckout connector for ' . strtolower(trim($customerEmail)));
        $rule->setFromDate(gmdate('Y-m-d'));
        $rule->setToDate(gmdate('Y-m-d', $expiresAtTs));
        $rule->setIsActive(1);
        $rule->setSimpleAction('by_percent');
        $rule->setDiscountAmount(round($discountPercent, 2));
        $rule->setCustomerGroupIds($groupIds);
        $rule->setWebsiteIds([$websiteId]);
        $rule->setCouponType(Rule::COUPON_TYPE_SPECIFIC);
        $rule->setCouponCode($couponCode);
        $rule->setUsesPerCoupon(1);
        $rule->setUsesPerCustomer(1);
        $rule->setStopRulesProcessing(0);
        $rule->setApplyToShipping(0);
        $rule->setSimpleFreeShipping(0);
        $rule->setSortOrder(0);
        $rule->setIsRss(0);
        $rule->setConditionsSerialized('{}');
        $rule->setActionsSerialized('{}');
        $rule->save();

        return (int) $rule->getId();
    }

    /**
     * @return list<int>
     */
    private function resolveTargetCustomerGroupIds(int $websiteId, string $customerEmail): array
    {
        $normalizedEmail = strtolower(trim($customerEmail));
        if ($normalizedEmail !== '') {
            try {
                $customer = $this->customerRepository->get($normalizedEmail, $websiteId);
                $groupId = (int) $customer->getGroupId();
                if ($groupId >= 0) {
                    return [$groupId];
                }
            } catch (\Throwable $e) {
            }
        }

        $groupIds = [];
        foreach ($this->groupCollectionFactory->create() as $group) {
            $groupIds[] = (int) $group->getId();
        }

        if ($groupIds === []) {
            return [GroupInterface::NOT_LOGGED_IN_ID];
        }

        if (in_array(GroupInterface::NOT_LOGGED_IN_ID, $groupIds, true)) {
            return [GroupInterface::NOT_LOGGED_IN_ID];
        }

        return [min($groupIds)];
    }

    private function buildRecoveryUrl(
        int $storeId,
        string $cartId,
        string $customerEmail,
        ?string $couponCode,
        string $cartFingerprint = ''
    ): string
    {
        return $this->recoveryLinkService->build($storeId, $cartId, $customerEmail, $couponCode ?? '', $cartFingerprint);
    }

    private function persistCoupon(
        int $storeId,
        string $requestUid,
        string $decisionId,
        string $actionId,
        string $cartId,
        string $customerEmail,
        int $ruleId,
        string $couponCode,
        float $discountPercent,
        string $cartFingerprint,
        string $recoveryUrl,
        string $expiresAt
    ): void {
        $table = $this->resource->getTableName('neurocheckout_coupon');
        $connection = $this->resource->getConnection();

        $connection->insert($table, [
            'store_id' => max(0, $storeId),
            'request_uid' => $requestUid,
            'decision_id' => $decisionId,
            'action_id' => $actionId,
            'cart_id' => $cartId,
            'customer_email' => strtolower(trim($customerEmail)),
            'rule_id' => max(0, $ruleId),
            'coupon_code' => $couponCode,
            'discount_percent' => round($discountPercent, 2),
            'cart_fingerprint' => $cartFingerprint !== '' ? $cartFingerprint : null,
            'recovery_url' => $recoveryUrl,
            'expires_at' => $expiresAt,
        ]);
    }

    private function resolveCartFingerprint(int $cartId): string
    {
        $quote = $this->loadQuote($cartId);
        if ($quote === null || !(int) $quote->getId()) {
            return '';
        }

        return $this->cartFingerprintService->fromQuote($quote);
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
}
