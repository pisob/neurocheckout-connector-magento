<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Http;

use Magento\Framework\HTTP\Client\CurlFactory;
use NeuroCheckout\Connector\Model\Config;
use NeuroCheckout\Connector\Model\Repository\PayloadAliasRepository;
use Psr\Log\LoggerInterface;

class SecureHttpClient
{
    public const CONNECTOR_VERSION = '1.0.5';
    private const DEFAULT_TIMEOUT = 8;
    private const CONNECT_TIMEOUT = 5;
    private const ORDER_TIMEOUT = 2;
    private const ORDER_CONNECT_TIMEOUT = 1;
    private const MAX_RETRIES = 2;
    private const GZIP_THRESHOLD = 1024;
    private const COMPACT_V4 = 4;
    private const COMPACT_V3 = 3;

    private Config $config;
    private CurlFactory $curlFactory;
    private PayloadAliasRepository $aliasRepository;
    private LoggerInterface $logger;

    public function __construct(
        Config $config,
        CurlFactory $curlFactory,
        PayloadAliasRepository $aliasRepository,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->curlFactory = $curlFactory;
        $this->aliasRepository = $aliasRepository;
        $this->logger = $logger;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function send(array $payload, array $options = []): array
    {
        $storeId = $this->extractStoreId($payload);
        $endpoint = rtrim($this->config->getString(Config::XML_PATH_API_ENDPOINT, $storeId), '/');
        $apiKeyCandidates = $this->resolveApiKeyCandidatesForRequest($storeId);

        if ($endpoint === '' || !$apiKeyCandidates) {
            return $this->errorResponse(0, 'API configuration missing');
        }

        if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
            return $this->errorResponse(0, 'Invalid API endpoint');
        }

        $compact = $this->buildCompactPayloads($payload, $storeId);
        $lastResult = $this->errorResponse(0, 'API request not sent');
        $totalCandidates = count($apiKeyCandidates);

        foreach ($apiKeyCandidates as $index => $candidate) {
            $result = $this->sendCartWithApiKey(
                $endpoint,
                (string) $candidate['key'],
                $payload,
                $compact,
                $options,
                $storeId
            );

            if (!empty($result['success'])) {
                return $result;
            }

            $lastResult = $result;
            $status = (int)($result['status'] ?? 0);
            if ($index < ($totalCandidates - 1) && in_array($status, [401, 403], true)) {
                continue;
            }

            return $lastResult;
        }

        return $lastResult;
    }

