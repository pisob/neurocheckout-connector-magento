<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Adminhtml;

use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\StoreManagerInterface;

class ScopeResolver
{
    private RequestInterface $request;
    private StoreManagerInterface $storeManager;

    public function __construct(
        RequestInterface $request,
        StoreManagerInterface $storeManager
    ) {
        $this->request = $request;
        $this->storeManager = $storeManager;
    }

    /**
     * @return array<string, string>
     */
    public function getScopeParams(): array
    {
        $params = [];

        $website = trim((string) $this->request->getParam('website'));
        if ($website !== '') {
            $params['website'] = $website;
        }

        $store = trim((string) $this->request->getParam('store'));
        if ($store !== '') {
            $params['store'] = $store;
        }

        return $params;
    }

    /**
     * @param array<string, string>|null $scopeParams
     */
    public function resolveStoreId(?array $scopeParams = null): ?int
    {
        $scopeParams = $scopeParams ?? $this->getScopeParams();

        try {
            if (!empty($scopeParams['store'])) {
                return (int) $this->storeManager->getStore($scopeParams['store'])->getId();
            }

            if (!empty($scopeParams['website'])) {
                $website = $this->storeManager->getWebsite($scopeParams['website']);
                $defaultStore = $website->getDefaultStore();
                if ($defaultStore !== null) {
                    return (int) $defaultStore->getId();
                }
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    /**
     * @param array<string, string>|null $scopeParams
     */
    public function getEffectiveStoreId(?array $scopeParams = null): int
    {
        $resolvedStoreId = $this->resolveStoreId($scopeParams);
        if ($resolvedStoreId !== null && $resolvedStoreId > 0) {
            return $resolvedStoreId;
        }

        try {
            return (int) $this->storeManager->getDefaultStoreView()->getId();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
