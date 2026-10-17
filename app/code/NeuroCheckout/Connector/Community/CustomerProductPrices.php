<?php
declare(strict_types=1);

namespace NeuroCheckout\Connector\Community;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use RuntimeException;

/** Ephemeral native price reader. No endpoint, snapshot write or campaign activation. */
class CustomerProductPrices
{
    private StoreManagerInterface $stores;
    private DeploymentConfig $deployment;
    private ScopeConfigInterface $config;
    private ObjectManagerInterface $objects;
    private $clock;

    public function __construct(StoreManagerInterface $stores, DeploymentConfig $deployment,
        ScopeConfigInterface $config, ObjectManagerInterface $objects, ?callable $clock = null)
    {
        $this->stores = $stores; $this->deployment = $deployment;
        $this->config = $config; $this->objects = $objects;
        $this->clock = $clock ?? static function (): float { return microtime(true); };
    }

    /** Do not log or persist the returned customer-bound data. Prices are for ONE unit, without a cart coupon. */
    public function read(int $scope, int $customerId, string $expectedEmail, array $productIds, string $currency): array
    {
        $started = ($this->clock)();
        try {
            if ($scope < 1 || $customerId < 1 || count($productIds) < 1 || count($productIds) > 16
                || array_keys($productIds) !== range(0, count($productIds) - 1)
                || !preg_match('/^[A-Z]{3}$/D', $currency)
                || strlen($expectedEmail) > 254 || !filter_var($expectedEmail, FILTER_VALIDATE_EMAIL)) { $this->unavailable(); }
            $ids = [];
            foreach ($productIds as $id) {
                if ((!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string) $id)
                    || isset($ids[(string) $id])) { $this->unavailable(); }
                $ids[(string) $id] = (int) $id;
            }
            $store = $this->stores->getStore($scope);
            $website = (int) $store->getWebsiteId();
            $this->checkScope($scope, $website);
            if (!$store->isActive() || (int) $store->getId() !== $scope || $website < 1
                || $this->objects->get(\Magento\Framework\App\State::class)->getAreaCode() !== 'frontend'
                || (int) $this->deployment->get('modules/Magento_Tax', 0) !== 1
                || (int) $this->deployment->get('modules/Magento_SharedCatalog', 0) === 1
                || (int) $this->deployment->get('modules/Magento_NegotiableQuote', 0) === 1
                || $this->config->isSetFlag('tax/weee/enable', 'store', $scope)) { $this->unavailable(); }
            // Never allow PriceCurrency's missing-rate fallback to relabel base
            // currency as the requested currency.
            if (!in_array($currency, $store->getAvailableCurrencyCodes(), true)) { $this->unavailable(); }
            $rate = $store->getBaseCurrency()->getRate($currency);
            if (!is_numeric($rate) || !is_finite((float) $rate) || (float) $rate <= 0) { $this->unavailable(); }

            $customer = $this->objects->get(\Magento\Customer\Api\CustomerRepositoryInterface::class)->getById($customerId);
            if ((int) $customer->getId() !== $customerId || (int) $customer->getWebsiteId() !== $website
                || strcasecmp((string) $customer->getEmail(), $expectedEmail) !== 0 || $customer->getConfirmation()) { $this->unavailable(); }
            $groupId = $this->taxId($customer->getGroupId());
            $group = $this->objects->get(\Magento\Customer\Api\GroupRepositoryInterface::class)->getById($groupId);
            if ((int) $group->getId() !== $groupId) { $this->unavailable(); }
            $customerTaxClass = $this->taxId($group->getTaxClassId());
            $basedOn = (string) $this->config->getValue('tax/calculation/based_on', 'store', $scope);
            if (!in_array($basedOn, ['shipping', 'billing', 'origin'], true)) { $this->unavailable(); }
            $shipping = $this->address($customer->getDefaultShipping(), $customerId, $basedOn === 'shipping', $scope);
            $billing = $this->address($customer->getDefaultBilling(), $customerId, $basedOn === 'billing', $scope);
            if ($basedOn === 'origin') {
                $this->taxLocation((string) $this->config->getValue('shipping/origin/country_id', 'store', $scope),
                    (string) $this->config->getValue('shipping/origin/postcode', 'store', $scope),
                    $this->config->getValue('shipping/origin/region_id', 'store', $scope), $scope);
            }
            $display = (string) $this->config->getValue('tax/display/type', 'store', $scope);
            $included = (string) $this->config->getValue('tax/calculation/price_includes_tax', 'store', $scope);
            if (!in_array($display, ['1', '2', '3'], true) || !in_array($included, ['0', '1'], true)) { $this->unavailable(); }
            $priceCurrency = $this->objects->get(\Magento\Framework\Pricing\PriceCurrencyInterface::class);
            if ((string) $priceCurrency->getCurrency($store, $currency)->getCode() !== $currency) { $this->unavailable(); }
            $products = $this->objects->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
            $tax = $this->objects->get(\Magento\Tax\Api\TaxCalculationInterface::class);
            $offers = [];
            foreach ($ids as $id) {
                $this->checkTime($started); $this->checkScope($scope, $website);
                try {
                    // Clone a force-reloaded model: never leave another group
                    // or cached final price in the repository's shared object.
                    $product = clone $products->getById($id, false, $scope, true);
                    if ((int) $product->getId() !== $id || (int) $product->getStoreId() !== $scope
                        || !in_array($website, array_map('intval', $product->getWebsiteIds()), true)
                        || !in_array($product->getTypeId(), ['simple', 'virtual'], true)
                        || (int) $product->getStatus() !== 1 || !in_array((int) $product->getVisibility(), [2,3,4], true)
                        || $product->getRequiredOptions()) { $this->unavailable(); }
                    $sku = (string) $product->getSku();
                    if ($sku === '' || strlen($sku) > 64 || preg_match('/[\x00-\x1f\x7f]/', $sku)) { $this->unavailable(); }
                    $productTaxClass = $this->taxId($product->getTaxClassId());
                    $this->checkTime($started); $this->checkScope($scope, $website);
                    $product->setCustomerGroupId($groupId);
                    $product->unsetData('calculated_final_price');
                    $product->unsetData('final_price');
                    $nativePrice = $this->money($product->getFinalPrice(1.0));
                    $this->checkTime($started); $this->checkScope($scope, $website);
                    if ((int) $this->deployment->get('modules/Magento_Msrp', 0) === 1
                        && $this->objects->get(\Magento\Msrp\Helper\Data::class)->canApplyMsrp($product)) {
                        // Applicable product type alone does not hide a price.
                        // Resolve the native minimum, then compare in base currency
                        // to THIS recipient's final price. The generic price-info
                        // helper can read a session customer's catalog-rule group.
                        $minimum = $this->money($this->objects->get(\Magento\Msrp\Pricing\MsrpPriceCalculatorInterface::class)
                            ->getMsrpPriceValue($product));
                        if ($minimum > $nativePrice) { $this->unavailable(); }
                    }
                    $converted = $this->money($priceCurrency->convertAndRound($nativePrice, $store, $currency));
                    $key = $this->objects->create(\Magento\Tax\Api\Data\TaxClassKeyInterface::class)
                        ->setType('id')->setValue($productTaxClass);
                    $customerKey = $this->objects->create(\Magento\Tax\Api\Data\TaxClassKeyInterface::class)
                        ->setType('id')->setValue($customerTaxClass);
                    $item = $this->objects->create(\Magento\Tax\Api\Data\QuoteDetailsItemInterface::class)
                        ->setCode($sku)->setType('product')->setQuantity(1)->setUnitPrice($converted)
                        ->setTaxClassKey($key)->setIsTaxIncluded($included === '1');
                    // DTO only, NOT a checkout quote. Do not use Catalog helper
                    // getTaxPrice: it injects the current session's customer ID.
                    $details = $this->objects->create(\Magento\Tax\Api\Data\QuoteDetailsInterface::class)
                        ->setCustomerId($customerId)->setCustomerTaxClassKey($customerKey)
                        ->setShippingAddress($shipping)->setBillingAddress($billing)->setItems([$item]);
                    $this->checkTime($started); $this->checkScope($scope, $website);
                    $rows = $tax->calculateTax($details, $scope, true)->getItems();
                    if (!is_array($rows) || count($rows) !== 1) { $this->unavailable(); }
                    $row = reset($rows);
                    if ($row->getCode() !== $sku) { $this->unavailable(); }
                    $excl = $this->money($row->getPrice()); $incl = $this->money($row->getPriceInclTax());
                    if ($incl < $excl) { $this->unavailable(); }
                    $offers[] = ['product_id' => (string) $id, 'status' => 'available', 'currency' => $currency,
                        'price_excl_tax' => $excl, 'price_incl_tax' => $incl,
                        'display_mode' => ['1' => 'excluding_tax', '2' => 'including_tax', '3' => 'both'][$display],
                        // Dual display must not silently collapse to an
                        // unlabeled tax-exclusive price in an email template.
                        'display_price' => $display === '3' ? null : ($display === '2' ? $incl : $excl)];
                } catch (\Throwable $error) {
                    $offers[] = ['product_id' => (string) $id, 'status' => 'unavailable'];
                }
            }
            $this->checkTime($started); $this->checkScope($scope, $website);
            return ['schema' => 'magento-customer-prices-v1', 'store_id' => $scope, 'website_id' => $website,
                'customer_id' => $customerId, 'customer_email' => (string) $customer->getEmail(),
                'customer_group_id' => $groupId, 'customer_tax_class_id' => $customerTaxClass,
                'currency' => $currency, 'quantity' => 1, 'read_at' => gmdate('Y-m-d\TH:i:s\Z', (int) ($this->clock)()),
                'offers' => $offers];
        } catch (\Throwable $error) {
            // No raw Magento exception, email, address or product data in errors.
            throw new RuntimeException('customer_prices_unavailable');
        }
    }

    private function address($id, int $customerId, bool $required, int $scope)
    {
        if (!$id) { if ($required) { $this->unavailable(); } return null; }
        if ((!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string) $id)) { $this->unavailable(); }
        $address = $this->objects->get(\Magento\Customer\Api\AddressRepositoryInterface::class)->getById((int) $id);
        $country = (string) $address->getCountryId();
        if ((int) $address->getId() !== (int) $id || (int) $address->getCustomerId() !== $customerId
            || !preg_match('/^[A-Z]{2}$/D', $country)) { $this->unavailable(); }
        if ($required) {
            $this->taxLocation($country, (string) $address->getPostcode(), $address->getRegionId(), $scope);
        }
        return $address;
    }

    private function taxLocation(string $country, string $postcode, $region, int $scope): void
    {
        if (!preg_match('/^[A-Z]{2}$/D', $country)
            || (trim($postcode) === '' && !$this->objects->get(\Magento\Directory\Helper\Data::class)->isZipCodeOptional($country))
            || strlen($postcode) > 32 || preg_match('/[\x00-\x1f\x7f]/', $postcode)) { $this->unavailable(); }
        $regions = explode(',', (string) $this->config->getValue('general/region/state_required', 'store', $scope));
        if (in_array($country, $regions, true) && $this->taxId($region) < 1) { $this->unavailable(); }
    }

    private function taxId($value): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^(0|[1-9][0-9]{0,9})$/D', (string) $value)) { $this->unavailable(); }
        return (int) $value;
    }

    private function checkScope(int $scope, int $website): void
    {
        $current = $this->stores->getStore();
        if ((int) $current->getId() !== $scope || (int) $current->getWebsiteId() !== $website) { $this->unavailable(); }
    }

    private function checkTime(float $started): void
    {
        $now = ($this->clock)();
        if ($now < $started || $now - $started >= 3.0) { $this->unavailable(); }
    }

    private function money($value): float
    {
        if (is_bool($value) || !is_numeric($value) || !is_finite((float) $value)
            || (float) $value < 0 || (float) $value > 1000000000) { $this->unavailable(); }
        return (float) $value;
    }

    private function unavailable(): void { throw new RuntimeException('customer_prices_unavailable'); }
}