    public function checkConnectorVersion(int $storeId): array
    {
        $endpoint = rtrim($this->config->getString(Config::XML_PATH_API_ENDPOINT, $storeId), '/');
        $candidates = $this->resolveApiKeyCandidatesForRequest($storeId);
        if ($endpoint === '' || !$candidates || !filter_var($endpoint, FILTER_VALIDATE_URL)) {
            return $this->errorResponse(0, 'API configuration missing');
        }
        $body = json_encode([
            'platform' => 'magento',
            'connector_version' => self::CONNECTOR_VERSION,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $key = (string) $candidates[0]['key'];
        return $this->executeWithRetry(
            $endpoint . '/api/v1/connectors/version-check',
            $body,
            [
                'Content-Type' => 'application/json',
                'X-API-Key' => $key,
                'X-Neuro-Timestamp' => $timestamp,
                'X-Neuro-Nonce' => $nonce,
                'X-Neuro-Signature' => hash_hmac('sha256', $timestamp . '.' . $nonce . '.' . $body, $key),
                'X-Neuro-Version' => '7',
            ],
            self::DEFAULT_TIMEOUT,
            self::CONNECT_TIMEOUT
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sendOrderCompleted(array $payload): array
    {
        $storeId = $this->extractStoreId($payload);
        $endpoint = rtrim($this->config->getString(Config::XML_PATH_API_ENDPOINT, $storeId), '/');
        $apiKeyCandidates = $this->resolveApiKeyCandidatesForRequest($storeId);

        if ($endpoint === '' || !$apiKeyCandidates) {
            return $this->errorResponse(0, 'API configuration missing');
        }

        if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
            return $this->errorResponse(0, 'Invalid API endpoint');
        }

        $lastResult = $this->errorResponse(0, 'API request not sent');
        $totalCandidates = count($apiKeyCandidates);

        foreach ($apiKeyCandidates as $index => $candidate) {
            $result = $this->sendOrderCompletedWithApiKey(
                $endpoint,
                (string) $candidate['key'],
                $payload
            );

            if (!empty($result['success'])) {
                return $result;
            }

            $lastResult = $result;
            $status = (int)($result['status'] ?? 0);
            if ($index < ($totalCandidates - 1) && in_array($status, [401, 403], true)) {
                continue;
            }

            return $lastResult;
        }

        return $lastResult;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sendSupportEvent(array $payload): array
    {
        $storeId = $this->extractStoreId($payload);
        $endpoint = rtrim($this->config->getString(Config::XML_PATH_API_ENDPOINT, $storeId), '/');
        $apiKeyCandidates = $this->resolveApiKeyCandidatesForRequest($storeId);

        if ($endpoint === '' || !$apiKeyCandidates) {
            return $this->errorResponse(0, 'API configuration missing');
        }

        if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
            return $this->errorResponse(0, 'Invalid API endpoint');
        }

        $lastResult = $this->errorResponse(0, 'API request not sent');
        $totalCandidates = count($apiKeyCandidates);

        foreach ($apiKeyCandidates as $index => $candidate) {
            $result = $this->sendSupportEventWithApiKey(
                $endpoint,
                (string) $candidate['key'],
                $payload
            );

            if (!empty($result['success'])) {
                return $result;
            }

            $lastResult = $result;
            $status = (int)($result['status'] ?? 0);
            if ($index < ($totalCandidates - 1) && in_array($status, [401, 403], true)) {
                continue;
            }

            return $lastResult;
        }

        return $lastResult;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sendTelemetryEvent(array $payload): array
    {
        $storeId = $this->extractStoreId($payload);
        $endpoint = rtrim($this->config->getString(Config::XML_PATH_API_ENDPOINT, $storeId), '/');
        $apiKeyCandidates = $this->resolveApiKeyCandidatesForRequest($storeId);

        if ($endpoint === '' || !$apiKeyCandidates) {
            return $this->errorResponse(0, 'API configuration missing');
        }

        if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
            return $this->errorResponse(0, 'Invalid API endpoint');
        }

        $lastResult = $this->errorResponse(0, 'API request not sent');
        $totalCandidates = count($apiKeyCandidates);

        foreach ($apiKeyCandidates as $index => $candidate) {
            $result = $this->sendTelemetryEventWithApiKey(
                $endpoint,
                (string) $candidate['key'],
                $payload
            );

            if (!empty($result['success'])) {
                return $result;
            }

            $lastResult = $result;
            $status = (int)($result['status'] ?? 0);
            if ($index < ($totalCandidates - 1) && in_array($status, [401, 403], true)) {
                continue;
            }

            return $lastResult;
        }

        return $lastResult;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sendCustomerJourneyEvent(array $payload): array
    {
        $storeId = $this->extractStoreId($payload);
        $endpoint = rtrim($this->config->getString(Config::XML_PATH_API_ENDPOINT, $storeId), '/');
        $apiKeyCandidates = $this->resolveApiKeyCandidatesForRequest($storeId);

        if ($endpoint === '' || !$apiKeyCandidates) {
            return $this->errorResponse(0, 'API configuration missing');
        }

        if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
            return $this->errorResponse(0, 'Invalid API endpoint');
        }

        $lastResult = $this->errorResponse(0, 'API request not sent');
        $totalCandidates = count($apiKeyCandidates);

        foreach ($apiKeyCandidates as $index => $candidate) {
            $result = $this->sendCustomerJourneyEventWithApiKey(
                $endpoint,
                (string) $candidate['key'],
                $payload
            );

            if (!empty($result['success'])) {
                return $result;
            }

            $lastResult = $result;
            $status = (int)($result['status'] ?? 0);
            if ($index < ($totalCandidates - 1) && in_array($status, [401, 403], true)) {
                continue;
            }

            return $lastResult;
        }

        return $lastResult;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function health(array $payload = []): array
    {
        $storeId = $this->extractStoreId($payload);
        $result = $this->checkConnectorVersion($storeId);
        if (empty($result['success'])) {
            return $result;
        }
        $data = json_decode((string) ($result['body'] ?? ''), true);
        if (!is_array($data) || ($data['platform'] ?? null) !== 'magento'
            || ($data['installed_version'] ?? null) !== self::CONNECTOR_VERSION) {
            return $this->errorResponse((int) ($result['status'] ?? 0), 'Unexpected API response. Check the NeuroCheckout API endpoint.');
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function buildCompactPayloads(array $payload, int $storeId): array
    {
        $cart = is_array($payload['cart'] ?? null) ? $payload['cart'] : [];
        $customer = is_array($payload['customer'] ?? null) ? $payload['customer'] : [];
        $rules = is_array($payload['rules'] ?? null) ? $payload['rules'] : [];
        $source = is_array($payload['source'] ?? null) ? $payload['source'] : [];
        $runtimeContext = is_array($payload['context'] ?? null) ? $payload['context'] : [];

        $aliasRepo = $this->aliasRepository;
        $itemsBase = [];
        $perItemExtensions = [];
        $legacyExtensions = [];

        $items = is_array($cart['items'] ?? null) ? $cart['items'] : [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $itemsBase[] = [
                (int)($item['product_id'] ?? 0),
                (int)($item['attribute_id'] ?? 0),
                (int)($item['quantity'] ?? 0),
                round((float)($item['unit_price'] ?? 0), 2),
                (string)($item['name'] ?? ''),
                (string)($item['product_url'] ?? ''),
                (string)($item['image_url'] ?? ''),
            ];

            $itemExtensions = [];
            foreach ($item as $key => $value) {
                if (in_array($key, ['product_id', 'attribute_id', 'quantity', 'unit_price', 'name', 'product_url', 'image_url'], true)) {
                    continue;
                }

                $alias = $aliasRepo->getOrCreateAlias($storeId, (string) $key);
                $itemExtensions[$alias] = $value;
                $legacyExtensions[$alias] = $value;
            }

            $perItemExtensions[] = $itemExtensions;
        }

        $aliasMappings = $aliasRepo->getAllMappings($storeId);
        $aliasesForTransport = [];
        foreach ($aliasMappings as $original => $alias) {
            $aliasesForTransport[$alias] = $original;
        }

        $common = [
            'e' => $payload['event_id'] ?? null,
            't' => $payload['event_type'] ?? null,
            'o' => $payload['occurred_at'] ?? null,
            's' => [
                $source['platform'] ?? null,
                $source['shop_id'] ?? null,
                $source['shop_name'] ?? null,
            ],
            'c' => [
                (string)($cart['id'] ?? ''),
                (string)($cart['uid'] ?? ($cart['id'] ?? '')),
                round((float)($cart['total'] ?? 0), 2),
                $itemsBase,
            ],
            'u' => [
                $customer['id'] ?? null,
                $customer['email'] ?? null,
                $customer['first_name'] ?? null,
                $customer['last_name'] ?? null,
                $customer['locale'] ?? null,
                (bool)($customer['is_guest'] ?? true),
                $customer['phone'] ?? null,
                array_key_exists('sms_opt_in', $customer) ? $customer['sms_opt_in'] : null,
            ],
            'r' => [
                (bool)($rules['recovery_enabled'] ?? false),
                (bool)($rules['allow_discount'] ?? false),
                (float)($rules['min_cart_total'] ?? 0),
                (bool)($rules['allow_guest'] ?? false),
                (float)($rules['no_discount_max'] ?? -1),
                (float)($rules['discount_5_min'] ?? 0),
                (float)($rules['discount_5_max'] ?? 0),
                (float)($rules['discount_10_min'] ?? 0),
                (float)($rules['max_discount_percent'] ?? 20),
            ],
            'z' => [
                $runtimeContext['shop_timezone'] ?? null,
                isset($runtimeContext['shop_local_hour']) ? (int)$runtimeContext['shop_local_hour'] : null,
                isset($runtimeContext['currency_precision']) ? (int)$runtimeContext['currency_precision'] : null,
                $runtimeContext['shop_locale'] ?? null,
                $runtimeContext['currency_code'] ?? null,
                $this->extractPrimaryColor($runtimeContext),
            ],
            'aliases' => $aliasesForTransport,
        ];

        $v4Body = $common;
        $v4Body['v'] = self::COMPACT_V4;
        $v4Body['ix'] = $perItemExtensions;
        $v4Body['x'] = $legacyExtensions;

        $v3Body = $common;
        $v3Body['v'] = self::COMPACT_V3;
        $v3Body['x'] = $legacyExtensions;

        return [
            'v4' => $v4Body,
            'v3' => $v3Body,
            'schema_version' => $aliasRepo->getSchemaVersion($storeId),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $compact
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function sendCartWithApiKey(
        string $endpoint,
        string $apiKey,
        array $payload,
        array $compact,
        array $options,
        int $storeId
    ): array {
        $result = $this->sendCompactPayload(
            $endpoint,
            $apiKey,
            $payload,
            (array)($compact['v4'] ?? []),
            (int)($compact['schema_version'] ?? 1),
            self::COMPACT_V4,
            $options,
            $storeId
        );

        $status = (int)($result['status'] ?? 0);
        if (empty($result['success']) && in_array($status, [400, 404, 405, 406, 410, 415, 422, 426, 501], true)) {
            $result = $this->sendCompactPayload(
                $endpoint,
                $apiKey,
                $payload,
                (array)($compact['v3'] ?? []),
                (int)($compact['schema_version'] ?? 1),
                self::COMPACT_V3,
                $options,
                $storeId
            );
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $compactBody
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function sendCompactPayload(
        string $endpoint,
        string $apiKey,
        array $payload,
        array $compactBody,
        int $schemaVersion,
        int $compactVersion,
        array $options,
        int $storeId
    ): array {
        $jsonPayload = json_encode($compactBody, JSON_UNESCAPED_UNICODE);
        if ($jsonPayload === false) {
            return $this->errorResponse(0, 'Payload encoding failed');
        }

        $bodyToSend = $jsonPayload;
        $useGzip = false;
        if (strlen($jsonPayload) > self::GZIP_THRESHOLD) {
            $gz = gzencode($jsonPayload, 6);
            if ($gz !== false) {
                $bodyToSend = $gz;
                $useGzip = true;
            }
        }

        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $message = $timestamp . '.' . $nonce . '.' . $bodyToSend;
        $signature = hash_hmac('sha256', $message, $apiKey);

        $eventId = (string)($payload['event_id'] ?? '');
        $idempotencySeed = $eventId !== '' ? $eventId : $bodyToSend;

        $headers = [
            'Content-Type' => 'application/json',
            'X-API-Key' => $apiKey,
            'X-Neuro-Timestamp' => $timestamp,
            'X-Neuro-Nonce' => $nonce,
            'X-Neuro-Signature' => $signature,
            'X-Neuro-Version' => '7',
            'X-Neuro-Compact' => (string)$compactVersion,
            'X-Neuro-Schema-Version' => (string)$schemaVersion,
            'X-Neuro-Shop' => (string)$storeId,
            'Idempotency-Key' => hash('sha256', $idempotencySeed),
        ];

        if (!empty($options['is_cron_test'])) {
            $headers['X-Neuro-Cron-Test'] = '1';
            $headers['X-Neuro-Test-Mode'] = '1';
        }

        if (!empty($options['is_api_test'])) {
            $headers['X-Neuro-Api-Test'] = '1';
            $headers['X-Neuro-Test-Mode'] = '1';
        }

        if ($useGzip) {
            $headers['Content-Encoding'] = 'gzip';
        }

        return $this->executeWithRetry(
            $endpoint . '/api/v1/events/cart',
            $bodyToSend,
            $headers,
            self::DEFAULT_TIMEOUT,
            self::CONNECT_TIMEOUT
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sendOrderCompletedWithApiKey(string $endpoint, string $apiKey, array $payload): array
    {
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($jsonPayload === false) {
            return $this->errorResponse(0, 'Payload encoding failed');
        }

        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $message = $timestamp . '.' . $nonce . '.' . $jsonPayload;
        $signature = hash_hmac('sha256', $message, $apiKey);

        $eventId = (string)($payload['event_id'] ?? '');
        $idempotencySeed = $eventId !== '' ? $eventId : (string)($payload['order_id'] ?? $jsonPayload);

        $headers = [
            'Content-Type' => 'application/json',
            'X-API-Key' => $apiKey,
            'X-Neuro-Timestamp' => $timestamp,
            'X-Neuro-Nonce' => $nonce,
            'X-Neuro-Signature' => $signature,
            'X-Neuro-Version' => '7',
            'Idempotency-Key' => hash('sha256', $idempotencySeed),
        ];

        return $this->executeRequest(
            $endpoint . '/api/v1/events/order',
            $jsonPayload,
            $headers,
            self::ORDER_TIMEOUT,
            self::ORDER_CONNECT_TIMEOUT
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sendSupportEventWithApiKey(string $endpoint, string $apiKey, array $payload): array
    {
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($jsonPayload === false) {
            return $this->errorResponse(0, 'Payload encoding failed');
        }

        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $message = $timestamp . '.' . $nonce . '.' . $jsonPayload;
        $signature = hash_hmac('sha256', $message, $apiKey);

        $eventId = (string)($payload['event_id'] ?? '');
        $support = is_array($payload['support'] ?? null) ? $payload['support'] : [];
        $idempotencySeed = $eventId !== '' ? $eventId : (string)($support['message_id'] ?? $jsonPayload);

        $headers = [
            'Content-Type' => 'application/json',
            'X-API-Key' => $apiKey,
            'X-Neuro-Timestamp' => $timestamp,
            'X-Neuro-Nonce' => $nonce,
            'X-Neuro-Signature' => $signature,
            'X-Neuro-Version' => '7',
            'Idempotency-Key' => hash('sha256', $idempotencySeed),
        ];

        return $this->executeWithRetry(
            $endpoint . '/api/v1/events/support',
            $jsonPayload,
            $headers,
            self::DEFAULT_TIMEOUT,
            self::CONNECT_TIMEOUT
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sendTelemetryEventWithApiKey(string $endpoint, string $apiKey, array $payload): array
    {
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($jsonPayload === false) {
            return $this->errorResponse(0, 'Payload encoding failed');
        }

        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $message = $timestamp . '.' . $nonce . '.' . $jsonPayload;
        $signature = hash_hmac('sha256', $message, $apiKey);

        $eventId = (string)($payload['event_id'] ?? '');
        $idempotencySeed = $eventId !== '' ? $eventId : (string)($payload['event_type'] ?? $jsonPayload);

        $headers = [
            'Content-Type' => 'application/json',
            'X-API-Key' => $apiKey,
            'X-Neuro-Timestamp' => $timestamp,
            'X-Neuro-Nonce' => $nonce,
            'X-Neuro-Signature' => $signature,
            'X-Neuro-Version' => '7',
            'Idempotency-Key' => hash('sha256', $idempotencySeed),
        ];

        return $this->executeWithRetry(
            $endpoint . '/api/v1/events/telemetry',
            $jsonPayload,
            $headers,
            self::DEFAULT_TIMEOUT,
            self::CONNECT_TIMEOUT
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sendCustomerJourneyEventWithApiKey(string $endpoint, string $apiKey, array $payload): array
    {
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($jsonPayload === false) {
            return $this->errorResponse(0, 'Payload encoding failed');
        }

        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $message = $timestamp . '.' . $nonce . '.' . $jsonPayload;
        $signature = hash_hmac('sha256', $message, $apiKey);

        $eventId = (string)($payload['event_id'] ?? '');
        $idempotencySeed = $eventId !== '' ? $eventId : (string)($payload['event_type'] ?? $jsonPayload);

        $headers = [
            'Content-Type' => 'application/json',
            'X-API-Key' => $apiKey,
            'X-Neuro-Timestamp' => $timestamp,
            'X-Neuro-Nonce' => $nonce,
            'X-Neuro-Signature' => $signature,
            'X-Neuro-Version' => '7',
            'Idempotency-Key' => hash('sha256', $idempotencySeed),
        ];

        return $this->executeWithRetry(
            $endpoint . '/api/v1/events/customer-journey',
            $jsonPayload,
            $headers,
            self::DEFAULT_TIMEOUT,
            self::CONNECT_TIMEOUT
        );
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function executeWithRetry(
        string $url,
        string $body,
        array $headers,
        int $timeout,
        int $connectTimeout
    ): array {
        $attempt = 0;
        $result = $this->errorResponse(0, 'API request not sent');

        while ($attempt <= self::MAX_RETRIES) {
            $result = $this->executeRequest($url, $body, $headers, $timeout, $connectTimeout);
            if (!empty($result['success'])) {
                return $result;
            }

            $status = (int)($result['status'] ?? 0);
            if (!in_array($status, [0, 408, 429, 500, 502, 503, 504], true)) {
                return $result;
            }

            $attempt++;
            usleep(300000 * $attempt);
        }

        return $result;
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function executeRequest(
        string $url,
        string $body,
        array $headers,
        int $timeout,
        int $connectTimeout
    ): array {
        $curl = $this->curlFactory->create();
        $curl->setTimeout($timeout);
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        $curl->setOption(CURLOPT_SSL_VERIFYPEER, true);
        $curl->setOption(CURLOPT_SSL_VERIFYHOST, 2);
        $curl->setHeaders($headers);

        try {
            $curl->post($url, $body);
            $status = (int) $curl->getStatus();
            $responseBody = (string) $curl->getBody();

            if ($status >= 200 && $status < 300) {
                return [
                    'success' => true,
                    'status' => $status,
                    'body' => $responseBody,
                    'error' => null,
                ];
            }

            return $this->errorResponse($status, 'HTTP ' . $status);
        } catch (\Throwable $e) {
            $this->logger->error('[NC] HTTP transport error: ' . $e->getMessage());
            return $this->errorResponse(0, $e->getMessage());
        }
    }

    /**
     * @return array<int, array{name: string, key: string}>
     */
    private function resolveApiKeyCandidatesForRequest(int $storeId): array
    {
        $current = $this->config->getNormalizedApiKey($storeId);
        $pending = $this->config->getNormalizedPendingApiKey($storeId);
        $previous = $this->getValidPreviousApiKeyForFallback($storeId);

        $candidates = [];
        if ($current !== '') {
            $candidates[] = ['name' => 'current', 'key' => $current];
        }
        if ($pending !== '' && !$this->isSameApiKey($pending, $current)) {
            $candidates[] = ['name' => 'pending', 'key' => $pending];
        }
        if ($previous !== '' && !$this->isSameApiKey($previous, $current) && !$this->isSameApiKey($previous, $pending)) {
            $candidates[] = ['name' => 'previous', 'key' => $previous];
        }

        return $candidates;
    }

    private function getValidPreviousApiKeyForFallback(int $storeId): string
    {
        $previous = $this->config->getNormalizedPreviousApiKey($storeId);
        if ($previous === '') {
            return '';
        }

        $validUntil = $this->config->getApiKeyPrevUntil($storeId);
        if ($validUntil <= time()) {
            $this->config->setValue(Config::XML_PATH_API_KEY_PREV, '', $storeId);
            $this->config->setValue(Config::XML_PATH_API_KEY_PREV_UNTIL, 0, $storeId);
            return '';
        }

        return $previous;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractStoreId(array $payload): int
    {
        $source = is_array($payload['source'] ?? null) ? $payload['source'] : [];
        $context = is_array($payload['context'] ?? null) ? $payload['context'] : [];

        foreach ([
            $source['store_id'] ?? null,
            $source['magento_store_id'] ?? null,
            $payload['store_id'] ?? null,
            $context['store_id'] ?? null,
            $source['shop_id'] ?? null,
        ] as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '' && ctype_digit($value)) {
                return (int) $value;
            }
        }

        return 0;
    }

    private function isSameApiKey(string $left, string $right): bool
    {
        if ($left === '' || $right === '' || strlen($left) !== strlen($right)) {
            return false;
        }

        return hash_equals($left, $right);
    }

    /**
     * @param array<string, mixed> $runtimeContext
     */
    private function extractPrimaryColor(array $runtimeContext): ?string
    {
        $palette = $runtimeContext['theme_palette'] ?? null;
        if (is_array($palette)) {
            $paletteColor = trim((string)($palette['primary_color'] ?? ''));
            if ($paletteColor !== '') {
                return $paletteColor;
            }
        }

        $primaryColor = trim((string)($runtimeContext['primary_color'] ?? ''));
        return $primaryColor !== '' ? $primaryColor : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function errorResponse(int $status, string $error): array
    {
        return [
            'success' => false,
            'status' => $status,
            'body' => null,
            'error' => $error,
        ];
    }
}
