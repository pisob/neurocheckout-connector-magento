<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Security;

use Magento\Framework\Webapi\Rest\Request;
use NeuroCheckout\Connector\Model\Config;
use NeuroCheckout\Connector\Model\Repository\NonceRepository;
use NeuroCheckout\Connector\Model\Repository\RequestRateLimitRepository;

class RequestSecurityValidator
{
    private const MAX_TIME_DRIFT = 120;
    // Cover the entire accepted past/future timestamp window.
    private const NONCE_TTL = (2 * self::MAX_TIME_DRIFT) + 1;

    private Request $request;
    private Config $config;
    private NonceRepository $nonceRepository;
    private RequestRateLimitRepository $rateLimitRepository;
    private IpResolver $ipResolver;

    public function __construct(
        Request $request,
        Config $config,
        NonceRepository $nonceRepository,
        RequestRateLimitRepository $rateLimitRepository,
        IpResolver $ipResolver
    ) {
        $this->request = $request;
        $this->config = $config;
        $this->nonceRepository = $nonceRepository;
        $this->rateLimitRepository = $rateLimitRepository;
        $this->ipResolver = $ipResolver;
    }

    /**
     * @return array<string, mixed>
     */
    public function validate(
        string $rawBody,
        int $storeId,
        string $nonceNamespace,
        bool $allowPreviousKey = false
    ): array {
        $endpoint = $this->normalizeEndpoint($nonceNamespace);
        $clientIp = $this->resolveClientIp($storeId);
        $retryAfterSeconds = $this->rateLimitRepository->getRetryAfterSeconds($storeId, $endpoint, $clientIp);
        if ($retryAfterSeconds > 0) {
            return $this->fail('Too many invalid requests', 429, $retryAfterSeconds);
        }

        $timestamp = (int) $this->request->getHeader('X-Neuro-Timestamp');
        $nonce = trim((string) $this->request->getHeader('X-Neuro-Nonce'));
        $signature = trim((string) $this->request->getHeader('X-Neuro-Signature'));

        if ($timestamp <= 0 || $nonce === '' || $signature === '') {
            return $this->failAndTrack($storeId, $endpoint, $clientIp, 'Missing security headers', 403);
        }

        if (abs(time() - $timestamp) > self::MAX_TIME_DRIFT) {
            return $this->failAndTrack($storeId, $endpoint, $clientIp, 'Signature expired', 403);
        }

        $currentApiKey = $this->config->getNormalizedApiKey($storeId);
        if ($currentApiKey === '') {
            return $this->fail('Connector API key missing', 409);
        }

        $validApiKeys = [$currentApiKey];
        if ($allowPreviousKey) {
            $previousApiKey = $this->getValidPreviousApiKey($storeId);
            if ($previousApiKey !== '' && !in_array($previousApiKey, $validApiKeys, true)) {
                $validApiKeys[] = $previousApiKey;
            }
        }

        $providedApiKey = preg_replace('/\s+/', '', trim((string) $this->request->getHeader('X-API-Key')));
        $providedApiHash = trim((string) $this->request->getHeader('X-Neuro-ApiKeyHash'));

        $authenticated = false;
        $authMode = null;
        $usedSecret = null;

        if ($providedApiKey !== '') {
            foreach ($validApiKeys as $candidateKey) {
                if ($candidateKey === '' || strlen($candidateKey) !== strlen($providedApiKey)) {
                    continue;
                }
                if (!hash_equals($candidateKey, $providedApiKey)) {
                    continue;
                }

                $expectedSignature = hash_hmac('sha256', $timestamp . '.' . $nonce . '.' . $rawBody, $candidateKey);
                if (!hash_equals($expectedSignature, $signature)) {
                    return $this->failAndTrack($storeId, $endpoint, $clientIp, 'Invalid signature', 403);
                }

                $authenticated = true;
                $authMode = 'api_key';
                $usedSecret = $candidateKey;
                break;
            }

            if (!$authenticated) {
                return $this->failAndTrack($storeId, $endpoint, $clientIp, 'Invalid API key', 403);
            }
        }

        if (!$authenticated) {
            foreach ($validApiKeys as $candidateKey) {
                $candidateHash = hash('sha256', $candidateKey);
                if ($providedApiHash === '' || !hash_equals($candidateHash, $providedApiHash)) {
                    continue;
                }

                $expectedSignature = hash_hmac('sha256', $timestamp . '.' . $nonce . '.' . $rawBody, $candidateHash);
                if (!hash_equals($expectedSignature, $signature)) {
                    return $this->failAndTrack($storeId, $endpoint, $clientIp, 'Invalid signature', 403);
                }

                $authenticated = true;
                $authMode = 'api_key_hash';
                $usedSecret = $candidateHash;
                break;
            }

            if (!$authenticated) {
                return $this->failAndTrack($storeId, $endpoint, $clientIp, 'Invalid API hash', 403);
            }
        }

        $this->nonceRepository->purgeExpired();
        $isNonceFresh = $this->nonceRepository->register(
            $nonceNamespace . '-' . $nonce,
            self::NONCE_TTL,
            $storeId
        );

        if (!$isNonceFresh) {
            return $this->failAndTrack($storeId, $endpoint, $clientIp, 'Replay detected', 409);
        }

        $this->rateLimitRepository->clear($storeId, $endpoint, $clientIp);

        return [
            'success' => true,
            'status' => 200,
            'auth_mode' => $authMode,
            'signature_secret' => $usedSecret,
        ];
    }

    private function getValidPreviousApiKey(int $storeId): string
    {
        $previousApiKey = $this->config->getNormalizedPreviousApiKey($storeId);
        if ($previousApiKey === '') {
            return '';
        }

        $validUntil = $this->config->getApiKeyPrevUntil($storeId);
        if ($validUntil <= time()) {
            $this->config->setValue(Config::XML_PATH_API_KEY_PREV, '', $storeId);
            $this->config->setValue(Config::XML_PATH_API_KEY_PREV_UNTIL, 0, $storeId);
            return '';
        }

        return $previousApiKey;
    }

    /**
     * @return array<string, mixed>
     */
    private function fail(string $message, int $status, ?int $retryAfterSeconds = null): array
    {
        $payload = [
            'success' => false,
            'status' => $status,
            'error' => $message,
        ];

        if ($retryAfterSeconds !== null && $retryAfterSeconds > 0) {
            $payload['retry_after_seconds'] = $retryAfterSeconds;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function failAndTrack(int $storeId, string $endpoint, string $clientIp, string $message, int $status): array
    {
        $retryAfterSeconds = $this->rateLimitRepository->recordFailure($storeId, $endpoint, $clientIp, $message);
        if ($retryAfterSeconds > 0) {
            return $this->fail('Too many invalid requests', 429, $retryAfterSeconds);
        }

        return $this->fail($message, $status);
    }

    private function normalizeEndpoint(string $nonceNamespace): string
    {
        $endpoint = strtolower(trim($nonceNamespace));
        $endpoint = preg_replace('/[^a-z0-9_-]+/', '-', $endpoint) ?: 'unknown';

        return substr($endpoint, 0, 32);
    }

    private function resolveClientIp(int $storeId): string
    {
        $trustedProxyRules = $this->ipResolver->parseRules(
            $this->config->getString(Config::XML_PATH_TRUSTED_PROXY_IPS, $storeId)
        );

        return $this->ipResolver->resolve($_SERVER, $trustedProxyRules);
    }
}
