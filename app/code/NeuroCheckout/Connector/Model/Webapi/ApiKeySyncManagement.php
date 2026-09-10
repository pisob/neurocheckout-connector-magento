<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Webapi;

use Magento\Framework\App\Cache\Type\Config as ConfigCacheType;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Webapi\Exception;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Store\Model\StoreManagerInterface;
use NeuroCheckout\Connector\Api\ApiKeySyncManagementInterface;
use NeuroCheckout\Connector\Model\Config;
use NeuroCheckout\Connector\Model\Security\RequestSecurityValidator;
use Psr\Log\LoggerInterface;

class ApiKeySyncManagement extends AbstractEndpoint implements ApiKeySyncManagementInterface
{
    private const MIN_API_KEY_LENGTH = 32;
    private const MAX_API_KEY_LENGTH = 512;
    private const PREVIOUS_KEY_GRACE_SECONDS = 900;

    private RequestSecurityValidator $securityValidator;
    private Config $config;
    private ResourceConnection $resource;
    private ReinitableConfigInterface $reinitableConfig;
    private TypeListInterface $cacheTypeList;
    private LoggerInterface $logger;

    public function __construct(
        Request $request,
        StoreManagerInterface $storeManager,
        RequestSecurityValidator $securityValidator,
        Config $config,
        ResourceConnection $resource,
        ReinitableConfigInterface $reinitableConfig,
        TypeListInterface $cacheTypeList,
        LoggerInterface $logger
    ) {
        parent::__construct($request, $storeManager);
        $this->securityValidator = $securityValidator;
        $this->config = $config;
        $this->resource = $resource;
        $this->reinitableConfig = $reinitableConfig;
        $this->cacheTypeList = $cacheTypeList;
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

            $currentApiKey = $this->config->getNormalizedApiKey($storeId);
            if ($currentApiKey === '') {
                return $this->response(false, 409, 'Connector API key missing');
            }

            $security = $this->securityValidator->validate($this->getRawBody(), $storeId, 'apikeysync', true);
            if (empty($security['success'])) {
                return $this->response(false, (int)($security['status'] ?? 403), (string)($security['error'] ?? 'forbidden'));
            }

            $phase = strtolower(trim((string)($payload['phase'] ?? 'direct')));
            if (!in_array($phase, ['prepare', 'finalize', 'direct'], true)) {
                $phase = 'direct';
            }

            $newApiKey = preg_replace('/\s+/', '', trim((string)($payload['new_api_key'] ?? '')));
            if ($newApiKey === '') {
                return $this->response(false, 422, 'Missing new_api_key');
            }

            $length = strlen($newApiKey);
            if ($length < self::MIN_API_KEY_LENGTH || $length > self::MAX_API_KEY_LENGTH) {
                return $this->response(false, 422, 'Invalid new_api_key length');
            }

            $providedHash = trim((string)($payload['new_api_key_hash'] ?? ''));
            $computedHash = hash('sha256', $newApiKey);
            if ($providedHash !== '' && !hash_equals($providedHash, $computedHash)) {
                return $this->response(false, 422, 'new_api_key_hash mismatch');
            }

            if (hash_equals(hash('sha256', $currentApiKey), $computedHash)) {
                $this->clearPendingRotationState($storeId);
                $this->refreshConfigRuntime();
                return $this->response(true, 200, null, [
                    'status' => 'already_current',
                    'phase' => $phase,
                    'api_key_hash_prefix' => substr($computedHash, 0, 10),
                ]);
            }

            $rotationId = trim((string)($payload['rotation_id'] ?? ($payload['request_uid'] ?? '')));

            if ($phase === 'prepare') {
                $this->config->setValue(Config::XML_PATH_API_KEY_NEXT, $newApiKey, $storeId);
                $this->config->setValue(Config::XML_PATH_API_KEY_ROTATION_ID, $rotationId, $storeId);
                $this->refreshConfigRuntime();

                return $this->response(true, 200, null, [
                    'status' => 'prepared',
                    'phase' => 'prepare',
                    'rotation_id' => $rotationId,
                    'api_key_hash_prefix' => substr($computedHash, 0, 10),
                ]);
            }

            if ($phase === 'finalize') {
                $pendingApiKey = $this->config->getNormalizedPendingApiKey($storeId);
                if ($pendingApiKey === '') {
                    return $this->response(false, 409, 'Pending rotation not prepared');
                }

                $storedRotationId = trim($this->config->getString(Config::XML_PATH_API_KEY_ROTATION_ID, $storeId));
                if ($rotationId === '' || $storedRotationId === '') {
                    return $this->response(false, 409, 'Pending rotation context missing');
                }

                if (!hash_equals($storedRotationId, $rotationId)) {
                    return $this->response(false, 409, 'Rotation ID mismatch');
                }

                $candidateApiKey = $pendingApiKey;

                if (!hash_equals(hash('sha256', $candidateApiKey), $computedHash)) {
                    return $this->response(false, 409, 'Pending key mismatch');
                }

                $this->storePreviousApiKeyForGrace($storeId, $currentApiKey, $candidateApiKey);
                $this->config->setValue(Config::XML_PATH_API_KEY, $candidateApiKey, $storeId);
                $this->clearPendingRotationState($storeId);
                $this->refreshApiTestValidationStateAfterApiKeySync($storeId, $candidateApiKey);
                $this->refreshConfigRuntime();

                return $this->response(true, 200, null, [
                    'status' => 'updated',
                    'phase' => 'finalize',
                    'rotation_id' => $storedRotationId,
                    'api_key_hash_prefix' => substr($computedHash, 0, 10),
                ]);
            }

            $this->storePreviousApiKeyForGrace($storeId, $currentApiKey, $newApiKey);
            $this->config->setValue(Config::XML_PATH_API_KEY, $newApiKey, $storeId);
            $this->clearPendingRotationState($storeId);
            $this->refreshApiTestValidationStateAfterApiKeySync($storeId, $newApiKey);
            $this->refreshConfigRuntime();

            return $this->response(true, 200, null, [
                'status' => 'updated',
                'phase' => 'direct',
                'api_key_hash_prefix' => substr($computedHash, 0, 10),
            ]);
        } catch (Exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('[NC] ApiKeySync endpoint error: ' . $e->getMessage());
            return $this->response(false, 500, 'Internal error');
        }
    }

    private function clearPendingRotationState(int $storeId): void
    {
        $this->config->setValue(Config::XML_PATH_API_KEY_NEXT, '', $storeId);
        $this->config->setValue(Config::XML_PATH_API_KEY_ROTATION_ID, '', $storeId);
    }

    private function storePreviousApiKeyForGrace(int $storeId, string $currentApiKey, string $newApiKey): void
    {
        if ($currentApiKey === '' || $this->isSameApiKey($currentApiKey, $newApiKey)) {
            $this->config->setValue(Config::XML_PATH_API_KEY_PREV, '', $storeId);
            $this->config->setValue(Config::XML_PATH_API_KEY_PREV_UNTIL, 0, $storeId);
            return;
        }

        $this->config->setValue(Config::XML_PATH_API_KEY_PREV, $currentApiKey, $storeId);
        $this->config->setValue(Config::XML_PATH_API_KEY_PREV_UNTIL, (string)(time() + self::PREVIOUS_KEY_GRACE_SECONDS), $storeId);
    }

    private function refreshApiTestValidationStateAfterApiKeySync(int $storeId, string $activeApiKey): void
    {
        $validatedAt = $this->config->getInt(Config::XML_PATH_API_TEST_VALIDATED_AT, $storeId);
        if ($validatedAt <= 0) {
            if (!$this->hasOperationalExecutionEvidence($storeId)) {
                $this->config->setValue(Config::XML_PATH_API_TEST_VALIDATION_FINGERPRINT, '', $storeId);
                return;
            }
            $validatedAt = time();
        }

        $endpoint = $this->config->getString(Config::XML_PATH_API_ENDPOINT, $storeId);
        $shopExternalId = $this->config->getString(Config::XML_PATH_SHOP_EXTERNAL_ID, $storeId);
        $activeApiKey = preg_replace('/\s+/', '', trim($activeApiKey));

        if ($endpoint === '' || $shopExternalId === '' || $activeApiKey === '') {
            $this->config->setValue(Config::XML_PATH_API_TEST_VALIDATED_AT, 0, $storeId);
            $this->config->setValue(Config::XML_PATH_API_TEST_VALIDATION_FINGERPRINT, '', $storeId);
            return;
        }

        $fingerprint = hash('sha256', implode('|', [$endpoint, $activeApiKey, $shopExternalId]));
        $this->config->setValue(Config::XML_PATH_API_TEST_VALIDATION_FINGERPRINT, $fingerprint, $storeId);
        $this->config->setValue(Config::XML_PATH_API_TEST_VALIDATED_AT, (string)($validatedAt > 0 ? $validatedAt : time()), $storeId);
    }

    private function hasOperationalExecutionEvidence(int $storeId): bool
    {
        $connection = $this->resource->getConnection();
        $cronTable = $this->resource->getTableName('neurocheckout_cron_log');
        $eventTable = $this->resource->getTableName('neurocheckout_event');

        $cronCount = (int) $connection->fetchOne(
            $connection->select()
                ->from($cronTable, ['cnt' => 'COUNT(*)'])
                ->where('store_id = ?', max(0, $storeId))
        );
        if ($cronCount > 0) {
            return true;
        }

        $sentCount = (int) $connection->fetchOne(
            $connection->select()
                ->from($eventTable, ['cnt' => 'COUNT(*)'])
                ->where('store_id = ?', max(0, $storeId))
                ->where('status IN (?)', ['sent', 'cleared'])
        );

        return $sentCount > 0;
    }

    private function refreshConfigRuntime(): void
    {
        $this->cacheTypeList->cleanType(ConfigCacheType::TYPE_IDENTIFIER);
        $this->reinitableConfig->reinit();
    }

    private function isSameApiKey(string $left, string $right): bool
    {
        if ($left === '' || $right === '' || strlen($left) !== strlen($right)) {
            return false;
        }

        return hash_equals($left, $right);
    }
}
