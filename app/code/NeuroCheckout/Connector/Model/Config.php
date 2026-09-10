<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    private const SECRET_VALUE_PREFIX = 'ncenc:';

    public const XML_PATH_API_ENDPOINT = 'neurocheckoutconnector/general/api_endpoint';
    public const XML_PATH_API_KEY = 'neurocheckoutconnector/general/api_key';
    public const XML_PATH_API_KEY_NEXT = 'neurocheckoutconnector/general/api_key_next';
    public const XML_PATH_API_KEY_ROTATION_ID = 'neurocheckoutconnector/general/api_key_rotation_id';
    public const XML_PATH_API_KEY_PREV = 'neurocheckoutconnector/general/api_key_prev';
    public const XML_PATH_API_KEY_PREV_UNTIL = 'neurocheckoutconnector/general/api_key_prev_until';
    public const XML_PATH_SHOP_EXTERNAL_ID = 'neurocheckoutconnector/general/shop_external_id';
    public const XML_PATH_API_TEST_VALIDATED_AT = 'neurocheckoutconnector/general/api_test_validated_at';
    public const XML_PATH_API_TEST_VALIDATION_FINGERPRINT = 'neurocheckoutconnector/general/api_test_validation_fingerprint';
    public const XML_PATH_INTERNAL_SECRET = 'neurocheckoutconnector/general/internal_secret';
    public const XML_PATH_OPAQUE_RECOVERY_LINKS = 'neurocheckoutconnector/general/opaque_recovery_links';

    public const XML_PATH_RECOVERY_ENABLED = 'neurocheckoutconnector/ia/recovery_enabled';
    public const XML_PATH_ENABLE_DISCOUNT = 'neurocheckoutconnector/ia/enable_discount';
    public const XML_PATH_MIN_CART_TOTAL = 'neurocheckoutconnector/ia/min_cart_total';
    public const XML_PATH_ALLOW_GUEST = 'neurocheckoutconnector/ia/allow_guest';
    public const XML_PATH_NO_DISCOUNT_MAX = 'neurocheckoutconnector/ia/no_discount_max';
    public const XML_PATH_DISCOUNT_5_MIN = 'neurocheckoutconnector/ia/discount_5_min';
    public const XML_PATH_DISCOUNT_5_MAX = 'neurocheckoutconnector/ia/discount_5_max';
    public const XML_PATH_DISCOUNT_10_MIN = 'neurocheckoutconnector/ia/discount_10_min';
    public const XML_PATH_MAX_DISCOUNT_PERCENT = 'neurocheckoutconnector/ia/max_discount_percent';

    public const XML_PATH_EXECUTION_MODE = 'neurocheckoutconnector/execution/execution_mode';
    public const XML_PATH_CRON_INTERVAL_SECONDS = 'neurocheckoutconnector/execution/cron_interval_seconds';
    public const XML_PATH_EVENT_RETENTION_DAYS = 'neurocheckoutconnector/execution/event_retention_days';
    public const XML_PATH_PURGE_BATCH_SIZE = 'neurocheckoutconnector/execution/purge_batch_size';
    public const XML_PATH_CB_FAILURE_THRESHOLD = 'neurocheckoutconnector/execution/cb_failure_threshold';
    public const XML_PATH_CB_COOLDOWN_SECONDS = 'neurocheckoutconnector/execution/cb_cooldown_seconds';
    public const XML_PATH_DEBUG_MODE = 'neurocheckoutconnector/execution/debug_mode';
    public const XML_PATH_DEBUG_ADVANCED = 'neurocheckoutconnector/execution/debug_advanced';
    public const XML_PATH_CRON_ALLOWED_IPS = 'neurocheckoutconnector/execution/cron_allowed_ips';
    public const XML_PATH_TRUSTED_PROXY_IPS = 'neurocheckoutconnector/execution/trusted_proxy_ips';
    public const XML_PATH_CRON_TOKEN = 'neurocheckoutconnector/execution/cron_token';
    public const XML_PATH_LAST_RUN = 'neurocheckoutconnector/execution/last_run';
    public const XML_PATH_TELEMETRY_ENABLED = 'neurocheckoutconnector/execution/telemetry_enabled';
    public const XML_PATH_CUSTOMER_JOURNEY_ENABLED = 'neurocheckoutconnector/execution/customer_journey_enabled';

    private ScopeConfigInterface $scopeConfig;
    private WriterInterface $writer;
    private EncryptorInterface $encryptor;
    private TypeListInterface $cacheTypeList;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        WriterInterface $writer,
        EncryptorInterface $encryptor,
        TypeListInterface $cacheTypeList
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->writer = $writer;
        $this->encryptor = $encryptor;
        $this->cacheTypeList = $cacheTypeList;
    }

    public function getString(string $path, ?int $storeId = null): string
    {
        $rawValue = trim((string) $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORES, $storeId));
        if (!$this->isEncryptedPath($path)) {
            return $rawValue;
        }

        return $this->decodeSecretValue($path, $rawValue, $storeId);
    }

    public function getInt(string $path, ?int $storeId = null): int
    {
        return (int) $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORES, $storeId);
    }

    public function getFloat(string $path, ?int $storeId = null): float
    {
        return (float) $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORES, $storeId);
    }

    public function getBool(string $path, ?int $storeId = null): bool
    {
        if ($path === self::XML_PATH_RECOVERY_ENABLED) {
            return true;
        }

        return (bool) $this->getInt($path, $storeId);
    }

    public function isOpaqueRecoveryLinksEnabled(?int $storeId = null): bool
    {
        $value = $this->getString(self::XML_PATH_OPAQUE_RECOVERY_LINKS, $storeId);
        if ($value === '') {
            return true;
        }

        return $value !== '0';
    }

    public function setValue(string $path, $value, ?int $storeId = null): void
    {
        if ($path === self::XML_PATH_RECOVERY_ENABLED) {
            $value = 1;
        }

        $value = $this->prepareValueForStorage($path, (string) $value);

        if ($storeId === null) {
            $this->writer->save($path, (string) $value, ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
            $this->cleanConfigCache();
            return;
        }

        $this->writer->save($path, (string) $value, ScopeInterface::SCOPE_STORES, $storeId);
        $this->cleanConfigCache();
    }

    public function isIaConfigurationReady(?int $storeId = null): bool
    {
        return $this->getIaConfigurationIssues($storeId) === [];
    }

    public function isApiConfigurationReady(?int $storeId = null): bool
    {
        return $this->getApiConfigurationIssues($storeId) === [];
    }

    /**
     * @return list<string>
     */
    public function getApiConfigurationIssues(?int $storeId = null): array
    {
        $issues = [];
        $endpoint = $this->getString(self::XML_PATH_API_ENDPOINT, $storeId);
        $apiKey = $this->getNormalizedApiKey($storeId);
        $shopExternalId = $this->getString(self::XML_PATH_SHOP_EXTERNAL_ID, $storeId);

        if ($endpoint === '') {
            $issues[] = (string) __('Le champ "API Endpoint" est obligatoire.');
        } elseif (!$this->isValidHttpUrl($endpoint)) {
            $issues[] = (string) __('Le champ "API Endpoint" doit etre une URL valide en http ou https.');
        }

        if ($apiKey === '') {
            $issues[] = (string) __('Le champ "API Key" est obligatoire.');
        }

        if ($shopExternalId === '') {
            $issues[] = (string) __('Le champ "Shop External ID" est obligatoire.');
        }

        return $issues;
    }

    /**
     * @return list<string>
     */
    public function getIaConfigurationIssues(?int $storeId = null): array
    {
        $issues = [];

        if (!$this->getBool(self::XML_PATH_RECOVERY_ENABLED, $storeId)) {
            $issues[] = (string) __('La relance automatique doit rester active.');
        }

        $minCart = $this->getString(self::XML_PATH_MIN_CART_TOTAL, $storeId);
        if ($minCart === '') {
            $issues[] = (string) __('Le champ "Montant minimum du panier" est obligatoire.');
        } elseif (!is_numeric($minCart) || (float) $minCart < 0) {
            $issues[] = (string) __('Le champ "Montant minimum du panier" doit etre un nombre superieur ou egal a 0.');
        }

        $maxDiscount = $this->getString(self::XML_PATH_MAX_DISCOUNT_PERCENT, $storeId);
        if ($maxDiscount === '') {
            $issues[] = (string) __('Le champ "Reduction maximale IA (%)" est obligatoire.');
        } elseif (!is_numeric($maxDiscount) || (float) $maxDiscount < 0 || (float) $maxDiscount > 100) {
            $issues[] = (string) __('Le champ "Reduction maximale IA (%)" doit etre un nombre entre 0 et 100.');
        }

        return $issues;
    }

    public function getExecutionMode(?int $storeId = null): string
    {
        $value = $this->getString(self::XML_PATH_EXECUTION_MODE, $storeId);
        return in_array($value, ['cron_module', 'cron'], true) ? $value : 'cron_module';
    }

    public function isDebugModeEnabled(?int $storeId = null): bool
    {
        return $this->getBool(self::XML_PATH_DEBUG_MODE, $storeId);
    }

    public function isDebugAdvancedEnabled(?int $storeId = null): bool
    {
        return $this->getBool(self::XML_PATH_DEBUG_ADVANCED, $storeId)
            && !$this->isDebugModeEnabled($storeId);
    }

    public function isTelemetryEnabled(?int $storeId = null): bool
    {
        return $this->getString(self::XML_PATH_TELEMETRY_ENABLED, $storeId) !== '0';
    }

    public function isCustomerJourneyEnabled(?int $storeId = null): bool
    {
        return $this->getString(self::XML_PATH_CUSTOMER_JOURNEY_ENABLED, $storeId) !== '0';
    }

    public function isApiTestValidationCurrent(?int $storeId = null): bool
    {
        $validatedAt = $this->getInt(self::XML_PATH_API_TEST_VALIDATED_AT, $storeId);
        $storedFingerprint = $this->getString(self::XML_PATH_API_TEST_VALIDATION_FINGERPRINT, $storeId);
        $endpoint = $this->getString(self::XML_PATH_API_ENDPOINT, $storeId);
        $apiKey = $this->getNormalizedApiKey($storeId);
        $shopExternalId = $this->getString(self::XML_PATH_SHOP_EXTERNAL_ID, $storeId);

        if ($validatedAt <= 0 || $storedFingerprint === '' || $endpoint === '' || $apiKey === '' || $shopExternalId === '') {
            return false;
        }

        $expectedFingerprint = hash('sha256', implode('|', [$endpoint, $apiKey, $shopExternalId]));

        return hash_equals($expectedFingerprint, $storedFingerprint);
    }

    public function getApiTestValidatedAt(?int $storeId = null): int
    {
        return $this->getInt(self::XML_PATH_API_TEST_VALIDATED_AT, $storeId);
    }

    public function getOrCreateInternalSecret(?int $storeId = null): string
    {
        return $this->getOrCreateSecureValue(self::XML_PATH_INTERNAL_SECRET, $storeId, 32);
    }

    public function getOrCreateCronToken(?int $storeId = null): string
    {
        return $this->getOrCreateSecureValue(self::XML_PATH_CRON_TOKEN, $storeId, 32);
    }

    public function getNormalizedApiKey(?int $storeId = null): string
    {
        return preg_replace('/\s+/', '', $this->getString(self::XML_PATH_API_KEY, $storeId));
    }

    public function getNormalizedPendingApiKey(?int $storeId = null): string
    {
        return preg_replace('/\s+/', '', $this->getString(self::XML_PATH_API_KEY_NEXT, $storeId));
    }

    public function getNormalizedPreviousApiKey(?int $storeId = null): string
    {
        return preg_replace('/\s+/', '', $this->getString(self::XML_PATH_API_KEY_PREV, $storeId));
    }

    public function getApiKeyPrevUntil(?int $storeId = null): int
    {
        return $this->getInt(self::XML_PATH_API_KEY_PREV_UNTIL, $storeId);
    }

    private function isValidHttpUrl(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true);
    }

    private function cleanConfigCache(): void
    {
        try {
            $this->cacheTypeList->cleanType('config');
        } catch (\Throwable $e) {
            // A stale config cache must not break the admin save/test flow.
        }
    }

    private function getOrCreateSecureValue(string $path, ?int $storeId, int $bytes): string
    {
        $existing = $this->getString($path, $storeId);
        if ($existing !== '') {
            return $existing;
        }

        try {
            $value = bin2hex(random_bytes($bytes));
        } catch (\Throwable $e) {
            $value = hash('sha256', $path . '|' . microtime(true) . '|' . mt_rand());
        }

        $this->setValue($path, $value, $storeId);

        $persisted = $this->getString($path, $storeId);
        return $persisted !== '' ? $persisted : $value;
    }

    private function isEncryptedPath(string $path): bool
    {
        return in_array($path, [
            self::XML_PATH_API_KEY,
            self::XML_PATH_API_KEY_NEXT,
            self::XML_PATH_API_KEY_PREV,
            self::XML_PATH_INTERNAL_SECRET,
            self::XML_PATH_CRON_TOKEN,
        ], true);
    }

    private function prepareValueForStorage(string $path, string $value): string
    {
        if (!$this->isEncryptedPath($path)) {
            return $value;
        }

        $normalized = trim($value);
        if ($normalized === '') {
            return '';
        }

        if (str_starts_with($normalized, self::SECRET_VALUE_PREFIX)) {
            return $normalized;
        }

        return self::SECRET_VALUE_PREFIX . $this->encryptor->encrypt($normalized);
    }

    private function decodeSecretValue(string $path, string $value, ?int $storeId): string
    {
        if ($value === '') {
            return '';
        }

        if (str_starts_with($value, self::SECRET_VALUE_PREFIX)) {
            $encryptedValue = substr($value, strlen(self::SECRET_VALUE_PREFIX));
            try {
                return trim((string) $this->encryptor->decrypt($encryptedValue));
            } catch (\Throwable $e) {
                return '';
            }
        }

        // Lazy migration from older plaintext storage.
        $this->setValue($path, $value, $storeId);
        return $value;
    }
}
