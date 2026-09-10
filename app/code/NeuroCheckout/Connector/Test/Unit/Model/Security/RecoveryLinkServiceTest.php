<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Test\Unit\Model\Security;

use Magento\Framework\UrlInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use NeuroCheckout\Connector\Model\Config;
use NeuroCheckout\Connector\Model\Repository\RecoveryTokenRepository;
use NeuroCheckout\Connector\Model\Security\RecoveryLinkService;
use PHPUnit\Framework\TestCase;

class RecoveryLinkServiceTest extends TestCase
{
    public function testBuildReturnsOpaqueRecoveryLinkWhenTokenStorageIsAvailable(): void
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getBaseUrl')->with(UrlInterface::URL_TYPE_WEB)->willReturn('https://shop.example/');
        $store->method('getCode')->willReturn('default');
        $storeManager->method('getStore')->with(1)->willReturn($store);

        $tokenRepository = $this->createMock(RecoveryTokenRepository::class);
        $tokenRepository
            ->expects($this->once())
            ->method('issue')
            ->with(1, '42', 'client@example.com', 'NC-42-ABCD', RecoveryLinkService::LINK_TTL_SECONDS, '')
            ->willReturn('opaque-token-123');

        $config = $this->createMock(Config::class);
        $config->expects($this->once())->method('isOpaqueRecoveryLinksEnabled')->with(1)->willReturn(true);
        $config->expects($this->never())->method('getOrCreateInternalSecret');

        $service = new RecoveryLinkService($storeManager, $config, $tokenRepository);

        $url = $service->build(1, '42', 'client@example.com', 'NC-42-ABCD');

        $this->assertStringContainsString('rt=opaque-token-123', $url);
        $this->assertStringContainsString('store_id=1', $url);
        $this->assertStringNotContainsString('cart_id=', $url);
        $this->assertStringNotContainsString('email=', $url);
        $this->assertStringNotContainsString('coupon=', $url);
    }

    public function testBuildFallsBackToLegacySignedLinkWhenTokenStorageIsUnavailable(): void
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getBaseUrl')->with(UrlInterface::URL_TYPE_WEB)->willReturn('https://shop.example/');
        $store->method('getCode')->willReturn('default');
        $storeManager->method('getStore')->with(1)->willReturn($store);

        $tokenRepository = $this->createMock(RecoveryTokenRepository::class);
        $tokenRepository->expects($this->once())->method('issue')->willReturn(null);

        $config = $this->createMock(Config::class);
        $config->expects($this->once())->method('isOpaqueRecoveryLinksEnabled')->with(1)->willReturn(true);
        $config->expects($this->once())->method('getOrCreateInternalSecret')->with(1)->willReturn('legacy-secret');

        $service = new RecoveryLinkService($storeManager, $config, $tokenRepository);

        $url = $service->build(1, '42', 'client@example.com', 'NC-42-ABCD');

        $this->assertStringContainsString('cart_id=42', $url);
        $this->assertStringContainsString('email=client%40example.com', $url);
        $this->assertStringContainsString('coupon=NC-42-ABCD', $url);
        $this->assertStringContainsString('sig=', $url);
    }

    public function testBuildFallsBackToLegacySignedLinkWhenOpaqueFeatureFlagIsDisabled(): void
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getBaseUrl')->with(UrlInterface::URL_TYPE_WEB)->willReturn('https://shop.example/');
        $store->method('getCode')->willReturn('default');
        $storeManager->method('getStore')->with(1)->willReturn($store);

        $tokenRepository = $this->createMock(RecoveryTokenRepository::class);
        $tokenRepository->expects($this->never())->method('issue');

        $config = $this->createMock(Config::class);
        $config->expects($this->once())->method('isOpaqueRecoveryLinksEnabled')->with(1)->willReturn(false);
        $config->expects($this->once())->method('getOrCreateInternalSecret')->with(1)->willReturn('legacy-secret');

        $service = new RecoveryLinkService($storeManager, $config, $tokenRepository);

        $url = $service->build(1, '42', 'client@example.com', 'NC-42-ABCD');

        $this->assertStringContainsString('cart_id=42', $url);
        $this->assertStringNotContainsString('rt=', $url);
        $this->assertStringContainsString('sig=', $url);
    }

    public function testResolveOpaqueDelegatesToRepository(): void
    {
        $tokenRepository = $this->createMock(RecoveryTokenRepository::class);
        $tokenRepository
            ->expects($this->once())
            ->method('resolveUsable')
            ->with('opaque-token-123')
            ->willReturn([
                'store_id' => 1,
                'cart_id' => '42',
                'customer_email' => 'client@example.com',
                'coupon_code' => 'NC-42-ABCD',
            ]);

        $service = new RecoveryLinkService(
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(Config::class),
            $tokenRepository
        );

        $payload = $service->resolveOpaque('opaque-token-123');

        $this->assertSame('42', $payload['cart_id']);
        $this->assertSame('client@example.com', $payload['customer_email']);
    }

    public function testConsumeOpaqueDelegatesToRepository(): void
    {
        $tokenRepository = $this->createMock(RecoveryTokenRepository::class);
        $tokenRepository
            ->expects($this->once())
            ->method('consume')
            ->with('opaque-token-123')
            ->willReturn(true);

        $service = new RecoveryLinkService(
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(Config::class),
            $tokenRepository
        );

        $this->assertTrue($service->consumeOpaque('opaque-token-123'));
    }
}
