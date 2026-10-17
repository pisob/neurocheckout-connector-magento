<?php
declare(strict_types=1);
use NeuroCheckout\Connector\Community\SourcePullProtocol;
use NeuroCheckout\Connector\Community\SourcePullGateway;
use NeuroCheckout\Connector\Community\CustomerPricesProtocol;
foreach (['SourcePullProtocol','CustomerPricesProtocol','SourcePullGateway'] as $name) {
    require __DIR__.'/../app/code/NeuroCheckout/Connector/Community/'.$name.'.php';
}
$checks=0;
function priceProtocolCheck($ok, string $why): void { global $checks; $checks++; if(!$ok) throw new RuntimeException($why); }
$base=sys_get_temp_dir().'/nc-price-protocol-'.bin2hex(random_bytes(12));
mkdir($base,0700); mkdir($base.'/web',0700); mkdir($base.'/state',0700);
$secret=bin2hex(random_bytes(32)); $path='/neurocheckout/community/pull';
$config=['enabled'=>true,'environment'=>'production','nativeScope'=>2,'platform'=>'magento','shopId'=>'synthetic-shop','secret'=>$secret];
$input=['schema'=>1,'shopId'=>'synthetic-shop','operation'=>'customer_prices','storeId'=>'2','websiteId'=>'7',
    'customerId'=>'42','customerEmail'=>'private@example.test','productIds'=>['1','2'],'currency'=>'EUR'];
$prices=['schema'=>'magento-customer-prices-v1','store_id'=>2,'website_id'=>7,'customer_id'=>42,'customer_email'=>'private@example.test',
    'customer_group_id'=>3,'customer_tax_class_id'=>9,'currency'=>'EUR','quantity'=>1,'read_at'=>gmdate('Y-m-d\TH:i:s\Z'),
    'offers'=>[['product_id'=>'1','status'=>'available','currency'=>'EUR','price_excl_tax'=>10.0,'price_incl_tax'=>12.0,
    'display_mode'=>'including_tax','display_price'=>12.0],['product_id'=>'2','status'=>'unavailable']]];
