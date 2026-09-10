<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Webapi;

use Magento\Framework\Webapi\Exception;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Store\Model\StoreManagerInterface;
use NeuroCheckout\Connector\Api\ApiTestManagementInterface;
use NeuroCheckout\Connector\Model\Config;
use NeuroCheckout\Connector\Model\Http\SecureHttpClient;
use NeuroCheckout\Connector\Model\Security\RequestSecurityValidator;
use Psr\Log\LoggerInterface;

class ApiTestManagement extends AbstractEndpoint implements ApiTestManagementInterface
{
    private RequestSecurityValidator $securityValidator;
    private SecureHttpClient $httpClient;
    private Config $config;
    private LoggerInterface $logger;

    public function __construct(
        Request $request,
        StoreManagerInterface $storeManager,
        RequestSecurityValidator $securityValidator,
        SecureHttpClient $httpClient,
        Config $config,
        LoggerInterface $logger
    ) {
        parent::__construct($request, $storeManager);
        $this->securityValidator = $securityValidator;
        $this->httpClient = $httpClient;
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
            $security = $this->securityValidator->validate($this->getRawBody(), $storeId, 'apitest', false);
            if (empty($security['success'])) {
                return $this->response(false, (int)($security['status'] ?? 403), (string)($security['error'] ?? 'forbidden'));
            }

            $apiIssues = $this->config->getApiConfigurationIssues($storeId);
            $iaIssues = $this->config->getIaConfigurationIssues($storeId);
            if ($apiIssues !== [] || $iaIssues !== []) {
                return $this->response(false, 422, 'Required configuration incomplete', [
                    'api_ready' => $apiIssues === [],
                    'api_issues' => $apiIssues,
                    'ia_ready' => $iaIssues === [],
                    'ia_issues' => $iaIssues,
                    'health' => null,
                    'event_probe' => null,
                ]);
            }

            $shopExternalId = $this->config->getString(Config::XML_PATH_SHOP_EXTERNAL_ID, $storeId);
            $shopRef = $shopExternalId !== '' ? $shopExternalId : (string)$storeId;
            $health = $this->httpClient->health([
                'source' => ['shop_id' => $shopRef],
            ]);

            $isIaReady = true;
            $healthOk = !empty($health['success']);
            $probe = null;
            if ($healthOk) {
                $probe = $this->httpClient->send(
                    $this->buildApiTestEventPayload($storeId, $shopRef),
                    ['is_api_test' => true]
                );
            }
            $probeOk = !$healthOk || !empty($probe['success']);
            $isSuccess = $healthOk && $probeOk;
            $error = null;
            if (!$isSuccess) {
                if (!$isIaReady) {
                    $error = 'IA configuration incomplete';
                } elseif (!$healthOk) {
                    $error = (string)($health['error'] ?? 'API check failed');
                } else {
                    $error = (string)($probe['error'] ?? 'API test event could not be sent');
                }
            }

            if ($isSuccess) {
                $endpoint = $this->config->getString(Config::XML_PATH_API_ENDPOINT, $storeId);
                $apiKey = $this->config->getNormalizedApiKey($storeId);
                $shopExternalId = $this->config->getString(Config::XML_PATH_SHOP_EXTERNAL_ID, $storeId);
                if ($endpoint !== '' && $apiKey !== '' && $shopExternalId !== '') {
                    $fingerprint = hash('sha256', implode('|', [$endpoint, $apiKey, $shopExternalId]));
                    $this->config->setValue(Config::XML_PATH_API_TEST_VALIDATION_FINGERPRINT, $fingerprint, $storeId);
                    $this->config->setValue(Config::XML_PATH_API_TEST_VALIDATED_AT, (string)time(), $storeId);
                }
            }

            return $this->response(
                $isSuccess,
                $isSuccess ? 200 : 422,
                $isSuccess ? null : $error,
                [
                    'ia_ready' => $isIaReady,
                    'health' => $health,
                    'event_probe' => $probe,
                ]
            );
        } catch (Exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('[NC] API test endpoint error: ' . $e->getMessage());
            return $this->response(false, 500, 'Internal error');
        }
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
