<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use NeuroCheckout\Connector\Model\Config;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private ScopeConfigInterface&MockObject $scopeConfig;
    private WriterInterface&MockObject $writer;
    private EncryptorInterface&MockObject $encryptor;
    private Config $config;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->writer = $this->createMock(WriterInterface::class);
        $this->encryptor = $this->createMock(EncryptorInterface::class);
        $this->config = new Config($this->scopeConfig, $this->writer, $this->encryptor);
    }

    public function testGetIaConfigurationIssuesReturnsExpectedMessages(): void
    {
        $storeId = 7;
        $this->scopeConfig->method('getValue')->willReturnMap([
            [Config::XML_PATH_RECOVERY_ENABLED, ScopeInterface::SCOPE_STORES, $storeId, 1],
            [Config::XML_PATH_MIN_CART_TOTAL, ScopeInterface::SCOPE_STORES, $storeId, ''],
            [Config::XML_PATH_MAX_DISCOUNT_PERCENT, ScopeInterface::SCOPE_STORES, $storeId, '150'],
        ]);

        $this->assertSame([
            'Le champ "Montant minimum du panier" est obligatoire.',
            'Le champ "Reduction maximale IA (%)" doit etre un nombre entre 0 et 100.',
        ], $this->config->getIaConfigurationIssues($storeId));
    }

    public function testIsApiTestValidationCurrentReturnsFalseWhenFingerprintDoesNotMatch(): void
    {
        $storeId = 11;
        $this->scopeConfig->method('getValue')->willReturnMap([
            [Config::XML_PATH_API_TEST_VALIDATED_AT, ScopeInterface::SCOPE_STORES, $storeId, 1710000000],
            [Config::XML_PATH_API_TEST_VALIDATION_FINGERPRINT, ScopeInterface::SCOPE_STORES, $storeId, 'stale-fingerprint'],
            [Config::XML_PATH_API_ENDPOINT, ScopeInterface::SCOPE_STORES, $storeId, 'https://api.example.test'],
            [Config::XML_PATH_API_KEY, ScopeInterface::SCOPE_STORES, $storeId, 'ncenc:encrypted-api-key'],
            [Config::XML_PATH_SHOP_EXTERNAL_ID, ScopeInterface::SCOPE_STORES, $storeId, 'Mage1'],
        ]);
        $this->encryptor->expects($this->once())->method('decrypt')->with('encrypted-api-key')->willReturn('api-key');

        $this->assertFalse($this->config->isApiTestValidationCurrent($storeId));
    }

    public function testGetStringDecryptsSecretValue(): void
    {
        $storeId = 3;
        $this->scopeConfig->method('getValue')->willReturnMap([
            [Config::XML_PATH_INTERNAL_SECRET, ScopeInterface::SCOPE_STORES, $storeId, 'ncenc:encrypted-secret'],
        ]);
        $this->encryptor->expects($this->once())->method('decrypt')->with('encrypted-secret')->willReturn('plain-secret');

        $this->assertSame('plain-secret', $this->config->getString(Config::XML_PATH_INTERNAL_SECRET, $storeId));
    }

    public function testGetStringMigratesLegacyPlainSecretStorage(): void
    {
        $storeId = 5;
        $this->scopeConfig->method('getValue')->willReturnMap([
            [Config::XML_PATH_CRON_TOKEN, ScopeInterface::SCOPE_STORES, $storeId, 'legacy-token'],
        ]);
        $this->encryptor->expects($this->once())->method('encrypt')->with('legacy-token')->willReturn('encrypted-token');
        $this->writer
            ->expects($this->once())
            ->method('save')
            ->with(Config::XML_PATH_CRON_TOKEN, 'ncenc:encrypted-token', ScopeInterface::SCOPE_STORES, $storeId);

        $this->assertSame('legacy-token', $this->config->getString(Config::XML_PATH_CRON_TOKEN, $storeId));
    }
}
