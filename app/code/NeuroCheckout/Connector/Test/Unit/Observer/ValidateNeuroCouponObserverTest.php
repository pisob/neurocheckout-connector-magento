<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Test\Unit\Observer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Quote\Model\Quote;
use NeuroCheckout\Connector\Observer\ValidateNeuroCouponObserver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ValidateNeuroCouponObserverTest extends TestCase
{
    public function testExecuteClearsCouponWhenCustomerEmailDoesNotMatchMetadata(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $connection = $this->createMock(AdapterInterface::class);
        $select = $this->createMock(Select::class);
        $logger = $this->createMock(LoggerInterface::class);

        $resource->method('getTableName')->with('neurocheckout_coupon')->willReturn('neurocheckout_coupon');
        $resource->method('getConnection')->willReturn($connection);
        $connection->method('select')->willReturn($select);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $connection->method('fetchRow')->with($select)->willReturn([
            'customer_email' => 'another.customer@example.com',
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ]);

        $item = new class {
            public mixed $appliedRuleIds = '16';
            public float $discountAmount = 8.0;
            public float $baseDiscountAmount = 8.0;

            public function setAppliedRuleIds($value): void
            {
                $this->appliedRuleIds = $value;
            }

            public function setDiscountAmount($value): void
            {
                $this->discountAmount = (float) $value;
            }

            public function setBaseDiscountAmount($value): void
            {
                $this->baseDiscountAmount = (float) $value;
            }
        };

        $quote = new class($item) extends Quote {
            public string $couponCode = 'NC-27-E12931A3';
            public bool $totalsCollectedFlag = true;
            private object $item;

            public function __construct(object $item)
            {
                $this->item = $item;
            }

            public function getId()
            {
                return 27;
            }

            public function getStoreId()
            {
                return 1;
            }

            public function getCouponCode()
            {
                return $this->couponCode;
            }

            public function getCustomerEmail()
            {
                return 'client.magento101@example.com';
            }

            public function getCustomerId()
            {
                return 0;
            }

            public function setCouponCode($couponCode)
            {
                $this->couponCode = (string) $couponCode;
                return $this;
            }

            public function setTotalsCollectedFlag($flag)
            {
                $this->totalsCollectedFlag = (bool) $flag;
                return $this;
            }

            public function getAllItems()
            {
                return [$this->item];
            }
        };

        $logger->expects($this->once())->method('info');

        $observer = new ValidateNeuroCouponObserver(
            $resource,
            $this->createMock(CustomerRepositoryInterface::class),
            $logger
        );

        $event = new Event(['quote' => $quote]);
        $frameworkObserver = new Observer(['event' => $event]);
        $observer->execute($frameworkObserver);

        $this->assertSame('', $quote->couponCode);
        $this->assertFalse($quote->totalsCollectedFlag);
        $this->assertNull($item->appliedRuleIds);
        $this->assertSame(0.0, $item->discountAmount);
        $this->assertSame(0.0, $item->baseDiscountAmount);
    }
}
