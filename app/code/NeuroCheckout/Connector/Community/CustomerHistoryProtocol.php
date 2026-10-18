<?php
declare(strict_types=1);
namespace NeuroCheckout\Connector\Community;
require_once __DIR__ . '/NativeHistoryV2.php';

/** Bounded native history wire shape; no marketing eligibility decisions. */
final class CustomerHistoryProtocol
{
    public static function request(array $input, string $shop): void
    {
        if (!self::keys($input,['schema','shopId','operation','storeId','websiteId','customerId','customerEmail'])
            || $input['schema']!==1 || $input['shopId']!==$shop || $input['operation']!=='customer_history') { self::fail(); }
        foreach(['storeId','websiteId','customerId'] as $k) { self::id($input[$k]); }
        if (!is_string($input['customerEmail']) || strlen($input['customerEmail'])>254
            || !filter_var($input['customerEmail'],FILTER_VALIDATE_EMAIL)) { self::fail(); }
    }
    public static function response($page, array $input, int $scope, string $raw): void
    {
        if (!self::keys($page,['schema','shopId','operation','requestHash','history']) || $page['schema']!==1
            || $page['shopId']!==$input['shopId'] || $page['operation']!=='customer_history'
            || $page['requestHash']!==hash('sha256',$raw) || (string)$scope!==$input['storeId']) { self::fail(); }
        self::history($page['history'],$input);
        if (strlen(json_encode($page,JSON_THROW_ON_ERROR))>65536) { self::fail(); }
    }
    public static function history($h, array $input): void
    {
        $h=NativeHistoryV2::legacy($h,'magento-customer-history-v1');
        if (!self::keys($h,['schema','store_id','website_id','customer_id','customer_email','customer_group_id',
                'customer_created_at','read_at','complete','order_count','orders','marketing_consent'])
            || $h['schema']!=='magento-customer-history-v1' || $h['complete']!==true
            || !is_int($h['customer_group_id']) || $h['customer_group_id']<0 || $h['customer_group_id']>9999999999
            || !is_string($h['customer_email']) || strcasecmp($h['customer_email'],$input['customerEmail'])!==0
            || !in_array($h['marketing_consent'],['granted','denied','unknown'],true)
            || !is_string($h['read_at']) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$h['read_at'])
            || abs(time()-(strtotime($h['read_at'])?:0))>15 || !self::list($h['orders']) || count($h['orders'])>128
            || $h['order_count']!==count($h['orders'])) { self::fail(); }
        foreach(['store_id'=>'storeId','website_id'=>'websiteId','customer_id'=>'customerId'] as $a=>$b) {
            if (!is_int($h[$a]) || (string)$h[$a]!==$input[$b]) { self::fail(); }
        }
        self::date($h['customer_created_at']); $orders=[]; $allItems=[]; $count=0;
        foreach($h['orders'] as $order) {
            if (!self::keys($order,['order_id','state','status','created_at','updated_at','currency','grand_total','total_paid','total_refunded','items'])) { self::fail(); }
            self::id($order['order_id']);
            if (isset($orders[$order['order_id']]) || !self::list($order['items']) || !$order['items']) { self::fail(); }
            $orders[$order['order_id']]=true;
            foreach(['state','status'] as $key) { if (!is_string($order[$key]) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$order[$key])) { self::fail(); } }
            if (!is_string($order['currency']) || !preg_match('/^[A-Z]{3}$/D',$order['currency'])) { self::fail(); }
            self::date($order['created_at']); self::date($order['updated_at']);
            self::decimal($order['grand_total']);
            foreach(['total_paid','total_refunded'] as $key) { if($order[$key]!==null) { self::decimal($order[$key]); } }
            $items=[];
            foreach($order['items'] as $item) {
                if (++$count>512 || !self::keys($item,['item_id','parent_item_id','product_id','product_type','qty_ordered','qty_invoiced','qty_refunded','qty_canceled'])) { self::fail(); }
                self::id($item['item_id']);
                if(isset($allItems[$item['item_id']])) { self::fail(); }
                $items[$item['item_id']]=$item; $allItems[$item['item_id']]=true;
                if($item['parent_item_id']!==null) { self::id($item['parent_item_id']); }
                if($item['product_id']!==null) { self::id($item['product_id']); }
                if(!is_string($item['product_type']) || !preg_match('/^[A-Za-z0-9_-]{1,32}$/D',$item['product_type'])) { self::fail(); }
                foreach(['qty_ordered','qty_invoiced','qty_refunded','qty_canceled'] as $key) { self::decimal($item[$key]); }
            }
            foreach($items as $id=>$item) {
                $parent=$item['parent_item_id'];
                if($parent!==null && (!isset($items[$parent]) || (string)$id===$parent || $items[$parent]['parent_item_id']!==null)) { self::fail(); }
            }
        }
        if(strlen(json_encode($h,JSON_THROW_ON_ERROR))>64000) { self::fail(); }
    }
    private static function date($v): void
    {
        if(!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$v)
            || !($d=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$v,new \DateTimeZone('UTC')))
            || $d->format('Y-m-d H:i:s')!==$v || $d->getTimestamp()>time()+15) { self::fail(); }
    }
    private static function decimal($v): void { if(!is_string($v) || !preg_match('/^\d{1,10}(?:\.\d{1,4})?$/D',$v)) { self::fail(); } }
    private static function id($v): void { if(!is_string($v) || !preg_match('/^[1-9][0-9]{0,9}$/D',$v)) { self::fail(); } }
    private static function list($v): bool { return is_array($v) && ($v===[] || array_keys($v)===range(0,count($v)-1)); }
    private static function keys($v,array $expected): bool { if(!is_array($v))return false;$keys=array_keys($v);sort($keys);sort($expected);return $keys===$expected; }
    private static function fail(): void { throw new \RuntimeException('customer_history_unavailable'); }
}
