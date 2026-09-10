<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Test\Unit\Model\Event;

use Magento\Customer\Model\AddressFactory;
use Magento\Customer\Model\CustomerFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use NeuroCheckout\Connector\Model\Config;
use NeuroCheckout\Connector\Model\Event\CartEventBuilder;
use NeuroCheckout\Connector\Model\Event\ProductContextResolver;
use NeuroCheckout\Connector\Model\Event\StorefrontThemeResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CartEventBuilderTest extends TestCase
{
    public function testBuildUpdatedIncludesSessionMetaAndThemePalette(): void
    {
        $config = $this->createMock(Config::class);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $timezone = $this->createMock(TimezoneInterface::class);
        $request = $this->getMockBuilder(Http::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRequestUri', 'getHeader'])
            ->getMock();
        $themeResolver = $this->createMock(StorefrontThemeResolver::class);
        $productContextResolver = $this->createMock(ProductContextResolver::class);
        $customerFactory = $this->createMock(CustomerFactory::class);
        $addressFactory = $this->createMock(AddressFactory::class);

        $builder = new CartEventBuilder(
            $config,
            $storeManager,
            $scopeConfig,
            $timezone,
            $request,
            $themeResolver,
            $productContextResolver,
            $customerFactory,
            $addressFactory
        );

        $address = new class extends Address {
            public function __construct()
            {
            }

            public function getTelephone()
            {
                return '+1 212 555 0101';
            }

            public function getCountryId()
            {
                return 'US';
            }
        };
        $item = new class extends \Magento\Quote\Model\Quote\Item {
            public function __construct()
            {
            }

            public function getProduct()
            {
                return null;
            }

            public function getProductId()
            {
                return 15;
            }

            public function getName()
            {
                return 'Joust Duffle Bag';
            }

            public function getQty()
            {
                return 1;
            }

            public function getPriceInclTax()
            {
                return 34.0;
            }

            public function getRowTotalInclTax()
            {
                return 34.0;
            }

            public function getProductOrderOptions()
            {
                return [
                    'attributes_info' => [
                        ['label' => 'Color', 'value' => 'Blue'],
                    ],
                ];
            }
        };
        $quote = new class($item, $address) extends Quote {
            private object $item;
            private Address $address;

            public function __construct(object $item, Address $address)
            {
                $this->item = $item;
                $this->address = $address;
            }

            public function getId()
            {
                return 27;
            }

            public function getStoreId()
            {
                return 1;
            }

            public function getAllVisibleItems()
            {
                return [$this->item];
            }

            public function getGrandTotal()
            {
                return 34.0;
            }

            public function getCustomerEmail()
            {
                return 'client.magento101@example.com';
            }

            public function getCustomerFirstname()
            {
                return 'Alice';
            }

            public function getCustomerLastname()
            {
                return 'Carter';
            }

            public function getCustomerId()
            {
                return 0;
            }

            public function getBillingAddress()
            {
                return $this->address;
            }

            public function getShippingAddress()
            {
                return $this->address;
            }

            public function getQuoteCurrencyCode()
            {
                return 'USD';
            }

            public function getCreatedAt()
            {
                return '2026-04-22 10:00:00';
            }
        };
        $store = $this->createMock(Store::class);

        $storeManager->method('getStore')->with(1)->willReturn($store);
        $store->method('getName')->willReturn('Mage1');

        $config->method('getString')->willReturnMap([
            [Config::XML_PATH_SHOP_EXTERNAL_ID, 1, 'Mage1'],
        ]);
        $config->method('getBool')->willReturnMap([
            [Config::XML_PATH_RECOVERY_ENABLED, 1, true],
            [Config::XML_PATH_ENABLE_DISCOUNT, 1, true],
            [Config::XML_PATH_ALLOW_GUEST, 1, true],
        ]);
        $config->method('getFloat')->willReturnMap([
            [Config::XML_PATH_MIN_CART_TOTAL, 1, 10.0],
            [Config::XML_PATH_NO_DISCOUNT_MAX, 1, 49.0],
            [Config::XML_PATH_DISCOUNT_5_MIN, 1, 50.0],
            [Config::XML_PATH_DISCOUNT_5_MAX, 1, 99.0],
            [Config::XML_PATH_DISCOUNT_10_MIN, 1, 100.0],
            [Config::XML_PATH_MAX_DISCOUNT_PERCENT, 1, 10.0],
        ]);

        $scopeConfig->method('getValue')->willReturnMap([
            ['general/locale/code', 'stores', 1, 'fr_FR'],
        ]);
        $timezone->method('getConfigTimezone')->willReturn('Europe/Paris');
        $timezone->method('date')->willReturn(new \DateTimeImmutable('2026-04-22 10:00:00'));

        $request->method('getRequestUri')->willReturn('/checkout/cart');
        $request->method('getHeader')->willReturnMap([
            ['Referer', 'http://localhost:8088/product/joust-duffle-bag'],
            ['User-Agent', 'NC-Test-UA/1.0'],
        ]);

        $themeResolver->method('resolvePrimaryColor')->with(1, 'fr_FR')->willReturn('#1979C3');
        $productContextResolver->method('buildVariantLabelFromOptions')->willReturn('Blue');
        $productContextResolver->method('resolveForCartItem')->willReturn([
            'attribute_id' => 0,
            'category_path' => 'Gear',
            'brand_name' => 'Luma Gear',
            'availability' => 'in_stock',
            'in_stock' => true,
            'stock' => 15,
            'product_url' => 'http://localhost:8088/joust-duffle-bag.html',
            'image_url' => 'http://localhost:8088/media/catalog/product/j/o/joust.jpg',
        ]);

        $payload = $builder->buildUpdated($quote);

        $this->assertIsArray($payload);
        $this->assertSame('/checkout/cart', $payload['meta']['session']['source_page']);
        $this->assertSame('http://localhost:8088/product/joust-duffle-bag', $payload['meta']['session']['referrer']);
        $this->assertSame('NC-Test-UA/1.0', $payload['meta']['session']['user_agent']);
        $this->assertSame('#1979C3', $payload['context']['primary_color']);
        $this->assertSame(['primary_color' => '#1979C3'], $payload['context']['theme_palette']);
        $this->assertSame('en_US', $payload['customer']['locale']);
        $this->assertNull($payload['customer']['sms_opt_in']);
    }
}
