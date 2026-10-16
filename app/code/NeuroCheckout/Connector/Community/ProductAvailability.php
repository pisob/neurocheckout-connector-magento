<?php
declare(strict_types=1);

namespace NeuroCheckout\Connector\Community;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use RuntimeException;

/** Read-only MSI evidence. Never substitutes legacy/global quantity for website stock. */
class ProductAvailability
{
    private StoreManagerInterface $stores;
    private DeploymentConfig $deployment;
    private ObjectManagerInterface $objects;
    private $clock;

    public function __construct(StoreManagerInterface $stores, DeploymentConfig $deployment,
        ObjectManagerInterface $objects, ?callable $clock = null)
    {
        $this->stores = $stores; $this->deployment = $deployment; $this->objects = $objects;
        $this->clock = $clock ?? static function (): float { return microtime(true); };
    }

    public function forStore(int $scope, int $website): callable
    {
        // A budget for all batches, not a fresh budget per product. Calls are
        // synchronous native reads: late results are discarded, never trusted.
        $deadline = ($this->clock)() + 1.5;
        try {
            if ($scope < 1 || $website < 1
                || (int) $this->deployment->get('modules/Magento_InventorySales', 0) !== 1) {
                throw new RuntimeException('inventory_unavailable');
            }
            $store = $this->stores->getStore($scope);
            if ((int) $store->getId() !== $scope || (int) $store->getWebsiteId() !== $website || !$store->isActive()) {
                throw new RuntimeException('inventory_unavailable');
            }
            // Resolve optional MSI only at authenticated capture time. A shop
            // with MSI disabled can still use ordinary cart synchronization.
            $stock = $this->objects->get(\Magento\InventorySalesApi\Api\StockResolverInterface::class)
                ->execute('website', (string) $store->getWebsite()->getCode());
            $stockId = (int) $stock->getStockId();
            if ($stockId < 1) { throw new RuntimeException('inventory_unavailable'); }
            $salable = $this->objects->get(\Magento\InventorySalesApi\Api\AreProductsSalableInterface::class);
            $quantity = $this->objects->get(\Magento\InventorySalesApi\Api\AreProductsSalableForRequestedQtyInterface::class);
            $requests = $this->objects->get(\Magento\InventorySalesApi\Api\Data\IsProductSalableForRequestedQtyRequestInterfaceFactory::class);
        } catch (\Throwable $error) {
            return static function (array $products): array { return []; };
        }

        return function (array $products) use ($scope, $website, $stockId, $salable, $quantity, $requests, $deadline): array {
            if (count($products) > 128 || ($this->clock)() >= $deadline) { return []; }
            try {
                $bySku = []; $requestList = [];
                foreach ($products as $product) {
                    // Composite products require variant/option-aware pricing
                    // and quantity semantics; do not invent a parent quantity.
                    if (!in_array($product['type_id'] ?? '', ['simple', 'virtual', 'downloadable'], true)
                        || (string) ($product['attributes']['status']['effective'] ?? '') !== '1'
                        || !in_array((string) ($product['attributes']['visibility']['effective'] ?? ''), ['2','3','4'], true)) { continue; }
                    $sku = $product['sku'] ?? null;
                    $id = $product['entity_id'] ?? null;
                    if (!is_string($sku) || $sku === '' || strlen($sku) > 64 || preg_match('/[\x00-\x1f\x7f]/', $sku)
                        || !preg_match('/^[1-9][0-9]*$/D', (string) $id)
                        || (int) ($product['store_id'] ?? 0) !== $scope || (int) ($product['website_id'] ?? 0) !== $website
                        || isset($bySku['sku:' . $sku])) { throw new RuntimeException('inventory_unavailable'); }
                    $bySku['sku:' . $sku] = (string) $id;
                    $requestList[] = $requests->create(['sku' => $sku, 'qty' => 1.0]);
                }
                if (!$bySku || ($this->clock)() >= $deadline) { return []; }
                $skus = array_map(static function ($key) { return substr($key, 4); }, array_keys($bySku));
                $indexed = $this->results($salable->execute($skus, $stockId), $bySku, $stockId, false);
                if (($this->clock)() >= $deadline) { return []; }
                // Indexed is_salable alone is insufficient: pending orders may
                // reserve the last unit before a stock index update occurs.
                $requested = $this->results($quantity->execute($requestList, $stockId), $bySku, $stockId, true);
                if (($this->clock)() >= $deadline) { return []; }
                $result = [];
                foreach ($bySku as $sku => $id) {
                    $result[$id] = ['inventory_status' => 'website_salable',
                        'available_for_sale' => $indexed[$sku] && $requested[$sku],
                        'inventory_scope' => ['store_id' => $scope, 'website_id' => $website,
                            'stock_id' => $stockId, 'requested_qty' => 1]];
                }
                // No capture timestamp in the payload: freshness belongs to
                // the signed source envelope, avoiding perpetual resync churn.
                return $result;
            } catch (\Throwable $error) {
                // Never expose stock exceptions or mistake a failed read for
                // an in-stock product. Other source records remain usable.
                return [];
            }
        };
    }

    private function results(array $rows, array $expected, int $stockId, bool $withErrors): array
    {
        if (count($rows) !== count($expected)) { throw new RuntimeException('inventory_unavailable'); }
        $result = [];
        foreach ($rows as $row) {
            $key = 'sku:' . $row->getSku();
            if (!isset($expected[$key]) || array_key_exists($key, $result) || $row->getStockId() !== $stockId
                || !is_bool($row->isSalable()) || ($withErrors && !is_array($row->getErrors()))) {
                throw new RuntimeException('inventory_unavailable');
            }
            $result[$key] = $row->isSalable() && (!$withErrors || $row->getErrors() === []);
        }
        return $result;
    }
}
