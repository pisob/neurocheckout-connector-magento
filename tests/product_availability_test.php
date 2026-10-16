<?php
declare(strict_types=1);
namespace Magento\Store\Model { interface StoreManagerInterface { public function getStore($id); } }
namespace Magento\Framework { interface ObjectManagerInterface { public function get($type); } }
namespace Magento\Framework\App {
    class DeploymentConfig {
        public int $enabled = 1;
        public function get($path, $default = null) { return $path === 'modules/Magento_InventorySales' ? $this->enabled : $default; }
    }
}
namespace {
    require __DIR__ . '/../app/code/NeuroCheckout/Connector/Community/ProductAvailability.php';
    $checks = 0;
    function checkAvailability($ok, string $message): void {
        global $checks; $checks++;
        if (!$ok) { throw new \RuntimeException($message); }
    }
    function productAvailabilityFixture(int $id = 1, string $sku = 'SKU'): array {
        return ['entity_id' => $id, 'sku' => $sku, 'store_id' => 2, 'website_id' => 7, 'type_id' => 'simple',
            'attributes' => ['status' => ['effective' => '1'], 'visibility' => ['effective' => '4']]];
    }
    class InventoryRow {
        public string $sku; public $stock = 9; public $salable = true; public $errors = [];
        public function __construct(string $sku) { $this->sku = $sku; }
        public function getSku() { return $this->sku; }
        public function getStockId() { return $this->stock; }
        public function isSalable() { return $this->salable; }
        public function getErrors() { return $this->errors; }
    }
    function inventoryFixture(): array {
        $clock = (object) ['now' => 100.0];
        $stores = new class implements \Magento\Store\Model\StoreManagerInterface {
            public int $scope = 2; public int $website = 7; public bool $active = true; public array $requested = [];
            public function getStore($id) { $this->requested[] = $id; return $this; }
            public function getId() { return $this->scope; }
            public function getWebsiteId() { return $this->website; }
            public function isActive() { return $this->active; }
            public function getWebsite() { return new class { public function getCode() { return 'selected_website'; } }; }
        };
        $deployment = new \Magento\Framework\App\DeploymentConfig();
        $indexed = new class {
            public int $calls = 0; public $mutate = null;
            public function execute(array $skus, int $stock): array {
                $this->calls++;
                checkAvailability($stock === 9, 'native check uses resolved stock, never default stock');
                $rows = array_map(static function ($sku) { return new InventoryRow($sku); }, $skus);
                return $this->mutate === null ? $rows : ($this->mutate)($rows);
            }
        };
        $quantity = new class {
            public int $calls = 0; public $mutate = null;
            public function execute(array $requests, int $stock): array {
                $this->calls++;
                checkAvailability($stock === 9, 'quantity uses resolved website stock');
                $rows = [];
                foreach ($requests as $request) {
                    checkAvailability($request->getQty() === 1.0, 'check one unit, not arbitrary inventory quantity');
                    $rows[] = new InventoryRow($request->getSku());
                }
                return $this->mutate === null ? $rows : ($this->mutate)($rows);
            }
        };
        $resolver = new class {
            public array $calls = []; public int $stock = 9;
            public function execute($type, $code) { $this->calls[] = [$type, $code]; return $this; }
            public function getStockId() { return $this->stock; }
        };
        $requests = new class {
            public function create(array $data) {
                return new class($data) {
                    private array $data;
                    public function __construct(array $data) { $this->data = $data; }
                    public function getSku() { return $this->data['sku']; }
                    public function getQty() { return $this->data['qty']; }
                };
            }
        };
        $objects = new class($resolver, $indexed, $quantity, $requests) implements \Magento\Framework\ObjectManagerInterface {
            public array $services; public array $lookups = []; public bool $fail = false;
            public function __construct($resolver, $indexed, $quantity, $requests) {
                $this->services = [
                    'Magento\\InventorySalesApi\\Api\\StockResolverInterface' => $resolver,
                    'Magento\\InventorySalesApi\\Api\\AreProductsSalableInterface' => $indexed,
                    'Magento\\InventorySalesApi\\Api\\AreProductsSalableForRequestedQtyInterface' => $quantity,
                    'Magento\\InventorySalesApi\\Api\\Data\\IsProductSalableForRequestedQtyRequestInterfaceFactory' => $requests];
            }
            public function get($type) {
                $this->lookups[] = $type;
                if ($this->fail || !isset($this->services[$type])) { throw new \RuntimeException('private native failure'); }
                return $this->services[$type];
            }
        };
        $service = new \NeuroCheckout\Connector\Community\ProductAvailability($stores, $deployment, $objects,
            static function () use ($clock): float { return $clock->now; });
        return compact('service', 'stores', 'deployment', 'objects', 'clock', 'indexed', 'quantity', 'resolver');
    }
    $f = inventoryFixture(); $read = $f['service']->forStore(2, 7); $product = productAvailabilityFixture();
    $result = $read([$product]);
    checkAvailability($result[1] === ['inventory_status' => 'website_salable', 'available_for_sale' => true,
        'inventory_scope' => ['store_id' => 2, 'website_id' => 7, 'stock_id' => 9, 'requested_qty' => 1]], 'exact bound evidence');
    checkAvailability($f['stores']->requested === [2], 'explicit store view');
    checkAvailability($f['resolver']->calls === [['website', 'selected_website']], 'explicit website sales channel');
    $f['clock']->now += 0.1;
    checkAvailability($read([$product]) === $result, 'unchanged stock does not churn payload digest');
    $mixed = $read([$product, productAvailabilityFixture(2, '123')]);
    checkAvailability(count($mixed) === 2 && $mixed[2]['available_for_sale'], 'numeric SKU is not confused with product ID');
    foreach (['indexed', 'quantity'] as $boundary) {
        foreach (['denied', 'foreign_stock', 'foreign_sku', 'duplicate', 'missing', 'extra', 'non_boolean', 'exception'] as $mode) {
            $f = inventoryFixture();
            $f[$boundary]->mutate = static function (array $rows) use ($mode): array {
                if ($mode === 'exception') { throw new \RuntimeException('sensitive native details'); }
                if ($mode === 'denied') { $rows[0]->salable = false; }
                if ($mode === 'foreign_stock') { $rows[0]->stock = 1; }
                if ($mode === 'foreign_sku') { $rows[0]->sku = 'OTHER'; }
                if ($mode === 'non_boolean') { $rows[0]->salable = 'true'; }
                if ($mode === 'duplicate') { $rows = [$rows[0], $rows[0]]; }
                if ($mode === 'missing') { $rows = []; }
                if ($mode === 'extra') { $rows[] = new InventoryRow('UNKNOWN'); }
                return $rows;
            };
            $r = $f['service']->forStore(2, 7)([$product, productAvailabilityFixture(2, 'SECOND')]);
            checkAvailability($mode === 'denied' ? $r[1]['available_for_sale'] === false : $r === [], $boundary . ' rejects ' . $mode);
        }
    }
    foreach ([['stock_error'], 'malformed'] as $errors) {
        $f = inventoryFixture();
        $f['quantity']->mutate = static function ($rows) use ($errors) { $rows[0]->errors = $errors; return $rows; };
        $r = $f['service']->forStore(2, 7)([$product]);
        checkAvailability(is_array($errors) ? !$r[1]['available_for_sale'] : $r === [], 'errors never grant');
    }
    foreach (['scope', 'website', 'disabled', 'inactive', 'missing_msi', 'invalid_stock'] as $mode) {
        $f = inventoryFixture();
        if ($mode === 'scope') { $f['stores']->scope = 3; }
        if ($mode === 'website') { $f['stores']->website = 8; }
        if ($mode === 'disabled') { $f['deployment']->enabled = 0; }
        if ($mode === 'inactive') { $f['stores']->active = false; }
        if ($mode === 'missing_msi') { $f['objects']->fail = true; }
        if ($mode === 'invalid_stock') { $f['resolver']->stock = 0; }
        checkAvailability($f['service']->forStore(2, 7)([$product]) === [], 'optional/scoped availability fails closed: ' . $mode);
        checkAvailability($f['indexed']->calls === 0 && $f['quantity']->calls === 0, 'no inventory call without valid scope');
        if ($mode === 'disabled') { checkAvailability($f['objects']->lookups === [], 'disabled MSI is not instantiated'); }
    }
    foreach (['configurable', 'bundle', 'grouped', 'unknown'] as $type) {
        $f = inventoryFixture(); $p = $product; $p['type_id'] = $type;
        checkAvailability($f['service']->forStore(2, 7)([$p]) === [] && !$f['indexed']->calls, 'composite/custom types remain unknown');
    }
    foreach ([['status','2'], ['visibility','1'], ['visibility','unknown']] as [$attribute, $value]) {
        $f = inventoryFixture(); $p = $product; $p['attributes'][$attribute]['effective'] = $value;
        checkAvailability($f['service']->forStore(2, 7)([$p]) === [] && !$f['indexed']->calls, 'disabled/hidden products not queried');
    }
    foreach ([['store_id',3], ['website_id',8], ['entity_id',0], ['sku',''], ['sku',str_repeat('x',65)], ['sku',"bad\n"]] as [$field, $value]) {
        $f = inventoryFixture(); $p = $product; $p[$field] = $value;
        checkAvailability($f['service']->forStore(2, 7)([$p]) === [], 'invalid identity excluded');
    }
    $f = inventoryFixture(); $read = $f['service']->forStore(2, 7);
    checkAvailability($read([$product, $product]) === [], 'duplicate SKU rejected');
    checkAvailability($read(array_fill(0, 129, $product)) === [] && !$f['indexed']->calls, 'bounded native batch');
    checkAvailability($read([]) === [] && !$f['indexed']->calls, 'empty batch needs no native read');
    foreach (['before', 'indexed', 'quantity'] as $boundary) {
        $f = inventoryFixture(); $read = $f['service']->forStore(2, 7);
        if ($boundary === 'before') { $f['clock']->now += 2; }
        else { $f[$boundary]->mutate = static function ($rows) use ($f) { $f['clock']->now += 2; return $rows; }; }
        checkAvailability($read([$product]) === [], 'late native evidence discarded');
        if ($boundary !== 'quantity') { checkAvailability(!$f['quantity']->calls, 'expired budget prevents next native call'); }
        checkAvailability($read([$product]) === [], 'later batches cannot renew budget');
    }
    echo $checks . " native inventory assertions passed (synthetic services; no store writes).\n";
}
