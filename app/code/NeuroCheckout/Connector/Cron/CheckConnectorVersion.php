<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Cron;

use Magento\Store\Model\StoreManagerInterface;
use NeuroCheckout\Connector\Model\Config;
use NeuroCheckout\Connector\Model\Http\SecureHttpClient;
use Psr\Log\LoggerInterface;

class CheckConnectorVersion
{
    public function __construct(
        private Config $config,
        private SecureHttpClient $http,
        private StoreManagerInterface $storeManager,
        private LoggerInterface $logger
    ) {}

    public function execute(): void
    {
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int) $store->getId();
            try {
                $result = $this->http->checkConnectorVersion($storeId);
                if (empty($result['success']) || !is_string($result['body'] ?? null)) {
                    continue;
                }
                $payload = json_decode($result['body'], true);
                if (!is_array($payload) || ($payload['platform'] ?? '') !== 'magento') {
                    continue;
                }
                $url = trim((string) ($payload['release_url'] ?? ''));
                if (strpos($url, 'https://github.com/pisob/neurocheckout-connector-magento/releases') !== 0) {
                    continue;
                }
                $this->config->setValue(Config::XML_PATH_UPDATE_CHECKED_AT, time(), $storeId);
                $this->config->setValue(Config::XML_PATH_UPDATE_STATUS, (string) ($payload['status'] ?? 'current'), $storeId);
                $this->config->setValue(Config::XML_PATH_UPDATE_LATEST_VERSION, (string) ($payload['latest_version'] ?? SecureHttpClient::CONNECTOR_VERSION), $storeId);
                $this->config->setValue(Config::XML_PATH_UPDATE_RELEASE_URL, $url, $storeId);
            } catch (\Throwable $e) {
                $this->logger->warning('[NC] Connector version check failed: ' . $e->getMessage());
            }
        }
    }
}
