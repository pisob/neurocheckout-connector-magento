<?php
declare(strict_types=1);
namespace NeuroCheckout\Connector\Community;

use PDO;
use RuntimeException;

/** Native facts only. No audience selection, payment inference or persistence. */
final class CustomerOrderHistory
{
    private PDO $db;
    private string $prefix;
    private bool $newsletterEnabled;
    private float $started = 0;
    private const TABLES = ['store', 'store_website', 'customer_entity', 'sales_order', 'sales_order_item'];

    public function __construct(PDO $db, string $prefix, bool $newsletterEnabled)
    {
        if (!preg_match('/^[A-Za-z0-9_]*$/D', $prefix) || $db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new RuntimeException('customer_history_unavailable');
        }
        $this->db = $db; $this->prefix = $prefix; $this->newsletterEnabled = $newsletterEnabled;
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function read(int $scope, int $website, int $customer, string $email): array
    {
        $ownedTransaction = false;
        try {
            if ($scope < 1 || $website < 1 || $customer < 1 || strlen($email) > 254
                || !filter_var($email, FILTER_VALIDATE_EMAIL) || $this->db->inTransaction()) { throw new RuntimeException(); }
            $this->started = microtime(true);
            $version = (string) $this->db->getAttribute(PDO::ATTR_SERVER_VERSION);
            $this->db->exec(stripos($version, 'mariadb') !== false
                ? 'SET SESSION max_statement_time=1' : 'SET SESSION MAX_EXECUTION_TIME=1000');
            $names = array_map(function ($name) { return $this->prefix . $name; }, self::TABLES);
            $tables = $this->rows('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('
                . implode(',', array_fill(0, count($names), '?')) . ')', $names, count($names));
            if (count($tables) !== count($names)) { throw new RuntimeException(); }
            foreach ($tables as $table) { if (strcasecmp((string) $table['ENGINE'], 'InnoDB') !== 0) { throw new RuntimeException(); } }
            $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY'); $ownedTransaction = true;
            $stores = $this->rows('SELECT s.store_id FROM ' . $this->table('store') . ' s JOIN ' . $this->table('store_website')
                . ' w ON w.website_id=s.website_id WHERE s.store_id=? AND s.website_id=? AND s.is_active=1', [$scope,$website], 1);
            $customers = $this->rows('SELECT entity_id,email,group_id,created_at FROM ' . $this->table('customer_entity')
                . ' WHERE entity_id=? AND website_id=? AND is_active=1 AND (confirmation IS NULL OR confirmation=\'\')'
                . ' AND LOWER(email)=LOWER(?)', [$customer,$website,$email], 1);
            if (count($stores) !== 1 || count($customers) !== 1) { throw new RuntimeException(); }
            $person = $customers[0];
            // Read sales tables directly, including orders whose quote was removed.
            // Guest orders sharing an email are never borrowed by an account.
            $orders = $this->rows('SELECT entity_id,state,status,created_at,updated_at,order_currency_code,grand_total,total_paid,total_refunded'
                . ' FROM ' . $this->table('sales_order') . ' WHERE store_id=? AND customer_id=? AND customer_is_guest=0 ORDER BY entity_id',
                [$scope,$customer], 128);
            $lines = [];
            if ($orders) {
                $ids = array_column($orders, 'entity_id');
                $lines = $this->rows('SELECT item_id,order_id,parent_item_id,store_id,product_id,product_type,qty_ordered,qty_invoiced,qty_refunded,qty_canceled'
                    . ' FROM ' . $this->table('sales_order_item') . ' WHERE order_id IN (' . implode(',', array_fill(0, count($ids), '?'))
                    . ') ORDER BY order_id,item_id', $ids, 512);
            }
            $items = [];
            foreach ($lines as $line) {
                if ((int) $line['store_id'] !== $scope) { throw new RuntimeException(); }
                $items[(string) $line['order_id']][] = [
                    'item_id' => (string) $line['item_id'], 'parent_item_id' => $line['parent_item_id'] === null ? null : (string) $line['parent_item_id'],
                    'product_id' => $line['product_id'] === null ? null : (string) $line['product_id'], 'product_type' => $line['product_type'],
                    'qty_ordered' => (string) $line['qty_ordered'], 'qty_invoiced' => (string) $line['qty_invoiced'],
                    'qty_refunded' => (string) $line['qty_refunded'], 'qty_canceled' => (string) $line['qty_canceled'],
                ];
            }
            $result = ['schema' => 'magento-customer-history-v1', 'store_id' => $scope, 'website_id' => $website,
                'customer_id' => $customer, 'customer_email' => $person['email'], 'customer_group_id' => (int) $person['group_id'],
                'customer_created_at' => $person['created_at'], 'read_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'complete' => true, 'order_count' => count($orders), 'orders' => [],
                'marketing_consent' => $this->consent($scope,$customer,(string) $person['email'])];
            foreach ($orders as $order) {
                $result['orders'][] = ['order_id' => (string) $order['entity_id'], 'state' => $order['state'], 'status' => $order['status'],
                    'created_at' => $order['created_at'], 'updated_at' => $order['updated_at'], 'currency' => $order['order_currency_code'],
                    'grand_total' => (string) $order['grand_total'],
                    'total_paid' => $order['total_paid'] === null ? null : (string) $order['total_paid'],
                    'total_refunded' => $order['total_refunded'] === null ? null : (string) $order['total_refunded'],
                    'items' => $items[(string) $order['entity_id']] ?? []];
            }
            CustomerHistoryProtocol::history($result, ['storeId'=>(string)$scope,'websiteId'=>(string)$website,
                'customerId'=>(string)$customer,'customerEmail'=>$email]);
            if (microtime(true) - $this->started > 3) { throw new RuntimeException(); }
            return $result;
        } catch (\Throwable $error) { throw new RuntimeException('customer_history_unavailable'); }
        finally { if ($ownedTransaction && $this->db->inTransaction()) { $this->db->rollBack(); } }
    }

    private function consent(int $scope, int $customer, string $email): string
    {
        if (!$this->newsletterEnabled) { return 'unknown'; }
        try {
            $tables = $this->rows('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
                [$this->prefix.'newsletter_subscriber'], 1);
            if (count($tables) !== 1 || strcasecmp((string)$tables[0]['ENGINE'],'InnoDB') !== 0) { return 'unknown'; }
            $rows = $this->rows('SELECT subscriber_status,subscriber_email FROM '.$this->table('newsletter_subscriber')
                .' WHERE customer_id=? AND store_id=? ORDER BY subscriber_id', [$customer,$scope], 2);
            if (count($rows)!==1 || strcasecmp((string)$rows[0]['subscriber_email'],$email)!==0) { return 'unknown'; }
            $state = (string)$rows[0]['subscriber_status'];
            return $state==='1' ? 'granted' : (in_array($state,['2','3','4'],true) ? 'denied' : 'unknown');
        } catch (\Throwable $error) { return 'unknown'; }
    }

    private function table(string $name): string { return '`'.$this->prefix.$name.'`'; }
    private function rows(string $sql, array $parameters, int $limit): array
    {
        if (microtime(true)-$this->started>3) { throw new RuntimeException(); }
        $query=$this->db->prepare($sql.' LIMIT '.($limit+1)); $query->execute($parameters); $rows=$query->fetchAll();
        if (count($rows)>$limit || microtime(true)-$this->started>3) { throw new RuntimeException(); }
        return $rows;
    }
}
