<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Test\Unit\Model\Webapi;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Customer\Model\ResourceModel\Group\CollectionFactory as GroupCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteFactory;
use Magento\SalesRule\Model\RuleFactory;
use Magento\Store\Model\StoreManagerInterface;
use NeuroCheckout\Connector\Model\Config;
use NeuroCheckout\Connector\Model\Security\CartFingerprintService;
use NeuroCheckout\Connector\Model\Security\RecoveryLinkService;
use NeuroCheckout\Connector\Model\Security\RequestSecurityValidator;
use NeuroCheckout\Connector\Model\Webapi\CouponManagement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CouponManagementTest extends TestCase
{
    public function testResolveTargetCustomerGroupIdsUsesCustomerGroupFromEmail(): void
    {
        $customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getGroupId')->willReturn(3);
        $customerRepository
            ->expects($this->once())
            ->method('get')
            ->with('client.magento101@example.com', 1)
            ->willReturn($customer);

        $service = $this->createService($customerRepository, $this->createMock(GroupCollectionFactory::class));

        $method = new \ReflectionMethod($service, 'resolveTargetCustomerGroupIds');
        $method->setAccessible(true);

        $this->assertSame([3], $method->invoke($service, 1, 'client.magento101@example.com'));
    }

    public function testResolveTargetCustomerGroupIdsFallsBackToNotLoggedInGroup(): void
    {
        $customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $customerRepository
            ->method('get')
            ->willThrowException(new \RuntimeException('missing customer'));

        $groupFactory = $this->createMock(GroupCollectionFactory::class);
        $guestGroup = new class {
            public function getId(): int
            {
                return GroupInterface::NOT_LOGGED_IN_ID;
            }
        };
        $vipGroup = new class {
            public function getId(): int
            {
                return 3;
            }
        };

        $groupFactory->method('create')->willReturn(new \ArrayIterator([$guestGroup, $vipGroup]));
        $service = $this->createService($customerRepository, $groupFactory);

        $method = new \ReflectionMethod($service, 'resolveTargetCustomerGroupIds');
        $method->setAccessible(true);

        $this->assertSame([GroupInterface::NOT_LOGGED_IN_ID], $method->invoke($service, 1, 'guest@example.com'));
    }

    private function createService(
        CustomerRepositoryInterface $customerRepository,
        GroupCollectionFactory $groupCollectionFactory
    ): CouponManagement {
        return new CouponManagement(
            $this->createMock(Request::class),
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(RequestSecurityValidator::class),
            $this->createMock(ResourceConnection::class),
            $this->createMock(RuleFactory::class),
            $groupCollectionFactory,
            $customerRepository,
            $this->createMock(CartRepositoryInterface::class),
            $this->createMock(QuoteFactory::class),
            $this->createMock(CartFingerprintService::class),
            $this->createMock(RecoveryLinkService::class),
            $this->createMock(Config::class),
            $this->createMock(LoggerInterface::class)
        );
    }
}