$calls=0; $lastHeaders=[];
$invoke=static function($change=[], $mutate=null, $headerChange=[], $reuse=false, $https=true) use(&$calls,&$lastHeaders,$input,$prices,$config,$base,$secret,$path) {
    $request=array_replace($input,$change); $raw=json_encode($request,JSON_THROW_ON_ERROR);
    if(!$reuse) {
        $time=(string)(int)floor(microtime(true)*1000); $nonce=bin2hex(random_bytes(16));
        $lastHeaders=['content-type'=>'application/json','x-nc-source-time'=>$time,'x-nc-source-nonce'=>$nonce,
            'x-nc-source-signature'=>hash_hmac('sha256',implode("\n",['nc-source-pull-v1','POST',$path,'synthetic-shop',$time,$nonce,hash('sha256',$raw)]),hex2bin($secret))];
    }
    $exporter=static function($req) use(&$calls,$prices,$raw,$mutate) {
        $calls++;
        $page=['schema'=>1,'shopId'=>'synthetic-shop','operation'=>'customer_prices','requestHash'=>hash('sha256',$raw),'prices'=>$prices];
        return $mutate ? $mutate($page) : $page;
    };
    return SourcePullGateway::handle('magento',2,$base.'/web','POST',$path,array_replace($lastHeaders,$headerChange),$raw,$https,$exporter,$config,$base.'/state');
};
try {
    [$status,$headers,$raw]=$invoke();
    priceProtocolCheck($status===200 && $calls===1,'authenticated price route reaches reader once');
    priceProtocolCheck($headers['Cache-Control']==='no-store, private','private responses never cached');
    priceProtocolCheck(hash_equals(SourcePullProtocol::responseSignature($secret,$lastHeaders['x-nc-source-nonce'],$raw),$headers['X-NC-Source-Response']),'exact response signature');
    priceProtocolCheck($invoke([],null,[],true)[0]===409 && $calls===1,'replay denied before reader');
    foreach ([['x-nc-source-signature'=>str_repeat('0',64)],['origin'=>'https://browser.invalid'],['cookie'=>'session=x'],['content-encoding'=>'gzip'],['x-nc-source-time'=>'0']] as $change) {
        $before=$calls; priceProtocolCheck($invoke([],null,$change)[0]!==200 && $calls===$before,'authentication failure before native lookup');
    }
    $before=$calls; priceProtocolCheck($invoke([],null,[],false,false)[0]===403 && $calls===$before,'TLS required');
    foreach ([['storeId'=>'3'],['storeId'=>2],['websiteId'=>'0'],['customerId'=>'42junk'],['customerEmail'=>'bad'],['productIds'=>[]],
        ['productIds'=>['1','1']],['productIds'=>range(1,17)],['productIds'=>[true]],['currency'=>'eur'],['shopId'=>'foreign'],
        ['url'=>'https://external.invalid'],['operation'=>'write_prices']] as $change) {
        $before=$calls; priceProtocolCheck($invoke($change)[0]!==200 && $calls===$before,'invalid request cannot reach native reader');
    }
    foreach (['request','shop','customer','email','store','website','currency','old','future','extra','missing','duplicate','negative','boolean','tax','display','dual','address'] as $mode) {
        $mutate=static function($page) use($mode) {
            if($mode==='request') $page['requestHash']=str_repeat('f',64);
            if($mode==='shop') $page['shopId']='foreign';
            if($mode==='customer') $page['prices']['customer_id']=43;
            if($mode==='email') $page['prices']['customer_email']='foreign@example.test';
            if($mode==='store') $page['prices']['store_id']=3;
            if($mode==='website') $page['prices']['website_id']=8;
            if($mode==='currency') $page['prices']['currency']='USD';
            if($mode==='old') $page['prices']['read_at']=gmdate('Y-m-d\TH:i:s\Z',time()-16);
            if($mode==='future') $page['prices']['read_at']=gmdate('Y-m-d\TH:i:s\Z',time()+16);
            if($mode==='extra') $page['prices']['offers'][]=$page['prices']['offers'][0];
            if($mode==='missing') array_pop($page['prices']['offers']);
            if($mode==='duplicate') $page['prices']['offers'][1]=$page['prices']['offers'][0];
            if($mode==='negative') $page['prices']['offers'][0]['price_excl_tax']=-1;
            if($mode==='boolean') $page['prices']['offers'][0]['price_excl_tax']=true;
            if($mode==='tax') $page['prices']['offers'][0]['price_incl_tax']=1;
            if($mode==='display') $page['prices']['offers'][0]['display_price']=10;
            if($mode==='dual') $page['prices']['offers'][0]['display_mode']='both';
            if($mode==='address') $page['prices']['address']='private address';
            return $page;
        };
        [$status,$headers,$body]=$invoke([],$mutate);
        priceProtocolCheck($status!==200 && !isset($headers['X-NC-Source-Response']) && strpos($body,'private')===false,'bad result redacted: '.$mode);
    }
    [$status]=$invoke([],static function($page){$page['prices']['offers'][0]['display_mode']='both';$page['prices']['offers'][0]['display_price']=null;return $page;});
    priceProtocolCheck($status===200,'dual tax display preserved');
    foreach(glob($base.'/state/*') as $file) {
        priceProtocolCheck(strpos(file_get_contents($file),'private@example.test')===false,'nonce state contains no email or price result');
    }
    echo $checks." authenticated native price protocol assertions passed (synthetic private state).\n";
} finally {
    // Only this test's explicitly created disposable fixture.
    foreach(glob($base.'/state/*') as $file) unlink($file);
    rmdir($base.'/state'); rmdir($base.'/web'); rmdir($base);
}
