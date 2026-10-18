<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { exit(1); }
require_once __DIR__.'/../app/code/NeuroCheckout/Connector/Community/CustomerOrderHistory.php';
use NeuroCheckout\Connector\Community\CustomerOrderHistory;
$socket=$argv[1]??'';
if (!preg_match('#^/tmp/nc-source-mariadb\.[A-Za-z0-9]+/mariadb\.sock$#D',$socket)||!file_exists($socket)) { exit(2); }
$checks=0;
function checkCart(bool $ok):void { global $checks;++$checks;if(!$ok)throw new RuntimeException('cart assertion failed'); }
function refuseCart(callable $fn):void {
    try{$fn();}catch(Throwable $e){checkCart($e->getMessage()==='customer_history_unavailable');return;}
    throw new RuntimeException('unexpected cart acceptance');
}
class CartTestPDO extends PDO {
    public $onCart=null;
    #[\ReturnTypeWillChange]
    public function prepare($query,$options=[]) {
        if ($this->onCart!==null && strpos($query,'SELECT entity_id,customer_is_guest FROM')===0) {
            $fn=$this->onCart;$this->onCart=null;$fn($this);
        }
        return parent::prepare($query,$options);
    }
}
$dsn='mysql:unix_socket='.$socket.';charset=utf8mb4';$database='nc_magento_carts_fixture_'.bin2hex(random_bytes(6));
$admin=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$admin->exec('CREATE DATABASE `'.$database.'`');
try {
    $writer=new PDO($dsn.';dbname='.$database,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    foreach ([
        'store'=>'store_id INT PRIMARY KEY,website_id INT,is_active INT',
        'store_website'=>'website_id INT PRIMARY KEY',
        'customer_entity'=>'entity_id INT PRIMARY KEY,website_id INT,email VARCHAR(254),group_id INT,created_at DATETIME,is_active INT,confirmation VARCHAR(32)',
        'quote'=>'entity_id INT PRIMARY KEY,store_id INT,customer_id INT,customer_is_guest INT,is_active INT',
        'quote_item'=>'item_id INT AUTO_INCREMENT PRIMARY KEY,quote_id INT,store_id INT,qty DECIMAL(12,4)',
        'newsletter_subscriber'=>'subscriber_id INT PRIMARY KEY,store_id INT,customer_id INT,subscriber_email VARCHAR(254),subscriber_status INT',
    ] as $name=>$schema) {$writer->exec('CREATE TABLE mc_'.$name.' ('.$schema.') ENGINE=InnoDB');}
    $writer->exec('INSERT INTO mc_store_website VALUES(1),(2)');$writer->exec('INSERT INTO mc_store VALUES(1,1,1),(2,2,1)');
    $writer->exec("INSERT INTO mc_customer_entity VALUES(7,1,'private@example.invalid',1,'2020-01-01',1,NULL),(8,2,'other@example.invalid',1,'2020-01-01',1,NULL)");
    $writer->exec("INSERT INTO mc_newsletter_subscriber VALUES(1,1,7,'private@example.invalid',1)");
    $db=new CartTestPDO($dsn.';dbname='.$database,'root','');$reader=new CustomerOrderHistory($db,'mc_',true);
    $read=static function()use($reader){return $reader->read(1,1,7,'private@example.invalid',true);};
    checkCart($read()['has_active_cart']===false && !$db->inTransaction());
    $writer->exec('INSERT INTO mc_quote VALUES(1,1,7,0,1),(2,1,8,0,1),(3,2,7,0,1),(4,1,7,0,0),(5,1,7,0,1)');
    $writer->exec('INSERT INTO mc_quote_item(quote_id,store_id,qty) VALUES(2,1,1),(3,2,1),(4,1,1)');
    checkCart($read()['has_active_cart']===false);
    $writer->exec('INSERT INTO mc_quote_item(quote_id,store_id,qty) VALUES(1,1,1),(5,1,2)');
    checkCart($read()['has_active_cart']===true);
    $writer->exec('UPDATE mc_quote SET is_active=0 WHERE entity_id=1');checkCart($read()['has_active_cart']===true);
    $writer->exec('UPDATE mc_quote_item SET qty=0 WHERE quote_id=5');checkCart($read()['has_active_cart']===false);
    foreach (['qty=-1','qty=NULL','store_id=2'] as $change) {
        $writer->exec('UPDATE mc_quote_item SET '.$change.' WHERE quote_id=5');refuseCart($read);
        $writer->exec('UPDATE mc_quote_item SET qty=0,store_id=1 WHERE quote_id=5');
    }
    $writer->exec('UPDATE mc_quote SET customer_is_guest=1 WHERE entity_id=5');refuseCart($read);
    $writer->exec('UPDATE mc_quote SET customer_is_guest=0 WHERE entity_id=5');
    foreach (['is_active=0',"confirmation='pending'","email='foreign@example.invalid'",'website_id=2'] as $change) {
        $writer->exec('UPDATE mc_customer_entity SET '.$change.' WHERE entity_id=7');refuseCart($read);
        $writer->exec("UPDATE mc_customer_entity SET is_active=1,confirmation=NULL,email='private@example.invalid',website_id=1 WHERE entity_id=7");
    }
    foreach ([1=>'granted',2=>'denied',9=>'unknown'] as $flag=>$state) {
        $writer->exec('UPDATE mc_newsletter_subscriber SET subscriber_status='.$flag);checkCart($read()['marketing_consent']===$state);
    }
    refuseCart(static function()use($reader){$reader->read(2,1,7,'private@example.invalid',true);});
    $db->beginTransaction();refuseCart($read);checkCart($db->inTransaction());$db->rollBack();
    $db->onCart=static function($connection) {try{$connection->exec('UPDATE mc_customer_entity SET group_id=2');}catch(PDOException $e){checkCart(true);return;}throw new RuntimeException('not read-only');};
    $read();checkCart(!$db->inTransaction());
    $db->onCart=static function()use($writer){$writer->exec('UPDATE mc_quote_item SET qty=3 WHERE quote_id=5');};
    checkCart($read()['has_active_cart']===false);checkCart($read()['has_active_cart']===true);
    foreach (['quote_item','newsletter_subscriber'] as $table) {
        $writer->exec('ALTER TABLE mc_'.$table.' ENGINE=MyISAM');refuseCart($read);checkCart(!$db->inTransaction());
        $writer->exec('ALTER TABLE mc_'.$table.' ENGINE=InnoDB');
    }
    $insert=$writer->prepare('INSERT INTO mc_quote VALUES(?,1,7,0,1)');
    for($id=10;$id<138;$id++){$insert->execute([$id]);}refuseCart($read);
    $writer->exec('DELETE FROM mc_quote WHERE entity_id>=10');
    $insert=$writer->prepare('INSERT INTO mc_quote_item(quote_id,store_id,qty) VALUES(5,1,1)');
    for($i=0;$i<512;$i++){$insert->execute();}refuseCart($read);
    $writer->exec('DROP TABLE mc_quote_item');refuseCart($read);
    echo "Magento active carts: $checks assertions passed\n";
} finally { $admin->exec('DROP DATABASE `'.$database.'`'); }
