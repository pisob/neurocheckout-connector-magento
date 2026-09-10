<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Controller\Journey;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use NeuroCheckout\Connector\Model\Config;
use NeuroCheckout\Connector\Model\Event\CustomerJourneyEventBuilder;
use NeuroCheckout\Connector\Model\Repository\CustomerJourneyEventRepository;
use Psr\Log\LoggerInterface;

class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const MAX_BODY_BYTES = 98304;

    private RequestInterface $request;
    private JsonFactory $jsonFactory;
    private StoreManagerInterface $storeManager;
    private Config $config;
    private CustomerJourneyEventBuilder $eventBuilder;
    private CustomerJourneyEventRepository $repository;
    private LoggerInterface $logger;

    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        StoreManagerInterface $storeManager,
        Config $config,
        CustomerJourneyEventBuilder $eventBuilder,
        CustomerJourneyEventRepository $repository,
        LoggerInterface $logger
    ) {
        $this->request = $request;
        $this->jsonFactory = $jsonFactory;
        $this->storeManager = $storeManager;
        $this->config = $config;
        $this->eventBuilder = $eventBuilder;
        $this->repository = $repository;
        $this->logger = $logger;
    }

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();

        try {
            if (!$this->isBodySizeAllowed()) {
                return $this->json($result, 413, ['success' => false, 'error' => 'payload_too_large']);
            }

            $payload = $this->decodeJsonBody();
            if ($payload === null) {
                return $this->json($result, 400, ['success' => false, 'error' => 'invalid_json']);
            }

            $storeId = $this->resolveStoreId($payload);
            if ($storeId <= 0 || !$this->config->isCustomerJourneyEnabled($storeId)) {
                return $this->json($result, 202, ['success' => true, 'queued' => false]);
            }

            if (!$this->config->isApiConfigurationReady($storeId)) {
                return $this->json($result, 202, ['success' => true, 'queued' => false]);
            }

            if (!$this->isSameSiteRequest($storeId) || !$this->isValidToken($storeId, $payload)) {
                return $this->json($result, 403, ['success' => false, 'error' => 'forbidden']);
            }

            unset($payload['token']);

            $eventPayload = $this->eventBuilder->buildFromBrowserPayload($payload, $storeId);
            if ($eventPayload === null) {
                return $this->json($result, 202, ['success' => true, 'queued' => false]);
            }

            $queued = $this->repository->enqueue($storeId, $eventPayload);

            return $this->json($result, 202, ['success' => true, 'queued' => $queued]);
        } catch (\Throwable $e) {
            $this->logger->warning('[NC] Customer journey endpoint error: ' . $e->getMessage());

            return $this->json($result, 500, ['success' => false, 'error' => 'internal_error']);
        }
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonBody(): ?array
    {
        $body = method_exists($this->request, 'getContent') ? (string) $this->request->getContent() : '';
        if ($body === '') {
            return null;
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function isBodySizeAllowed(): bool
    {
        $contentLength = (int) ($this->request->getServer('CONTENT_LENGTH') ?: 0);

        return $contentLength <= self::MAX_BODY_BYTES;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveStoreId(array $payload): int
    {
        $candidate = (int) ($payload['store_id'] ?? ($payload['context']['store_id'] ?? 0));
        if ($candidate > 0) {
            return $candidate;
        }

        try {
            return (int) $this->storeManager->getStore()->getId();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function isValidToken(int $storeId, array $payload): bool
    {
        $provided = trim((string) ($payload['token'] ?? ''));
        if ($provided === '') {
            $provided = trim((string) ($this->request->getServer('HTTP_X_NEURO_JOURNEY_TOKEN') ?: ''));
        }

        $secret = $this->config->getOrCreateInternalSecret($storeId);
        if ($provided === '' || $secret === '') {
            return false;
        }

        $expected = hash_hmac('sha256', 'journey|' . $storeId, $secret);

        return hash_equals($expected, $provided);
    }

    private function isSameSiteRequest(int $storeId): bool
    {
        $origin = trim((string) ($this->request->getServer('HTTP_ORIGIN') ?: ''));
        $referer = trim((string) ($this->request->getServer('HTTP_REFERER') ?: ''));
        $candidate = $origin !== '' ? $origin : $referer;
        if ($candidate === '') {
            return true;
        }

        $host = strtolower((string) parse_url($candidate, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }

        return in_array($host, $this->allowedHosts($storeId), true);
    }

    /**
     * @return array<int, string>
     */
    private function allowedHosts(int $storeId): array
    {
        $hosts = [];
        $requestHost = strtolower((string) ($this->request->getServer('HTTP_HOST') ?: ''));
        if ($requestHost !== '') {
            $hosts[] = preg_replace('/:\d+$/', '', $requestHost);
        }

        try {
            $store = $this->storeManager->getStore($storeId);
            foreach ([UrlInterface::URL_TYPE_WEB, UrlInterface::URL_TYPE_LINK] as $type) {
                $baseHost = strtolower((string) parse_url((string) $store->getBaseUrl($type), PHP_URL_HOST));
                if ($baseHost !== '') {
                    $hosts[] = $baseHost;
                }
            }
        } catch (\Throwable $e) {
        }

        return array_values(array_unique(array_filter($hosts)));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(Json $result, int $statusCode, array $data): Json
    {
        $result->setHttpResponseCode($statusCode);
        $result->setData($data);

        return $result;
    }
}
