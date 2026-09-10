<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Test\Unit\Model\Webapi;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable as ConfigurableType;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\DataObject;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\ResourceModel\Quote as QuoteResource;
use Magento\Store\Model\StoreManagerInterface;
use NeuroCheckout\Connector\Model\Security\CartFingerprintService;
use NeuroCheckout\Connector\Model\Security\RecoveryLinkService;
use NeuroCheckout\Connector\Model\Security\RequestSecurityValidator;
use NeuroCheckout\Connector\Model\Webapi\CartRestoreManagement;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CartRestoreManagementTest extends TestCase
{
    public function testBuildConfigurableAddPayloadBuildsSelectedOptionAndSuperAttributes(): void
    {
        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $parentProduct = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getTypeId', 'getTypeInstance', 'getId'])
            ->getMock();
        $typeInstance = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getConfigurableAttributes'])
            ->getMock();
        $attribute = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getProductAttribute'])
            ->getMock();
        $productAttribute = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getAttributeId', 'getAttributeCode', 'getData'])
            ->getMock();
        $variantProduct = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getData'])
            ->getMock();

        $parentProduct->method('getTypeId')->willReturn('configurable');
        $parentProduct->method('getTypeInstance')->willReturn($typeInstance);
        $parentProduct->method('getId')->willReturn(123);
        $typeInstance->method('getConfigurableAttributes')->with($parentProduct)->willReturn([$attribute]);
        $attribute->method('getProductAttribute')->willReturn($productAttribute);
        $productAttribute->method('getAttributeId')->willReturn(93);
        $productAttribute->method('getAttributeCode')->willReturn('color');
        $variantProduct->method('getData')->with('color')->willReturn('17');

        $productRepository
            ->expects($this->once())
            ->method('getById')
            ->with(456, false, 1, true)
            ->willReturn($variantProduct);

        $service = $this->createService($productRepository);
        $method = new \ReflectionMethod($service, 'buildConfigurableAddPayload');
        $method->setAccessible(true);

        $result = $method->invoke($service, $parentProduct, 456, 2, 1);

        $this->assertIsArray($result);
        $this->assertSame($parentProduct, $result['product']);
        $this->assertInstanceOf(DataObject::class, $result['request']);
        $this->assertSame(123, $result['request']->getData('product'));
        $this->assertSame(123, $result['request']->getData('item'));
        $this->assertSame(456, $result['request']->getData('selected_configurable_option'));
        $this->assertSame([93 => 17], $result['request']->getData('super_attribute'));
        $this->assertSame(2, $result['request']->getData('qty'));
    }

    public function testCloneCouponMetaForRecoveredCartReturnsTrueAndInsertsClone(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $connection = $this->createMock(AdapterInterface::class);
        $select = $this->createMock(Select::class);

        $resource->method('getTableName')->with('neurocheckout_coupon')->willReturn('neurocheckout_coupon');
        $resource->method('getConnection')->willReturn($connection);
        $connection->method('select')->willReturn($select);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $connection->method('fetchOne')->with($select)->willReturn(0);
        $connection->method('fetchRow')->with($select)->willReturn([
            'decision_id' => 'decision-1',
            'action_id' => 'action-1',
            'rule_id' => 16,
            'discount_percent' => 10.0,
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
        ]);
        $connection
            ->expects($this->once())
            ->method('insert')
            ->with(
                'neurocheckout_coupon',
                $this->callback(function (array $row): bool {
                    return $row['cart_id'] === '45'
                        && $row['customer_email'] === 'client.magento101@example.com'
                        && $row['coupon_code'] === 'NC-27-E12931A3'
                        && $row['rule_id'] === 16;
                })
            );

        $service = $this->createService($this->createMock(ProductRepositoryInterface::class), $resource);
        $method = new \ReflectionMethod($service, 'cloneCouponMetaForRecoveredCart');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke(
            $service,
            1,
            'NC-27-E12931A3',
            'client.magento101@example.com',
            '45',
            'http://localhost/recover?coupon=NC-27-E12931A3'
        ));
    }

    public function testCloneCouponMetaForRecoveredCartReturnsFalseWhenSourceCouponIsExpired(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $connection = $this->createMock(AdapterInterface::class);
        $select = $this->createMock(Select::class);

        $resource->method('getTableName')->with('neurocheckout_coupon')->willReturn('neurocheckout_coupon');
        $resource->method('getConnection')->willReturn($connection);
        $connection->method('select')->willReturn($select);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $connection->method('fetchOne')->with($select)->willReturn(0);
        $connection->method('fetchRow')->with($select)->willReturn([
            'decision_id' => 'decision-1',
            'action_id' => 'action-1',
            'rule_id' => 16,
            'discount_percent' => 10.0,
            'expires_at' => date('Y-m-d H:i:s', time() - 86400),
        ]);
        $connection->expects($this->never())->method('insert');

        $service = $this->createService($this->createMock(ProductRepositoryInterface::class), $resource);
        $method = new \ReflectionMethod($service, 'cloneCouponMetaForRecoveredCart');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke(
            $service,
            1,
            'NC-27-E12931A3',
            'client.magento101@example.com',
            '45',
            'http://localhost/recover?coupon=NC-27-E12931A3'
        ));
    }

    private function createService(
        ProductRepositoryInterface $productRepository,
        ?ResourceConnection $resource = null
    ): CartRestoreManagement
    {
        return new CartRestoreManagement(
            $this->createMock(Request::class),
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(RequestSecurityValidator::class),
            $this->createMock(CartRepositoryInterface::class),
            $this->createMock(QuoteResource::class),
            $this->createMock(QuoteFactory::class),
            $productRepository,
            $this->createMock(CustomerRepositoryInterface::class),
            $this->createMock(AddressRepositoryInterface::class),
            $this->createMock(ConfigurableType::class),
            $resource ?? $this->createMock(ResourceConnection::class),
            $this->createMock(State::class),
            $this->createMock(CartFingerprintService::class),
            $this->createMock(RecoveryLinkService::class),
            $this->createMock(LoggerInterface::class)
        );
    }
}
