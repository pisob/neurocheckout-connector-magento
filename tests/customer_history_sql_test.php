<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') { exit(1); }
foreach(['CustomerHistoryProtocol','CustomerOrderHistory','SourcePullProtocol','SourcePullGateway'] as $name) {
    require __DIR__.'/../app/code/NeuroCheckout/Connector/Community/'.$name.'.php';
}
use NeuroCheckout\Connector\Community\CustomerOrderHistory;
use NeuroCheckout\Connector\Community\CustomerHistoryProtocol;
use NeuroCheckout\Connector\Community\SourcePullProtocol;
use NeuroCheckout\Connector\Community\SourcePullGateway;
$socket=$argv[1]??'';
if(!preg_match('#^/tmp/nc-source-mariadb\.[A-Za-z0-9]+/mariadb\.sock$#D',$socket)||!file_exists($socket))exit(2);
$checks=0;
function historyCheck($value,string $reason):void { global $checks;$checks++;if(!$value)throw new RuntimeException($reason); }
function historyRefuse(callable $call):void {
    try{$call();}catch(Throwable $e){historyCheck($e->getMessage()==='customer_history_unavailable','constant private error');return;}
    throw new RuntimeException('unexpected history acceptance');
}
class HistoryTestPDO extends PDO {
    public $beforeOrderRead=null;
    #[\ReturnTypeWillChange]
    public function prepare($query,$options=[]) {
        if($this->beforeOrderRead!==null && strpos($query,'FROM `mh_sales_order` WHERE')!==false) {
            $run=$this->beforeOrderRead;$this->beforeOrderRead=null;$run($this);
        }
        return parent::prepare($query,$options);
    }
}
$dsn='mysql:unix_socket='.$socket.';charset=utf8mb4';
$database='nc_customer_history_fixture_'.bin2hex(random_bytes(6));
$admin=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$admin->exec('CREATE DATABASE `'.$database.'`');
$directory=sys_get_temp_dir().'/nc-history-protocol-'.bin2hex(random_bytes(12));mkdir($directory,0700);
try {
    $writer=new PDO($dsn.';dbname='.$database,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $schemas=[
        'store_website'=>'website_id INT PRIMARY KEY',
        'store'=>'store_id INT PRIMARY KEY,website_id INT,is_active INT',
        'customer_entity'=>'entity_id INT PRIMARY KEY,website_id INT,email VARCHAR(255),group_id INT,created_at DATETIME,is_active INT,confirmation VARCHAR(64) NULL',
        'sales_order'=>'entity_id INT PRIMARY KEY,store_id INT,customer_id INT,customer_is_guest INT,state VARCHAR(32),status VARCHAR(64),created_at DATETIME,updated_at DATETIME,order_currency_code CHAR(3),grand_total DECIMAL(20,4),total_paid DECIMAL(20,4) NULL,total_refunded DECIMAL(20,4) NULL',
        'sales_order_item'=>'item_id INT PRIMARY KEY,order_id INT,parent_item_id INT NULL,store_id INT,product_id INT NULL,product_type VARCHAR(32),qty_ordered DECIMAL(12,4),qty_invoiced DECIMAL(12,4),qty_refunded DECIMAL(12,4),qty_canceled DECIMAL(12,4)',
        'newsletter_subscriber'=>'subscriber_id INT PRIMARY KEY,customer_id INT,store_id INT,subscriber_email VARCHAR(255),subscriber_status INT',
    ];
    foreach($schemas as $table=>$schema)$writer->exec('CREATE TABLE mh_'.$table.' ('.$schema.') ENGINE=InnoDB');
    $writer->exec('INSERT INTO mh_store_website VALUES(1),(2)');
    $writer->exec('INSERT INTO mh_store VALUES(1,1,1),(2,1,1),(3,2,1),(4,1,0)');
    $writer->exec("INSERT INTO mh_customer_entity VALUES(7,1,'private@example.test',2,'2020-01-01 00:00:00',1,NULL),
        (8,2,'private@example.test',2,'2020-01-01 00:00:00',1,NULL),(9,1,'new@example.test',2,'2020-01-01 00:00:00',1,NULL)");
    $writer->exec("INSERT INTO mh_newsletter_subscriber VALUES(1,7,1,'private@example.test',1)");
    $writer->exec("INSERT INTO mh_sales_order VALUES
        (1,1,7,0,'new','pending','2020-02-01 00:00:00','2020-02-01 00:00:00','EUR',30,NULL,NULL),
        (2,1,7,0,'canceled','canceled','2020-03-01 00:00:00','2020-03-01 00:00:00','EUR',20,0,NULL),
        (3,1,7,0,'complete','complete','2020-04-01 00:00:00','2020-04-01 00:00:00','EUR',40,40,0),
        (4,1,7,0,'closed','closed','2020-05-01 00:00:00','2020-05-01 00:00:00','USD',50,50,50),
        (5,2,7,0,'complete','complete','2020-06-01 00:00:00','2020-06-01 00:00:00','EUR',999,999,0),
        (6,1,8,0,'complete','complete','2020-06-01 00:00:00','2020-06-01 00:00:00','EUR',999,999,0),
        (7,1,7,1,'complete','complete','2020-06-01 00:00:00','2020-06-01 00:00:00','EUR',999,999,0)");
    $writer->exec("INSERT INTO mh_sales_order_item VALUES
        (11,1,NULL,1,10,'simple',1,0,0,0),(12,2,NULL,1,20,'simple',1,0,0,1),
        (13,3,NULL,1,30,'configurable',2,2,0,0),(14,3,13,1,31,'simple',2,2,0,0),
        (15,4,NULL,1,NULL,'simple',1,1,1,0)");
    $db=new HistoryTestPDO($dsn.';dbname='.$database,'root','');
    $reader=new CustomerOrderHistory($db,'mh_',true);
    $input=['storeId'=>'1','websiteId'=>'1','customerId'=>'7','customerEmail'=>'private@example.test'];
    $h=$reader->read(1,1,7,'private@example.test');
    historyCheck($h['complete'] && $h['order_count']===4,'complete orders without any quote table');
    historyCheck(array_column($h['orders'],'order_id')===['1','2','3','4'],'no foreign store/customer/guest orders');
    historyCheck($h['orders'][0]['total_paid']===null && $h['orders'][1]['state']==='canceled','unpaid and canceled not converted into paid');
    historyCheck($h['orders'][2]['total_paid']==='40.0000' && $h['orders'][3]['total_refunded']==='50.0000','decimal amounts preserved');
    historyCheck($h['orders'][3]['currency']==='USD' && $h['orders'][3]['items'][0]['product_id']===null,'native currency and deleted product preserved');
    historyCheck($h['orders'][2]['items'][1]['parent_item_id']==='13','native parent-child relation retained');
    historyCheck($h['marketing_consent']==='granted' && !$db->inTransaction(),'consent read with completed read-only transaction');
    historyCheck($reader->read(1,1,9,'new@example.test')['orders']===[],'proven empty account history');
    historyCheck((new CustomerOrderHistory($db,'mh_',false))->read(1,1,7,'private@example.test')['marketing_consent']==='unknown','disabled newsletter never grants');
    foreach([[1,2,7,'private@example.test'],[1,1,8,'private@example.test'],[1,1,7,'other@example.test'],[4,1,7,'private@example.test']] as $args)
        historyRefuse(static function()use($reader,$args){$reader->read(...$args);});
    foreach(["is_active=0","confirmation='pending'"] as $change) {
        $writer->exec('UPDATE mh_customer_entity SET '.$change.' WHERE entity_id=7');
        historyRefuse(static function()use($reader){$reader->read(1,1,7,'private@example.test');});
        $writer->exec('UPDATE mh_customer_entity SET is_active=1,confirmation=NULL WHERE entity_id=7');
    }
    foreach([2,3,4] as $state) {
        $writer->exec('UPDATE mh_newsletter_subscriber SET subscriber_status='.$state);
        historyCheck($reader->read(1,1,7,'private@example.test')['marketing_consent']==='denied','native refusal not opt-in');
    }
    $writer->exec('UPDATE mh_newsletter_subscriber SET subscriber_status=1,store_id=2');
    historyCheck($reader->read(1,1,7,'private@example.test')['marketing_consent']==='unknown','other view cannot grant');
    $writer->exec("UPDATE mh_newsletter_subscriber SET store_id=1,subscriber_email='changed@example.test'");
    historyCheck($reader->read(1,1,7,'private@example.test')['marketing_consent']==='unknown','changed address cannot grant');
    $writer->exec("UPDATE mh_newsletter_subscriber SET subscriber_email='private@example.test'");
    $writer->exec('INSERT INTO mh_newsletter_subscriber SELECT 2,customer_id,store_id,subscriber_email,subscriber_status FROM mh_newsletter_subscriber WHERE subscriber_id=1');
    historyCheck($reader->read(1,1,7,'private@example.test')['marketing_consent']==='unknown','duplicate grant refused');
    $writer->exec('DELETE FROM mh_newsletter_subscriber WHERE subscriber_id=2');
    $writer->exec('RENAME TABLE mh_newsletter_subscriber TO mh_newsletter_hidden');
    historyCheck($reader->read(1,1,7,'private@example.test')['marketing_consent']==='unknown','missing optional newsletter never grants');
    $writer->exec('RENAME TABLE mh_newsletter_hidden TO mh_newsletter_subscriber');
    $db->beforeOrderRead=static function($connection)use($writer) {
        $refused=false;try{$connection->exec('UPDATE mh_customer_entity SET group_id=9');}catch(PDOException $e){$refused=true;}
        historyCheck($refused,'native transaction blocks writes');
        $writer->exec('UPDATE mh_sales_order SET total_paid=30 WHERE entity_id=1');
    };
    historyCheck($reader->read(1,1,7,'private@example.test')['orders'][0]['total_paid']===null,'concurrent payment cannot mix snapshot');
    historyCheck($reader->read(1,1,7,'private@example.test')['orders'][0]['total_paid']==='30.0000','next consistent read sees payment');
    $db->beginTransaction();historyRefuse(static function()use($reader){$reader->read(1,1,7,'private@example.test');});
    historyCheck($db->inTransaction(),'caller transaction not rolled back');$db->rollBack();
    foreach(["store_id=2","parent_item_id=12","qty_ordered=-1"] as $change) {
        $writer->exec('UPDATE mh_sales_order_item SET '.$change.' WHERE item_id=14');
        historyRefuse(static function()use($reader){$reader->read(1,1,7,'private@example.test');});
        $writer->exec('UPDATE mh_sales_order_item SET store_id=1,parent_item_id=13,qty_ordered=2 WHERE item_id=14');
    }
    $writer->exec('ALTER TABLE mh_sales_order_item ENGINE=MyISAM');
    historyRefuse(static function()use($reader){$reader->read(1,1,7,'private@example.test');});
    $writer->exec('ALTER TABLE mh_sales_order_item ENGINE=InnoDB');
    $secret=bin2hex(random_bytes(32));$path='/neurocheckout/community/pull';
    $config=['enabled'=>true,'environment'=>'production','nativeScope'=>1,'platform'=>'magento','shopId'=>'synthetic-shop','secret'=>$secret];
    $request=['schema'=>1,'shopId'=>'synthetic-shop','operation'=>'customer_history']+$input;
    foreach([['schema'=>true],['shopId'=>'foreign'],['operation'=>'read'],['storeId'=>1],['storeId'=>'01'],
        ['websiteId'=>'0'],['customerId'=>false],['customerEmail'=>'invalid'],['callback'=>'https://other.invalid']] as $patch) {
        historyRefuse(static function()use($request,$patch){CustomerHistoryProtocol::request(array_replace($request,$patch),'synthetic-shop');});
    }
    $raw=json_encode($request);
    $nonce=bin2hex(random_bytes(16));$time=(string)(int)(microtime(true)*1000);
    $headers=['content-type'=>'application/json','x-nc-source-time'=>$time,'x-nc-source-nonce'=>$nonce,
        'x-nc-source-signature'=>hash_hmac('sha256',implode("\n",['nc-source-pull-v1','POST',$path,'synthetic-shop',$time,$nonce,hash('sha256',$raw)]),hex2bin($secret))];
    $calls=0;
    $exporter=static function($request)use($reader,$raw,&$calls) {
        $calls++;return ['schema'=>1,'shopId'=>'synthetic-shop','operation'=>'customer_history','requestHash'=>hash('sha256',$raw),
            'history'=>$reader->read((int)$request['storeId'],(int)$request['websiteId'],(int)$request['customerId'],$request['customerEmail'])];
    };
    $invoke=static function($changes=[])use($path,$headers,$raw,$exporter,$config,$directory) {
        return SourcePullGateway::handle('magento',1,__DIR__,'POST',$path,array_replace($headers,$changes),$raw,true,$exporter,$config,$directory);
    };
    [$status,$responseHeaders,$response]=$invoke();
    historyCheck($status===200 && $calls===1,'native authenticated history route');
    historyCheck($responseHeaders['X-NC-Source-Response']===SourcePullProtocol::responseSignature($secret,$nonce,$response),'native history response HMAC');
    historyCheck($invoke()[0]===409 && $calls===1,'replay blocked before history read');
    historyCheck($invoke(['cookie'=>'session=unsafe'])[0]!==200 && $calls===1,'no browser credentials');
    historyCheck($invoke(['x-nc-source-signature'=>str_repeat('0',64)])[0]===401 && $calls===1,'no unauthenticated history');
    foreach(['foreign','partial','paid_boolean','missing_item','duplicate_order','future','parent_cycle','oversize','address'] as $case) {
        $bad=$h;
        if($case==='foreign')$bad['customer_id']=8;
        if($case==='partial')$bad['complete']=false;
        if($case==='paid_boolean')$bad['orders'][0]['total_paid']=true;
        if($case==='missing_item')$bad['orders'][0]['items']=[];
        if($case==='duplicate_order'){$bad['orders'][1]['order_id']='1';}
        if($case==='future')$bad['customer_created_at']='2099-01-01 00:00:00';
        if($case==='parent_cycle')$bad['orders'][2]['items'][0]['parent_item_id']='14';
        if($case==='oversize')$bad['orders'][0]['grand_total']=str_repeat('1',65000);
        if($case==='address')$bad['address']='private';
        historyRefuse(static function()use($bad,$input){CustomerHistoryProtocol::history($bad,$input);});
    }
    $db->beforeOrderRead=static function()use($reader){$p=new ReflectionProperty($reader,'started');$p->setAccessible(true);$p->setValue($reader,0);};
    historyRefuse(static function()use($reader){$reader->read(1,1,7,'private@example.test');});
    // A valid-shaped payload may hit the byte cap before the item-count cap.
    $bad=$h;$bad['orders'][0]['items']=[];
    for($id=100;$id<500;$id++)$bad['orders'][0]['items'][]=array_replace($h['orders'][0]['items'][0],['item_id'=>(string)$id]);
    historyCheck(strlen(json_encode($bad))>64000,'valid fields exceed byte capacity');
    historyRefuse(static function()use($bad,$input){CustomerHistoryProtocol::history($bad,$input);});
    for($id=1000;$id<1513;$id++)$writer->exec('INSERT INTO mh_sales_order_item SELECT '.$id.',order_id,NULL,store_id,product_id,product_type,qty_ordered,qty_invoiced,qty_refunded,qty_canceled FROM mh_sales_order_item WHERE item_id=11');
    historyRefuse(static function()use($reader){$reader->read(1,1,7,'private@example.test');});
    $writer->exec('DELETE FROM mh_sales_order_item WHERE item_id>=1000');
    for($id=100;$id<229;$id++)$writer->exec('INSERT INTO mh_sales_order SELECT '.$id.',store_id,customer_id,customer_is_guest,state,status,created_at,updated_at,order_currency_code,grand_total,total_paid,total_refunded FROM mh_sales_order WHERE entity_id=1');
    historyRefuse(static function()use($reader){$reader->read(1,1,7,'private@example.test');});
    foreach(glob($directory.'/*') as $file)historyCheck(strpos((string)file_get_contents($file),'private@example.test')===false,'only nonce/rate state persisted');
    echo "$checks native customer-history SQL/protocol assertions passed (synthetic private schema).\n";
} finally {
    if(isset($db)&&$db->inTransaction())$db->rollBack();
    $admin->exec('DROP DATABASE `'.$database.'`');
    foreach(glob($directory.'/*') as $file)if(is_file($file)&&!is_link($file))unlink($file);
    rmdir($directory);
}
