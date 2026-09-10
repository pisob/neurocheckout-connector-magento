<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Controller\Adminhtml\System\Config;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Store\Model\StoreManagerInterface;
use NeuroCheckout\Connector\Model\Config;
use NeuroCheckout\Connector\Model\Http\SecureHttpClient;

class TestApi extends Action
{
    public const ADMIN_RESOURCE = 'NeuroCheckout_Connector::config';

    private Config $config;
    private SecureHttpClient $httpClient;
    private StoreManagerInterface $storeManager;

    public function __construct(
        Context $context,
        Config $config,
        SecureHttpClient $httpClient,
        StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
        $this->config = $config;
        $this->httpClient = $httpClient;
        $this->storeManager = $storeManager;
    }

    public function execute(): Redirect
    {
        $scopeParams = $this->resolveScopeParams();
        $storeId = $this->resolveStoreId($scopeParams);
        $effectiveStoreId = (int)($storeId ?? 0);
        $shopExternalId = $this->config->getString(Config::XML_PATH_SHOP_EXTERNAL_ID, $storeId);
        $shopRef = $shopExternalId !== '' ? $shopExternalId : (string)$effectiveStoreId;
        $apiIssues = $this->config->getApiConfigurationIssues($storeId);
        $iaIssues = $this->config->getIaConfigurationIssues($storeId);

        try {
            if ($apiIssues !== [] || $iaIssues !== []) {
                $this->messageManager->addErrorMessage(
                    __('Test API bloque: completez et sauvegardez d abord la configuration requise. %1', implode(' ', array_merge($apiIssues, $iaIssues)))
                );

                $params = array_merge(['section' => 'neurocheckoutconnector'], $scopeParams);
                return $this->resultRedirectFactory->create()->setPath('adminhtml/system_config/edit', $params);
            }

            $health = $this->httpClient->health([
                'source' => ['shop_id' => $shopRef],
            ]);
            $isIaReady = true;
            $healthOk = !empty($health['success']);
            $probe = null;
            if ($healthOk) {
                $probe = $this->httpClient->send(
                    $this->buildApiTestEventPayload($effectiveStoreId, $shopRef),
                    ['is_api_test' => true]
                );
            }
            $probeOk = !$healthOk || !empty($probe['success']);
            $isSuccess = $healthOk && $probeOk;

            if ($isSuccess) {
                $endpoint = $this->config->getString(Config::XML_PATH_API_ENDPOINT, $storeId);
                $apiKey = $this->config->getNormalizedApiKey($storeId);
                $shopExternalId = $this->config->getString(Config::XML_PATH_SHOP_EXTERNAL_ID, $storeId);

                if ($endpoint !== '' && $apiKey !== '' && $shopExternalId !== '') {
                    $fingerprint = hash('sha256', implode('|', [$endpoint, $apiKey, $shopExternalId]));
                    $this->config->setValue(Config::XML_PATH_API_TEST_VALIDATION_FINGERPRINT, $fingerprint, $storeId);
                    $this->config->setValue(Config::XML_PATH_API_TEST_VALIDATED_AT, (string)time(), $storeId);
                }

                $this->messageManager->addSuccessMessage(
                    __('Test API valide. Le cron et le traitement des evenements sont autorises.')
                );
            } else {
                if (!$isIaReady) {
                    $error = (string) __('Configuration IA incomplete. %1', implode(' ', $iaIssues));
                } elseif (!$healthOk) {
                    $error = (string)($health['error'] ?? 'API check failed');
                } else {
                    $error = (string)($probe['error'] ?? 'API test event could not be sent');
                }
                $this->messageManager->addErrorMessage(__('Test API echoue: %1', $error));
            }
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Test API echoue: %1', $e->getMessage()));
        }

        $params = array_merge(['section' => 'neurocheckoutconnector'], $scopeParams);
        return $this->resultRedirectFactory->create()->setPath('adminhtml/system_config/edit', $params);
    }

    /**
     * @return array<string, string>
     */
    private function resolveScopeParams(): array
    {
        $params = [];

        $website = trim((string)$this->getRequest()->getParam('website'));
        if ($website !== '') {
            $params['website'] = $website;
        }

        $store = trim((string)$this->getRequest()->getParam('store'));
        if ($store !== '') {
            $params['store'] = $store;
        }

        return $params;
    }

    /**
     * @param array<string, string> $scopeParams
     */
    private function resolveStoreId(array $scopeParams): ?int
    {
        try {
            if (!empty($scopeParams['store'])) {
                return (int)$this->storeManager->getStore($scopeParams['store'])->getId();
            }

            if (!empty($scopeParams['website'])) {
                $website = $this->storeManager->getWebsite($scopeParams['website']);
                $defaultStore = $website->getDefaultStore();
                if ($defaultStore !== null) {
                    return (int)$defaultStore->getId();
                }
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildApiTestEventPayload(int $storeId, string $shopRef): array
    {
        $suffix = bin2hex(random_bytes(6));
        $cartRef = 'api-test-' . $shopRef . '-' . $suffix;
        $locale = $this->resolveStoreLocale($storeId);
        $language = $this->resolveLanguageCode($locale);

        return [
            'event_id' => 'test-' . $suffix,
            'event_type' => 'cart.updated',
            'occurred_at' => gmdate('c'),
            'language' => $language,
            'source' => [
                'platform' => 'magento',
                'shop_id' => (string)$storeId,
                'shop_name' => $shopRef,
                'language' => $language,
            ],
            'cart' => [
                'id' => $cartRef,
                'uid' => $cartRef,
                'total' => 99.0,
                'items' => [
                    [
                        'product_id' => 999001,
                        'attribute_id' => 0,
                        'quantity' => 1,
                        'unit_price' => 99.0,
                        'name' => 'NeuroCheckout API Test Item',
                    ],
                ],
            ],
            'customer' => [
                'id' => 'api-test',
                'email' => 'apitest+' . $shopRef . '@neurocheckout.local',
                'first_name' => 'API',
                'last_name' => 'Test',
                'locale' => $locale,
                'language' => $language,
                'is_guest' => true,
            ],
            'context' => [
                'shop_locale' => $locale,
                'shop_language' => $language,
            ],
        ];
    }

    private function resolveStoreLocale(int $storeId): string
    {
        try {
            $locale = trim((string)$this->storeManager->getStore($storeId)->getConfig('general/locale/code'));
            if ($locale !== '') {
                return $locale;
            }
        } catch (\Throwable $e) {
            return 'en_US';
        }

        return 'en_US';
    }

    private function resolveLanguageCode(string $locale): string
    {
        $normalized = strtolower(str_replace('_', '-', trim($locale)));
        $language = explode('-', $normalized, 2)[0] ?? '';

        return in_array($language, ['fr', 'en', 'es', 'de', 'it'], true) ? $language : 'en';
    }
}
