<?php
declare(strict_types=1);
namespace Magento\Store\Model { interface StoreManagerInterface { public function getStore($id = null); } }
namespace Magento\Framework { interface ObjectManagerInterface { public function get($type); public function create($type, array $args = []); } }
namespace Magento\Framework\App\Config { interface ScopeConfigInterface { public function getValue($path, $type = null, $scope = null); public function isSetFlag($path, $type = null, $scope = null); } }
namespace Magento\Framework\App {
    class DeploymentConfig {
        public array $modules = ['Magento_Tax' => 1];
        public function get($path, $default = null) { return $this->modules[substr($path, 8)] ?? $default; }
    }
}
namespace {
    require __DIR__ . '/../app/code/NeuroCheckout/Connector/Community/CustomerProductPrices.php';
    $checks = 0;
    function checkPrice($ok, string $why): void {
        global $checks; $checks++;
        if (!$ok) { throw new \RuntimeException($why); }
    }
    class PriceData {
        public array $data;
        public function __construct(array $data = []) { $this->data = $data; }
        public function __call($name, $args) {
            $key = substr($name, 3);
            if (substr($name, 0, 3) === 'get') { return $this->data[$key] ?? null; }
            if (substr($name, 0, 3) === 'set') { $this->data[$key] = $args[0]; return $this; }
            throw new \RuntimeException('unexpected API');
        }
    }
    class NativePriceProduct extends PriceData {
        public $mutate = null;
        public function unsetData($key) { unset($this->data[$key]); return $this; }
        public function getFinalPrice($qty) {
            checkPrice($qty === 1.0 && $this->getCustomerGroupId() === 3, 'native one-unit final price for exact recipient group');
            checkPrice(!isset($this->data['final_price']) && !isset($this->data['calculated_final_price']), 'clear stale price cache');
            // Service double represents native group/special/catalog-rule result.
            return $this->mutate ? ($this->mutate)($this) : 18.0;
        }
    }
    function pricesFixture(): array {
        $clock = (object) ['now' => 1000.0];
        $stores = new class implements \Magento\Store\Model\StoreManagerInterface {
            public int $scope = 2; public int $website = 7; public int $currentScope = 2; public bool $active = true; public $rate = 1.25;
            public function getStore($id = null) {
                if ($id === null) { $copy = clone $this; $copy->scope = $this->currentScope; return $copy; } return $this;
            }
            public function getId() { return $this->scope; }
            public function getWebsiteId() { return $this->website; }
            public function isActive() { return $this->active; }
            public function getAvailableCurrencyCodes() { return ['USD', 'EUR']; }
            public function getBaseCurrency() { return $this; }
            public function getRate($currency) { return $this->rate; }
        };
        $deployment = new \Magento\Framework\App\DeploymentConfig();
        $config = new class implements \Magento\Framework\App\Config\ScopeConfigInterface {
            public array $values = ['tax/calculation/based_on' => 'shipping', 'tax/display/type' => '2',
                'tax/calculation/price_includes_tax' => '0', 'general/region/state_required' => 'US,CA',
                'shipping/origin/country_id' => 'US', 'shipping/origin/region_id' => 12, 'shipping/origin/postcode' => '90001'];
            public function getValue($path, $type = null, $scope = null) {
                if ($type !== 'store' || $scope !== 2) { throw new \RuntimeException('unscoped config'); }
                return $this->values[$path] ?? null;
            }
            public function isSetFlag($path, $type = null, $scope = null) { return (bool) $this->getValue($path, $type, $scope); }
        };
        $customer = new PriceData(['Id'=>42, 'WebsiteId'=>7, 'Email'=>'recipient@example.test', 'GroupId'=>3, 'DefaultShipping'=>51, 'DefaultBilling'=>52]);
        $group = new PriceData(['Id'=>3, 'TaxClassId'=>9]);
        $shipping = new PriceData(['Id'=>51, 'CustomerId'=>42, 'CountryId'=>'US', 'Postcode'=>'90001', 'RegionId'=>12]);
        $billing = new PriceData(['Id'=>52, 'CustomerId'=>42, 'CountryId'=>'FR', 'Postcode'=>'75001', 'RegionId'=>null]);
        $repository = new class {
            public array $rows = []; public array $calls = []; public $mutate = null;
            public function getById($id, ...$args) {
                $this->calls[] = [$id, $args];
                if ($this->mutate) { ($this->mutate)(); }
                if (!isset($this->rows[$id])) { throw new \RuntimeException('private missing entity'); }
                return $this->rows[$id];
            }
        };
        $customers = clone $repository; $customers->rows = [42=>$customer];
        $groups = clone $repository; $groups->rows = [3=>$group];
        $addresses = clone $repository; $addresses->rows = [51=>$shipping,52=>$billing];
        $product = new NativePriceProduct(['Id'=>1, 'StoreId'=>2, 'WebsiteIds'=>[7], 'TypeId'=>'simple', 'Status'=>1,
            'Visibility'=>4, 'Sku'=>'SKU-ONE', 'TaxClassId'=>2, 'RequiredOptions'=>false, 'CustomerGroupId'=>99,
            'final_price'=>999, 'calculated_final_price'=>999]);
        $products = clone $repository; $products->rows = [1=>$product];
        $priceCurrency = new class($stores) {
            public $stores; public string $code = 'EUR'; public $mutate = null; public array $calls = [];
            public function __construct($stores) { $this->stores = $stores; }
            public function getCurrency($store, $currency) { return new PriceData(['Code'=>$this->code]); }
            public function convertAndRound($price, $store, $currency) {
                $this->calls[] = [$price,$store->getId(),$currency];
                return $this->mutate ? ($this->mutate)() : round($price * $this->stores->rate, 2);
            }
        };
        $tax = new class {
            public array $calls = []; public $mutate = null;
            public function calculateTax($details, $scope, $round) {
                $this->calls[] = [$details,$scope,$round]; $item = $details->getItems()[0]; $price = $item->getUnitPrice();
                $excl = $item->getIsTaxIncluded() ? $price / 1.2 : $price;
                $rows = [new PriceData(['Code'=>$item->getCode(), 'Price'=>round($excl,2), 'PriceInclTax'=>round($excl*1.2,2)])];
                return new PriceData(['Items'=>$this->mutate ? ($this->mutate)($rows) : $rows]);
            }
        };
        $state = new PriceData(['AreaCode'=>'frontend']);
        $directory = new class { public bool $optional = false; public function isZipCodeOptional($country) { return $this->optional; } };
        $msrp = new class {
            public bool $hidden = true; public $minimum = 25.0;
            public function canApplyMsrp($product) { return $this->hidden; }
            public function getMsrpPriceValue($product) {
                checkPrice($product->getCustomerGroupId() === 3, 'MAP bound to recipient group'); return $this->minimum;
            }
        };
        $objects = new class implements \Magento\Framework\ObjectManagerInterface {
            public array $services = []; public array $lookups = []; public array $created = [];
            public function get($type) {
                $this->lookups[] = $type;
                if (!isset($this->services[$type])) { throw new \RuntimeException('private missing service'); }
                return $this->services[$type];
            }
            public function create($type, array $args = []) {
                $this->created[] = $type;
                if (!in_array($type, ['Magento\Tax\Api\Data\TaxClassKeyInterface', 'Magento\Tax\Api\Data\QuoteDetailsItemInterface',
                    'Magento\Tax\Api\Data\QuoteDetailsInterface'], true)) { throw new \RuntimeException('only ephemeral tax DTOs allowed'); }
                return new PriceData();
            }
        };
        $objects->services = ['Magento\Framework\App\State'=>$state, 'Magento\Customer\Api\CustomerRepositoryInterface'=>$customers,
            'Magento\Customer\Api\GroupRepositoryInterface'=>$groups, 'Magento\Customer\Api\AddressRepositoryInterface'=>$addresses,
            'Magento\Catalog\Api\ProductRepositoryInterface'=>$products, 'Magento\Framework\Pricing\PriceCurrencyInterface'=>$priceCurrency,
            'Magento\Tax\Api\TaxCalculationInterface'=>$tax, 'Magento\Directory\Helper\Data'=>$directory, 'Magento\Msrp\Helper\Data'=>$msrp,
            'Magento\Msrp\Pricing\MsrpPriceCalculatorInterface'=>$msrp];
        $service = new \NeuroCheckout\Connector\Community\CustomerProductPrices($stores,$deployment,$config,$objects,
            static function () use ($clock): float { return $clock->now; });
        return compact('service','stores','deployment','config','customer','group','shipping','billing','customers','groups',
            'addresses','products','product','priceCurrency','tax','state','directory','msrp','objects','clock');
    }
    function readPrices(array $f, array $ids = [1], string $currency = 'EUR'): array {
        return $f['service']->read(2,42,'recipient@example.test',$ids,$currency);
    }
    function deniedPrices(array $f, string $reason, array $ids = [1], string $currency = 'EUR'): void {
        try { readPrices($f,$ids,$currency); } catch (\RuntimeException $e) {
            checkPrice($e->getMessage() === 'customer_prices_unavailable' && $e->getPrevious() === null, 'private error redacted: '.$reason); return;
        }
        throw new \RuntimeException('expected refusal: '.$reason);
    }
    $f = pricesFixture(); $r = readPrices($f);
    checkPrice($r['schema']==='magento-customer-prices-v1' && $r['store_id']===2 && $r['website_id']===7 && $r['customer_id']===42
        && $r['customer_email']==='recipient@example.test' && $r['customer_group_id']===3 && $r['customer_tax_class_id']===9
        && $r['currency']==='EUR' && $r['quantity']===1, 'recipient-bound envelope');
    checkPrice($r['offers'][0] === ['product_id'=>'1','status'=>'available','currency'=>'EUR','price_excl_tax'=>22.5,
        'price_incl_tax'=>27.0,'display_mode'=>'including_tax','display_price'=>27.0], 'native converted final price with tax');
    checkPrice($f['products']->calls === [[1,[false,2,true]]], 'force reload in exact store');
    checkPrice($f['product']->getCustomerGroupId()===99 && $f['product']->data['final_price']===999, 'repository object not contaminated');
    checkPrice($f['priceCurrency']->calls === [[18.0,2,'EUR']], 'native conversion/rounding');
    [$dto,$scope,$round] = $f['tax']->calls[0]; $item = $dto->getItems()[0];
    checkPrice($scope===2 && $round && $dto->getCustomerId()===42 && $dto->getCustomerTaxClassKey()->getValue()===9
        && $dto->getShippingAddress()===$f['shipping'] && $dto->getBillingAddress()===$f['billing'], 'explicit native recipient context');
    checkPrice($item->getType()==='product' && $item->getQuantity()===1 && $item->getTaxClassKey()->getValue()===2
        && !$item->getIsTaxIncluded(), 'native product tax class and inclusion');
    checkPrice(!str_contains(json_encode($r),'90001') && !str_contains(json_encode($r),'75001'), 'no address exported');
    checkPrice(!array_filter($f['objects']->lookups,static fn($v)=>str_contains($v,'Session')), 'no session impersonation');
    foreach (['1'=>['excluding_tax',22.5], '2'=>['including_tax',27.0], '3'=>['both',null]] as $mode=>[$name,$amount]) {
        $f=pricesFixture(); $f['config']->values['tax/display/type']=(string)$mode; $o=readPrices($f)['offers'][0];
        checkPrice($o['display_mode']===$name && $o['display_price']===$amount, 'respect tax display including dual values');
    }
    $f=pricesFixture(); $f['config']->values['tax/calculation/price_includes_tax']='1';
    checkPrice(readPrices($f)['offers'][0]['display_price']===22.5, 'tax-inclusive price not taxed twice');
    foreach (['shipping','billing','origin'] as $basis) {
        $f=pricesFixture(); $f['config']->values['tax/calculation/based_on']=$basis;
        if ($basis==='origin') $f['customer']->setDefaultShipping(null)->setDefaultBilling(null);
        checkPrice(readPrices($f)['offers'][0]['status']==='available', 'native tax basis: '.$basis);
    }
    foreach ([[],[1,1],[true],[1.0],['01'],['0'],[-1],['1e2'],[new \stdClass()],range(1,17),[1=>1]] as $ids) {
        $f=pricesFixture(); deniedPrices($f,'invalid bounded IDs',$ids); checkPrice(!$f['products']->calls,'invalid input not loaded');
    }
    foreach (['eur','GBP','EURO',''] as $currency) deniedPrices(pricesFixture(),'invalid/unavailable currency',[1],$currency);
    foreach ([false,null,0,-1,INF,NAN] as $rate) { $f=pricesFixture(); $f['stores']->rate=$rate; deniedPrices($f,'missing/invalid rate'); }
    $f=pricesFixture(); $f['priceCurrency']->code='USD'; deniedPrices($f,'silent base-currency fallback');
    foreach ([['customer','Id',41],['customer','WebsiteId',8],['customer','Email','other@example.test'],['customer','Confirmation','pending'],
        ['customer','GroupId',null],['group','Id',4],['group','TaxClassId','2.5'],['group','TaxClassId',null],['shipping','CustomerId',99],
        ['shipping','Id',99],['shipping','CountryId',''],['shipping','Postcode',''],['shipping','Postcode','   '],['shipping','Postcode',"90001\n"],
        ['shipping','RegionId',null],['billing','CustomerId',99],['customer','DefaultShipping',null],['customer','DefaultShipping','51junk'],
        ['state','AreaCode','adminhtml']] as [$part,$key,$value]) {
        $f=pricesFixture(); $f[$part]->data[$key]=$value; deniedPrices($f,$part.$key);
        checkPrice(!$f['products']->calls,'invalid recipient context stops lookup');
    }
    $f=pricesFixture(); $f['customer']->setEmail('RECIPIENT@example.test'); checkPrice(readPrices($f)['offers'][0]['status']==='available','email case binding');
    $f=pricesFixture(); $f['shipping']->setPostcode(''); $f['directory']->optional=true;
    checkPrice(readPrices($f)['offers'][0]['status']==='available','native optional postcode');
    foreach (['Magento_Tax','Magento_SharedCatalog','Magento_NegotiableQuote'] as $module) {
        $f=pricesFixture(); $f['deployment']->modules[$module]=$module==='Magento_Tax'?0:1; deniedPrices($f,'unsupported module');
    }
    foreach (['tax/weee/enable'=>1,'tax/calculation/based_on'=>'default','tax/display/type'=>'0','tax/calculation/price_includes_tax'=>'unknown'] as $key=>$value) {
        $f=pricesFixture(); $f['config']->values[$key]=$value; deniedPrices($f,'unsupported tax configuration');
    }
    foreach (['country_id','postcode','region_id'] as $part) {
        $f=pricesFixture(); $f['config']->values['tax/calculation/based_on']='origin';
        $f['config']->values['shipping/origin/'.$part]=''; deniedPrices($f,'incomplete tax origin');
    }
    foreach (['scope'=>3,'website'=>0,'currentScope'=>3,'active'=>false] as $key=>$value) {
        $f=pricesFixture(); $f['stores']->$key=$value; deniedPrices($f,'invalid store');
    }
    foreach ([['Id',2],['StoreId',3],['WebsiteIds',[8]],['TypeId','configurable'],['TypeId','bundle'],['TypeId','downloadable'],
        ['Status',2],['Visibility',1],['RequiredOptions',true],['Sku',''],['Sku',str_repeat('x',65)],['Sku',"SKU\n"],['TaxClassId',null]] as [$key,$value]) {
        $f=pricesFixture(); $f['product']->data[$key]=$value;
        checkPrice(readPrices($f)['offers']===[['product_id'=>'1','status'=>'unavailable']],'unsupported/foreign product: '.$key);
        checkPrice(!$f['tax']->calls,'unsupported product not priced');
    }
    foreach ([true,false] as $hidden) {
        $f=pricesFixture(); $f['deployment']->modules['Magento_Msrp']=1; $f['msrp']->hidden=$hidden;
        checkPrice(readPrices($f)['offers'][0]['status']===($hidden?'unavailable':'available'),'native advertised-price restriction');
    }
    foreach ([0.0, 17.0, 18.0, 25.0, null, -1, INF] as $minimum) {
        $f=pricesFixture(); $f['deployment']->modules['Magento_Msrp']=1; $f['msrp']->minimum=$minimum;
        $available=$minimum!==null && is_finite($minimum) && $minimum>=0 && $minimum<=18;
        checkPrice(readPrices($f)['offers'][0]['status']===($available?'available':'unavailable'),'native MAP minimum, not just enabled module');
    }
    foreach (['product','priceCurrency'] as $boundary) foreach ([null,true,-1,INF,NAN,1000000001] as $value) {
        $f=pricesFixture(); $f[$boundary]->mutate=static fn()=>$value;
        checkPrice(readPrices($f)['offers'][0]['status']==='unavailable','invalid money: '.$boundary);
    }
    foreach (['empty','duplicate','foreign','negative','inverted','exception'] as $mode) {
        $f=pricesFixture(); $f['tax']->mutate=static function($rows) use($mode) {
            if($mode==='empty') return [];
            if($mode==='duplicate') return [$rows[0],$rows[0]];
            if($mode==='foreign') $rows[0]->setCode('foreign');
            if($mode==='negative') $rows[0]->setPrice(-1);
            if($mode==='inverted') $rows[0]->setPriceInclTax(1);
            if($mode==='exception') throw new \RuntimeException('private tax details');
            return $rows;
        };
        checkPrice(readPrices($f)['offers'][0]['status']==='unavailable','invalid native tax: '.$mode);
    }
    $f=pricesFixture(); $f['product']->mutate=static fn()=>0;
    checkPrice(readPrices($f)['offers'][0]['display_price']===0.0,'zero price valid');
    $f=pricesFixture(); $f['tax']->mutate=static function($rows){$rows[0]->setPriceInclTax($rows[0]->getPrice());return $rows;};
    checkPrice(readPrices($f)['offers'][0]['display_price']===22.5,'zero tax valid');
    $f=pricesFixture(); $r=readPrices($f,[1,2]);
    checkPrice($r['offers'][0]['status']==='available' && $r['offers'][1]===['product_id'=>'2','status'=>'unavailable'],'partial product failures explicit');
    foreach (['products','product','priceCurrency','tax'] as $boundary) foreach (['late','backwards','scope'] as $mode) {
        $f=pricesFixture(); $f[$boundary]->mutate=static function($rows=null) use($f,$mode,$boundary) {
            if($mode==='scope') $f['stores']->currentScope=3; else $f['clock']->now+=$mode==='late'?3:-1;
            return $boundary==='tax'?$rows:18.0;
        };
        deniedPrices($f,'expired/drifted envelope: '.$boundary.$mode);
        if($boundary!=='tax') checkPrice(!$f['tax']->calls,'stale/foreign context stops next tax call');
    }
    $f=pricesFixture(); $f['customers']->mutate=static function(){throw new \RuntimeException('private customer data');};
    deniedPrices($f,'native customer failure');
    echo $checks." native recipient-price assertions passed (synthetic services; no store writes).\n";
}
