<?php
declare(strict_types=1);

namespace NeuroCheckout\Connector\Community;

use PDO;
use RuntimeException;

/** Magento Open Source, one database, entity_id EAV; bounded staging pilot. */
final class MagentoSourceSnapshot
{
    private PDO $db;
    private string $prefix;
    private float $started = 0;
    private array $batchValues = [];
    private array $batchChildren = [];
    private array $batchCategories = [];
    private const TABLES = ['store', 'store_website', 'catalog_product_entity', 'catalog_product_website',
        'eav_entity_type', 'eav_attribute', 'catalog_eav_attribute', 'catalog_product_entity_varchar',
        'catalog_product_entity_int', 'catalog_product_entity_decimal', 'catalog_product_entity_text',
        'catalog_product_entity_datetime', 'catalog_product_relation', 'catalog_category_product', 'quote', 'quote_item', 'sales_order'];
    private const ATTRIBUTES = ['name', 'status', 'visibility', 'price', 'special_price', 'special_from_date',
        'special_to_date', 'tax_class_id', 'weight', 'url_key', 'short_description', 'image'];

    public function __construct(PDO $connection, string $prefix)
    {
        if (!preg_match('/^[A-Za-z0-9_]*$/D', $prefix) || $connection->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new RuntimeException('source_schema_unavailable');
        }
        $this->db = $connection; $this->prefix = $prefix;
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function capture(int $scope): array
    {
        if ($scope < 1 || $this->db->inTransaction()) { throw new RuntimeException('source_unavailable'); }
        $this->started = microtime(true);
        $version = (string) $this->db->getAttribute(PDO::ATTR_SERVER_VERSION);
        $this->db->exec(stripos($version, 'mariadb') !== false
            ? 'SET SESSION max_statement_time=1' : 'SET SESSION MAX_EXECUTION_TIME=1000');
        $this->validateSchema();
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        try {
            $stores = $this->rows('SELECT s.store_id, s.website_id FROM ' . $this->table('store') . ' s JOIN '
                . $this->table('store_website') . ' w ON w.website_id=s.website_id WHERE s.store_id=? AND s.is_active=1 AND s.website_id>0', [$scope], 1);
            if (count($stores) !== 1) { throw new RuntimeException('source_shop_unavailable'); }
            $website = (int) $stores[0]['website_id'];
            $attributes = $this->attributes();
            $products = $this->rows('SELECT p.entity_id, p.sku, p.type_id, p.attribute_set_id, p.created_at, p.updated_at FROM '
                . $this->table('catalog_product_entity') . ' p JOIN ' . $this->table('catalog_product_website')
                . ' pw ON pw.product_id=p.entity_id WHERE pw.website_id=? ORDER BY p.entity_id', [$website], 8192);
            $quotes = $this->rows('SELECT entity_id, store_id, is_active, customer_id, customer_is_guest, customer_email,
                customer_firstname, customer_lastname, created_at, updated_at, base_currency_code, quote_currency_code,
                grand_total, subtotal, subtotal_with_discount FROM ' . $this->table('quote') . ' WHERE store_id=? ORDER BY entity_id', [$scope], 8192);
            if (count($products) + count($quotes) > 8192) { throw new RuntimeException('source_snapshot_capacity'); }
            $result = [];
            foreach (array_chunk($products, 128) as $batch) {
              $this->prepareProductBatch($batch, $scope, $website, $attributes);
              foreach ($batch as $product) {
                $id = (int) $product['entity_id'];
                $product['store_id'] = $scope; $product['website_id'] = $website;
                $product['attributes'] = $this->values($id, $scope, $attributes);
                $product['children'] = $this->batchChildren[$id] ?? [];
                $product['category_ids'] = $this->batchCategories[$id] ?? [];
                if (count($product['children']) > 128 || count($product['category_ids']) > 128) { throw new RuntimeException('source_snapshot_capacity'); }
                // MSI salable quantities require reservations and website stock
                // resolution; do not mislabel a legacy/global quantity as stock.
                $product['inventory_status'] = 'not_exported';
                $result[] = $this->record('product', $id, $product);
              }
            }
            foreach ($quotes as $quote) {
                $id = (int) $quote['entity_id'];
                $quote['items'] = $this->rows('SELECT item_id, parent_item_id, store_id, product_id, sku, name, product_type,
                    qty, price, row_total, discount_amount, tax_amount FROM ' . $this->table('quote_item') . ' WHERE quote_id=? ORDER BY item_id', [$id], 128);
                foreach ($quote['items'] as $item) {
                    if ((int) $item['store_id'] !== $scope) { throw new RuntimeException('source_scope_inconsistent'); }
                }
                $quote['orders'] = $this->rows('SELECT entity_id, store_id, increment_id, state, status, grand_total,
                    order_currency_code, created_at FROM ' . $this->table('sales_order') . ' WHERE quote_id=? ORDER BY entity_id', [$id], 32);
                foreach ($quote['orders'] as $order) {
                    if ((int) $order['store_id'] !== $scope) { throw new RuntimeException('source_scope_inconsistent'); }
                }
                $quote['status'] = $quote['orders'] ? 'converted' : (!(int) $quote['is_active'] ? 'inactive' : ($quote['items'] ? 'active' : 'empty'));
                $result[] = $this->record('cart', $id, $quote);
            }
            if (microtime(true) - $this->started > 5) { throw new RuntimeException('source_snapshot_timeout'); }
            $this->db->rollBack();
            return $result;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $error;
        }
    }

    private function attributes(): array
    {
        $rows = $this->rows('SELECT a.attribute_id, a.attribute_code, a.backend_type, a.backend_table, c.is_global FROM '
            . $this->table('eav_attribute') . ' a JOIN ' . $this->table('eav_entity_type') . ' t ON t.entity_type_id=a.entity_type_id
            JOIN ' . $this->table('catalog_eav_attribute') . ' c ON c.attribute_id=a.attribute_id
            WHERE t.entity_type_code=? AND a.attribute_code IN (' . implode(',', array_fill(0, count(self::ATTRIBUTES), '?'))
            . ') ORDER BY a.attribute_id', array_merge(['catalog_product'], self::ATTRIBUTES), count(self::ATTRIBUTES));
        $codes = [];
        foreach ($rows as $row) {
            if (!in_array($row['backend_type'], ['varchar', 'int', 'decimal', 'text', 'datetime'], true)
                || !empty($row['backend_table']) || !in_array((int) $row['is_global'], [0, 1, 2], true)
                || isset($codes[$row['attribute_code']])) { throw new RuntimeException('source_schema_unavailable'); }
            $codes[$row['attribute_code']] = $row['backend_type'];
        }
        foreach (['name' => 'varchar', 'status' => 'int', 'visibility' => 'int', 'price' => 'decimal'] as $name => $type) {
            if (($codes[$name] ?? null) !== $type) { throw new RuntimeException('source_schema_unavailable'); }
        }
        return $rows;
    }

    private function values(int $product, int $scope, array $attributes): array
    {
        $types = []; $result = []; $byId = [];
        foreach ($attributes as $attribute) {
            $types[$attribute['backend_type']][] = (int) $attribute['attribute_id'];
            $byId[(int) $attribute['attribute_id']] = $attribute['attribute_code'];
            $result[$attribute['attribute_code']] = ['scope' => (int) $attribute['is_global'], 'default' => null, 'store' => null, 'effective' => null];
        }
        $values = $this->batchValues[$product] ?? [];
        if (count($values) > 2 * count($attributes)) { throw new RuntimeException('source_snapshot_capacity'); }
        $seen = [];
        foreach ($values as $value) {
            $key = $value['attribute_id'] . ':' . $value['store_id'];
            if (isset($seen[$key]) || strlen((string) $value['value']) > 16384) { throw new RuntimeException('source_snapshot_capacity'); }
            $seen[$key] = true;
            $code = $byId[(int) $value['attribute_id']];
            // Global attributes use only the global value even if an inconsistent
            // store row exists. Never consult another store's localized values.
            if ((int) $value['store_id'] === 0) { $result[$code]['default'] = $value['value']; }
            elseif ($result[$code]['scope'] !== 1) { $result[$code]['store'] = $value['value']; }
        }
        foreach ($result as &$value) { $value['effective'] = $value['store'] ?? $value['default']; } unset($value);
        if ($result['name']['effective'] === null || $result['status']['effective'] === null
            || $result['visibility']['effective'] === null) { throw new RuntimeException('source_schema_unavailable'); }
        return $result;
    }

    private function prepareProductBatch(array $products, int $scope, int $website, array $attributes): void
    {
        $this->batchValues = []; $this->batchChildren = []; $this->batchCategories = [];
        $ids = array_map(static function ($p) { return (int) $p['entity_id']; }, $products);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $types = [];
        foreach ($attributes as $attribute) { $types[$attribute['backend_type']][] = (int) $attribute['attribute_id']; }
        foreach ($types as $type => $attributeIds) {
            $rows = $this->rows('SELECT entity_id, attribute_id, store_id, LEFT(CAST(value AS CHAR),16385) AS value FROM '
                . $this->table('catalog_product_entity_' . $type) . ' WHERE entity_id IN (' . $marks . ') AND store_id IN (0,?) AND attribute_id IN ('
                . implode(',', array_fill(0, count($attributeIds), '?')) . ') ORDER BY entity_id,attribute_id,store_id',
                array_merge($ids, [$scope], $attributeIds), count($ids) * count($attributeIds) * 2);
            foreach ($rows as $row) { $this->batchValues[(int) $row['entity_id']][] = $row; }
        }
        $rows = $this->rows('SELECT r.parent_id,r.child_id FROM ' . $this->table('catalog_product_relation')
            . ' r JOIN ' . $this->table('catalog_product_website') . ' pw ON pw.product_id=r.child_id WHERE r.parent_id IN ('
            . $marks . ') AND pw.website_id=? ORDER BY r.parent_id,r.child_id', array_merge($ids, [$website]), count($ids) * 128);
        foreach ($rows as $row) { $this->batchChildren[(int) $row['parent_id']][] = ['child_id' => $row['child_id']]; }
        $rows = $this->rows('SELECT product_id,category_id FROM ' . $this->table('catalog_category_product')
            . ' WHERE product_id IN (' . $marks . ') ORDER BY product_id,category_id', $ids, count($ids) * 128);
        foreach ($rows as $row) { $this->batchCategories[(int) $row['product_id']][] = ['category_id' => $row['category_id']]; }
    }

    private function validateSchema(): void
    {
        $names = array_map(function ($table) { return $this->prefix . $table; }, self::TABLES);
        $engines = $this->rows('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('
            . implode(',', array_fill(0, count($names), '?')) . ')', $names, count($names));
        if (count($engines) !== count($names)) { throw new RuntimeException('source_schema_unavailable'); }
        foreach ($engines as $table) {
            if (strcasecmp((string) $table['ENGINE'], 'InnoDB') !== 0) { throw new RuntimeException('source_snapshot_not_transactional'); }
        }
        // Adobe Commerce content staging uses row_id and version intervals.
        // Refuse it explicitly rather than exporting a future/duplicate version.
        $rowId = $this->rows('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',
            [$this->prefix . 'catalog_product_entity', 'row_id'], 1);
        if ($rowId) { throw new RuntimeException('source_schema_unavailable'); }
    }

    private function table(string $name): string { return '`' . $this->prefix . $name . '`'; }

    private function record(string $kind, int $id, array $payload): array
    {
        $payload['source_schema'] = 'magento-native-v1';
        if (strlen(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) > 16384) {
            throw new RuntimeException('source_snapshot_capacity');
        }
        return ['kind' => $kind, 'sourceId' => (string) $id, 'payload' => $payload];
    }

    private function rows(string $sql, array $parameters, int $limit): array
    {
        if (microtime(true) - $this->started > 5) { throw new RuntimeException('source_snapshot_timeout'); }
        $query = $this->db->prepare($sql . ' LIMIT ' . ($limit + 1)); $query->execute($parameters);
        $rows = $query->fetchAll();
        if (count($rows) > $limit) { throw new RuntimeException('source_snapshot_capacity'); }
        return $rows;
    }
}
