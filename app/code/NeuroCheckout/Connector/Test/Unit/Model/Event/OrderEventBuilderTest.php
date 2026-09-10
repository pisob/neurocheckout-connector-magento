<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Test\Unit\Model\Event;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\StoreManagerInterface;
use NeuroCheckout\Connector\Model\Config;
use NeuroCheckout\Connector\Model\Event\OrderEventBuilder;
use NeuroCheckout\Connector\Model\Event\ProductContextResolver;
use NeuroCheckout\Connector\Model\Event\StorefrontThemeResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OrderEventBuilderTest extends TestCase
{
    public function testBuildIncludesVisibleMagentoIncrementIdForSupportResolution(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $connection = $this->createMock(AdapterInterface::class);
        $select = $this->createMock(Select::class);
        $config = $this->createMock(Config::class);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $timezone = $this->createMock(TimezoneInterface::class);
        $themeResolver = $this->createMock(StorefrontThemeResolver::class);
        $productContextResolver = $this->createMock(ProductContextResolver::class);
        $store = new class {
            public function getName(): string
            {
                return 'Mage1';
            }
        };

        $resource->method('getTableName')->with('neurocheckout_coupon')->willReturn('neurocheckout_coupon');
        $resource->method('getConnection')->willReturn($connection);
        $connection->method('select')->willReturn($select);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $connection->method('fetchAll')->with($select)->willReturn([]);
        $config->method('getString')->willReturn('Mage');
        $storeManager->method('getStore')->with(1)->willReturn($store);
        $scopeConfig->method('getValue')->willReturn('en_US');
        $timezone->method('getConfigTimezone')->willReturn('UTC');
        $themeResolver->method('resolvePrimaryColor')->willReturn(null);

        $builder = new OrderEventBuilder(
            $config,
            $storeManager,
            $scopeConfig,
            $timezone,
            $themeResolver,
            $productContextResolver,
            $resource
        );

        $order = $this->createMock(Order::class);
        $order->method('getEntityId')->willReturn(7);
        $order->method('getQuoteId')->willReturn(46);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getAllVisibleItems')->willReturn([]);
        $order->method('getShippingAddress')->willReturn(null);
        $order->method('getBillingAddress')->willReturn(null);
        $order->method('getCustomerEmail')->willReturn('client.magento105@example.com');
        $order->method('getOrderCurrencyCode')->willReturn('USD');
        $order->method('getGrandTotal')->willReturn(84.70);
        $order->method('getCustomerId')->willReturn(56);
        $order->method('getCustomerIsGuest')->willReturn(false);
        $order->method('getCustomerFirstname')->willReturn('Client');
        $order->method('getCustomerLastname')->willReturn('Mage');
        $order->method('getCouponCode')->willReturn('');
        $order->method('getAppliedRuleIds')->willReturn('');
        $order->method('getDiscountAmount')->willReturn(0);
        $order->method('getCreatedAt')->willReturn('2026-04-22 22:14:34');
        $order->method('getIncrementId')->willReturn('000000008');
        $order->method('getStatus')->willReturn('pending');

        $payload = $builder->build($order);

        $this->assertNotNull($payload);
        $this->assertSame('7', $payload['order_id']);
        $this->assertSame('000000008', $payload['order_reference']);
        $this->assertSame('000000008', $payload['order_increment_id']);
        $this->assertSame('000000008', $payload['order']['reference']);
        $this->assertSame('000000008', $payload['order']['increment_id']);
    }

    public function testResolveDiscountContextMatchesStoredNeuroCouponByCouponCode(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $connection = $this->createMock(AdapterInterface::class);
        $select = $this->createMock(Select::class);

        $resource->method('getTableName')->with('neurocheckout_coupon')->willReturn('neurocheckout_coupon');
        $resource->method('getConnection')->willReturn($connection);
        $connection->method('select')->willReturn($select);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $connection->method('fetchAll')->with($select)->willReturn([
            [
                'rule_id' => 42,
                'coupon_code' => 'NC-27-E12931A3',
                'discount_percent' => 10.0,
            ],
        ]);

        $builder = new OrderEventBuilder(
            $this->createMock(Config::class),
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(TimezoneInterface::class),
            $this->createMock(StorefrontThemeResolver::class),
            $this->createMock(ProductContextResolver::class),
            $resource
        );

        $order = $this->createMock(Order::class);
        $order->method('getCouponCode')->willReturn('NC-27-E12931A3');
        $order->method('getAppliedRuleIds')->willReturn('42,99');
        $order->method('getDiscountAmount')->willReturn(-12.34);
        $order->method('getStoreId')->willReturn(1);

        $method = new \ReflectionMethod($builder, 'resolveDiscountContext');
        $method->setAccessible(true);
        $result = $method->invoke($builder, $order, 27, 'client.magento101@example.com');

        $this->assertTrue($result['neuro_coupon_used']);
        $this->assertSame('NC-27-E12931A3', $result['neuro_coupon_code']);
        $this->assertSame(10.0, $result['neuro_discount_percent']);
        $this->assertSame(12.34, $result['neuro_discount_amount']);
        $this->assertSame(['NC-27-E12931A3'], $result['used_coupon_codes']);
    }

    public function testResolveDiscountContextFallsBackOnNcPrefixWithoutStoredRow(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $connection = $this->createMock(AdapterInterface::class);
        $select = $this->createMock(Select::class);

        $resource->method('getTableName')->willReturn('neurocheckout_coupon');
        $resource->method('getConnection')->willReturn($connection);
        $connection->method('select')->willReturn($select);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $connection->method('fetchAll')->with($select)->willReturn([]);

        $builder = new OrderEventBuilder(
            $this->createMock(Config::class),
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(TimezoneInterface::class),
            $this->createMock(StorefrontThemeResolver::class),
            $this->createMock(ProductContextResolver::class),
            $resource
        );

        $order = $this->createMock(Order::class);
        $order->method('getCouponCode')->willReturn('NC-FALLBACK-1234');
        $order->method('getAppliedRuleIds')->willReturn('');
        $order->method('getDiscountAmount')->willReturn(-8.5);
        $order->method('getStoreId')->willReturn(1);

        $method = new \ReflectionMethod($builder, 'resolveDiscountContext');
        $method->setAccessible(true);
        $result = $method->invoke($builder, $order, 27, 'client.magento101@example.com');

        $this->assertTrue($result['neuro_coupon_used']);
        $this->assertSame('NC-FALLBACK-1234', $result['neuro_coupon_code']);
        $this->assertNull($result['neuro_discount_percent']);
        $this->assertSame(8.5, $result['neuro_discount_amount']);
    }

    public function testResolveDiscountContextDoesNotOverAttributeAmountWhenMultipleRulesAreApplied(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $connection = $this->createMock(AdapterInterface::class);
        $select = $this->createMock(Select::class);

        $resource->method('getTableName')->willReturn('neurocheckout_coupon');
        $resource->method('getConnection')->willReturn($connection);
        $connection->method('select')->willReturn($select);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $connection->method('fetchAll')->with($select)->willReturn([
            [
                'rule_id' => 42,
                'coupon_code' => 'NC-27-E12931A3',
                'discount_percent' => 10.0,
            ],
        ]);

        $builder = new OrderEventBuilder(
            $this->createMock(Config::class),
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(TimezoneInterface::class),
            $this->createMock(StorefrontThemeResolver::class),
            $this->createMock(ProductContextResolver::class),
            $resource
        );

        $order = $this->createMock(Order::class);
        $order->method('getCouponCode')->willReturn('SPRING-OTHER-RULE');
        $order->method('getAppliedRuleIds')->willReturn('42,99');
        $order->method('getDiscountAmount')->willReturn(-12.34);
        $order->method('getStoreId')->willReturn(1);

        $method = new \ReflectionMethod($builder, 'resolveDiscountContext');
        $method->setAccessible(true);
        $result = $method->invoke($builder, $order, 27, 'client.magento101@example.com');

        $this->assertTrue($result['neuro_coupon_used']);
        $this->assertSame('NC-27-E12931A3', $result['neuro_coupon_code']);
        $this->assertSame(10.0, $result['neuro_discount_percent']);
        $this->assertSame(0.0, $result['neuro_discount_amount']);
        $this->assertSame(['SPRING-OTHER-RULE'], $result['used_coupon_codes']);
    }
}
