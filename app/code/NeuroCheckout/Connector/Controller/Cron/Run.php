<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Controller\Cron;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use NeuroCheckout\Connector\Model\Application\CronExecutor;
use NeuroCheckout\Connector\Model\Config;
use NeuroCheckout\Connector\Model\Repository\NonceRepository;
use NeuroCheckout\Connector\Model\Repository\RequestRateLimitRepository;
use NeuroCheckout\Connector\Model\Security\IpResolver;

class Run extends Action
{
    private const NONCE_TTL = 120;
    private const MAX_TIME_DRIFT = 60;

    private JsonFactory $jsonFactory;
    private CronExecutor $cronExecutor;
    private Config $config;
    private NonceRepository $nonceRepository;
    private RequestRateLimitRepository $rateLimitRepository;
    private IpResolver $ipResolver;

    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        CronExecutor $cronExecutor,
        Config $config,
        NonceRepository $nonceRepository,
        RequestRateLimitRepository $rateLimitRepository,
        IpResolver $ipResolver
    ) {
        parent::__construct($context);
        $this->jsonFactory = $jsonFactory;
        $this->cronExecutor = $cronExecutor;
        $this->config = $config;
        $this->nonceRepository = $nonceRepository;
        $this->rateLimitRepository = $rateLimitRepository;
        $this->ipResolver = $ipResolver;
    }

    public function execute(): Json
    {
        $storeId = (int) $this->getRequest()->getParam('store_id');
        if ($storeId <= 0) {
            return $this->jsonError(422, 'store_id manquant');
        }

        $isTest = (bool) filter_var($this->getRequest()->getParam('test', 0), FILTER_VALIDATE_BOOLEAN);
        $isDebugForce = (bool) filter_var($this->getRequest()->getParam('debug_force', 0), FILTER_VALIDATE_BOOLEAN);

        if ($isTest && !$this->config->isDebugModeEnabled($storeId)) {
            return $this->jsonError(403, 'Mode debug cron desactive');
        }

        if ($isDebugForce && !$this->config->isDebugAdvancedEnabled($storeId)) {
            return $this->jsonError(403, 'Mode debug avance desactive');
        }

        if (!$isTest && !$isDebugForce && $this->config->getExecutionMode($storeId) !== 'cron') {
            return $this->jsonError(403, 'Le mode cron serveur manuel n est pas actif');
        }

        $token = trim((string) $this->getRequest()->getParam('token'));
        $timestamp = (int) $this->getRequest()->getParam('ts');
        $nonce = trim((string) $this->getRequest()->getParam('nonce'));
        $signature = trim((string) $this->getRequest()->getParam('sig'));
        $expectedToken = $this->config->getOrCreateCronToken($storeId);
        $apiKey = $this->config->getNormalizedApiKey($storeId);
        $clientIp = $this->getRealIp($storeId);
        $retryAfterSeconds = $this->rateLimitRepository->getRetryAfterSeconds($storeId, 'cron', $clientIp);
        if ($retryAfterSeconds > 0) {
            return $this->jsonRateLimited($retryAfterSeconds);
        }

        if ($expectedToken === '' || !hash_equals($expectedToken, $token)) {
            return $this->registerFailureAndRespond($storeId, 'cron', $clientIp, 'Token invalide', 403);
        }

        if ($timestamp <= 0 || $nonce === '' || $signature === '' || $apiKey === '') {
            return $this->registerFailureAndRespond($storeId, 'cron', $clientIp, 'Parametres de securite manquants', 403);
        }

        if (abs(time() - $timestamp) > self::MAX_TIME_DRIFT) {
            return $this->registerFailureAndRespond($storeId, 'cron', $clientIp, 'Signature expiree', 403);
        }

        if (!$this->isAllowedCronIp($clientIp, $storeId)) {
            return $this->registerFailureAndRespond($storeId, 'cron', $clientIp, 'IP source du cron non autorisee', 403);
        }

        $expectedSignature = hash_hmac('sha256', $timestamp . '.' . $nonce . '.' . $token, $apiKey);
        if (!hash_equals($expectedSignature, $signature)) {
            return $this->registerFailureAndRespond($storeId, 'cron', $clientIp, 'Signature invalide', 403);
        }

        $this->nonceRepository->purgeExpired();
        if (!$this->nonceRepository->register('cron-' . $nonce, self::NONCE_TTL, $storeId)) {
            return $this->registerFailureAndRespond($storeId, 'cron', $clientIp, 'Replay detecte', 409);
        }

        $this->rateLimitRepository->clear($storeId, 'cron', $clientIp);

        return $this->jsonFactory->create()->setData(
            $this->cronExecutor->executeStore($storeId, $isTest, $isDebugForce)
        );
    }

    private function jsonError(int $status, string $message): Json
    {
        return $this->jsonFactory->create()->setHttpResponseCode($status)->setData([
            'success' => false,
            'status' => $status,
            'error' => $message,
            'message' => $message,
            'timestamp' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function getRealIp(int $storeId): string
    {
        $trustedProxyRules = $this->ipResolver->parseRules(
            $this->config->getString(Config::XML_PATH_TRUSTED_PROXY_IPS, $storeId)
        );

        return $this->ipResolver->resolve($_SERVER, $trustedProxyRules);
    }

    private function isAllowedCronIp(string $clientIp, int $storeId): bool
    {
        $rules = preg_split('/[\s,;]+/', trim($this->config->getString(Config::XML_PATH_CRON_ALLOWED_IPS, $storeId)));
        $rules = array_values(array_filter(array_map('trim', is_array($rules) ? $rules : [])));

        if (!$rules) {
            return true;
        }

        foreach ($rules as $rule) {
            if ($this->ipMatchesRule($clientIp, $rule)) {
                return true;
            }
        }

        return false;
    }

    private function ipMatchesRule(string $clientIp, string $rule): bool
    {
        if ($rule === '') {
            return false;
        }

        if (strpos($rule, '/') === false) {
            return hash_equals($rule, $clientIp);
        }

        [$subnet, $prefix] = array_pad(explode('/', $rule, 2), 2, null);
        $prefix = is_numeric($prefix) ? (int) $prefix : -1;

        $ipBinary = @inet_pton($clientIp);
        $subnetBinary = @inet_pton((string) $subnet);
        if ($ipBinary === false || $subnetBinary === false || strlen($ipBinary) !== strlen($subnetBinary)) {
            return false;
        }

        $maxBits = strlen($ipBinary) * 8;
        if ($prefix < 0 || $prefix > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($fullBytes > 0 && substr($ipBinary, 0, $fullBytes) !== substr($subnetBinary, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return
            (ord($ipBinary[$fullBytes]) & $mask)
            === (ord($subnetBinary[$fullBytes]) & $mask);
    }

    private function registerFailureAndRespond(
        int $storeId,
        string $endpoint,
        string $clientIp,
        string $message,
        int $status
    ): Json {
        $retryAfterSeconds = $this->rateLimitRepository->recordFailure($storeId, $endpoint, $clientIp, $message);
        if ($retryAfterSeconds > 0) {
            return $this->jsonRateLimited($retryAfterSeconds);
        }

        return $this->jsonError($status, $message);
    }

    private function jsonRateLimited(int $retryAfterSeconds): Json
    {
        return $this->jsonFactory->create()->setHttpResponseCode(429)->setData([
            'success' => false,
            'status' => 429,
            'error' => 'Trop de requetes invalides',
            'message' => 'Trop de requetes invalides',
            'retry_after_seconds' => max(1, $retryAfterSeconds),
            'timestamp' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
