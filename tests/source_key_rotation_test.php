<?php
declare(strict_types=1);
namespace Magento\Framework\App\Config {
    interface ScopeConfigInterface { const SCOPE_TYPE_DEFAULT='default'; }
    class Value {
        private array $data=[];
        public static int $deleted=0;
        public function __call($method,$args) {
            $key=substr($method,3);
            if (substr($method,0,3)==='set') { $this->data[$key]=$args[0]; return $this; }
            return $this->data[$key]??null;
        }
        public function beforeSave() { return $this; }
        public function beforeDelete() { self::$deleted++; return $this; }
    }
}
namespace Magento\Framework\App\Config\Storage { interface WriterInterface {} }
namespace Magento\Framework\App\Cache { interface TypeListInterface {} }
namespace Magento\Framework\Encryption { interface EncryptorInterface {} }
namespace Magento\Store\Model {
    interface ScopeInterface { const SCOPE_STORES='stores'; }
    interface StoreManagerInterface {}
}
namespace Magento\Framework\App {
    class ObjectManager {
        public static array $services=[];
        public static function getInstance() { return new self(); }
        public function get($class) { return self::$services[$class]; }
    }
}
namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    $root=sys_get_temp_dir().'/nc-magento-key-rotation-'.bin2hex(random_bytes(8));
    mkdir($root,0700);
    define('BP',$root);
    foreach (['Model/Config','Model/Config/Backend/ApiKey','Community/AutomaticSourceBinding','Community/ReconciledSourceExporter','Community/SourceStorageKey'] as $file) {
        require __DIR__.'/../app/code/NeuroCheckout/Connector/'.$file.'.php';
    }
    use NeuroCheckout\Connector\Model\Config;
    use NeuroCheckout\Connector\Model\Config\Backend\ApiKey;
    use NeuroCheckout\Connector\Community\AutomaticSourceBinding;
    use NeuroCheckout\Connector\Community\ReconciledSourceExporter;
    use Magento\Framework\App\ObjectManager;
    class FixtureScope implements \Magento\Framework\App\Config\ScopeConfigInterface {
        public array $values=[];
        public function getValue($path,$scope='default',$id=null) {
            if ($scope==='default') return $this->values['default:0'][$path]??'';
            if ($scope==='websites') return $this->values['websites:'.$id][$path]??$this->getValue($path);
            $id=$id??1; $website=$id===3?2:1;
            return $this->values['stores:'.$id][$path]??$this->getValue($path,'websites',$website);
        }
    }
    class FixtureWriter implements \Magento\Framework\App\Config\Storage\WriterInterface {
        public FixtureScope $config;
        public bool $writable=true;
        public function save($path,$value,$scope,$id) {
            if (!$this->writable) throw new RuntimeException('database unavailable');
            $this->config->values[$scope.':'.$id][$path]=$value;
        }
    }
    class FixtureEncryptor implements \Magento\Framework\Encryption\EncryptorInterface {
        private string $key;
        public function __construct() { $this->key=random_bytes(32); }
        public function encrypt($plain) {
            $iv=random_bytes(12); $tag='';
            $cipher=openssl_encrypt($plain,'aes-256-gcm',$this->key,OPENSSL_RAW_DATA,$iv,$tag);
            return base64_encode($iv.$tag.$cipher);
        }
        public function decrypt($value) {
            $raw=base64_decode($value,true);
            if ($raw===false||strlen($raw)<29) return '';
            return openssl_decrypt(substr($raw,28),'aes-256-gcm',$this->key,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16))?:'';
        }
    }
    class FixtureCache implements \Magento\Framework\App\Cache\TypeListInterface { public function cleanType($type) {} }
    class FixtureStore {
        private int $id;
        public function __construct($id) { $this->id=$id; }
        public function getId() { return $this->id; }
        public function getWebsiteId() { return $this->id===3?2:1; }
    }
    class FixtureStoreManager implements \Magento\Store\Model\StoreManagerInterface {
        public function getStores($withDefault=false) { return [new FixtureStore(1),new FixtureStore(2),new FixtureStore(3)]; }
    }
    $scope=new FixtureScope(); $writer=new FixtureWriter(); $writer->config=$scope;
    $encryptor=new FixtureEncryptor();
    $config=new Config($scope,$writer,$encryptor,new FixtureCache());
    ObjectManager::$services=[Config::class=>$config,\Magento\Framework\Encryption\EncryptorInterface::class=>$encryptor,
        \Magento\Store\Model\StoreManagerInterface::class=>new FixtureStoreManager()];
    $checks=0;
    function checkKey(bool $ok,string $message): void { global $checks; $checks++; if (!$ok) throw new RuntimeException($message); }
    function refuseKey(callable $call): void {
        try { $call(); } catch (RuntimeException $e) { checkKey(true,'refused'); return; }
        throw new RuntimeException('Unsafe key change accepted');
    }
    function binding(int $id,string $key): array {
        return ['environment'=>'production','platform'=>'magento','nativeScope'=>$id,'shopId'=>'fixture-'.$id,
            'secret'=>AutomaticSourceBinding::secret($key,'fixture-'.$id)];
    }
    function directory(int $id): string { return BP.'/var/neurocheckout-community-source/'.$id; }
    function backend(FixtureScope $scope,string $context,int $id,string $value): ApiKey {
        return (new ApiKey())->setPath(Config::XML_PATH_API_KEY)->setScope($context)->setScopeId($id)
            ->setOldValue($scope->getValue(Config::XML_PATH_API_KEY,$context,$id))->setValue($value);
    }
    $old=bin2hex(random_bytes(32)); $next=bin2hex(random_bytes(32));
    $scope->values['default:0']=[Config::XML_PATH_API_KEY=>$old,Config::XML_PATH_API_ENDPOINT=>'https://www.neurocheckout.com'];
    $reader=static function(){return [['kind'=>'cart','sourceId'=>'quote-1','payload'=>['status'=>'active']]];};
    $configs=[]; $pages=[];
    try {
        foreach ([1,2,3] as $id) {
            mkdir(directory($id),0700,true);
            $scope->values['stores:'.$id][Config::XML_PATH_SHOP_EXTERNAL_ID]='fixture-'.$id;
            $configs[$id]=binding($id,$old);
            $pages[$id]=(new ReconciledSourceExporter(directory($id),$configs[$id],$reader))->page([
                'schema'=>1,'shopId'=>'fixture-'.$id,'streamId'=>null,'cursor'=>'','limit'=>8]);
        }
        $config->setValue(Config::XML_PATH_API_KEY_NEXT,$next,1);
        checkKey($config->getString(Config::XML_PATH_API_KEY,1)===$old,'prepare/lazy encryption keeps old key');
        checkKey(!glob(directory(1).'/storage-*.key'),'prepare does not change source key');
        $config->setValue(Config::XML_PATH_API_KEY,$config->getString(Config::XML_PATH_API_KEY_NEXT,1),1);
        checkKey($config->getString(Config::XML_PATH_API_KEY,1)===$next,'automatic finalize activates new key');
        checkKey($config->getSourceStorageSecret(binding(1,$next),directory(1))===$configs[1]['secret'],'automatic rotation preserves old snapshot key');
        checkKey(!glob(directory(2).'/storage-*.key'),'store rotation leaves other stores untouched');
        $storage=binding(1,$next); $storage['secret']=$config->getSourceStorageSecret($storage,directory(1));
        $page=(new ReconciledSourceExporter(directory(1),$storage,$reader))->page([
            'schema'=>1,'shopId'=>'fixture-1','streamId'=>$pages[1]['streamId'],'cursor'=>$pages[1]['nextCursor'],'limit'=>8]);
        checkKey($page['records']===[] && $page['streamId']===$pages[1]['streamId'],'existing cursor continues');
        $masked=backend($scope,'stores',1,'******'); $masked->beforeSave();
        checkKey($masked->getValue()===$scope->getValue(Config::XML_PATH_API_KEY,'stores',1),'obscured unchanged input preserved');
        $websiteKey=bin2hex(random_bytes(32));
        $manual=backend($scope,'websites',1,$websiteKey); $manual->beforeSave();
        $writer->save(Config::XML_PATH_API_KEY,$manual->getValue(),'websites',1);
        checkKey($config->getString(Config::XML_PATH_API_KEY,2)===$websiteKey,'website manual save changes inherited key');
        checkKey($config->getSourceStorageSecret(binding(2,$websiteKey),directory(2))===$configs[2]['secret'],'website save preserves inherited child snapshot');
        checkKey(!glob(directory(3).'/storage-*.key'),'other website untouched');
        $global=backend($scope,'default',0,bin2hex(random_bytes(32))); $global->beforeSave();
        $writer->save(Config::XML_PATH_API_KEY,$global->getValue(),'default',0);
        checkKey($config->getSourceStorageSecret(binding(3,$config->getString(Config::XML_PATH_API_KEY,3)),directory(3))===$configs[3]['secret'],'global save includes all stores');
        // Returning to inheritance invokes beforeDelete, before losing the override.
        $inherit=backend($scope,'stores',1,''); $inherit->beforeDelete();
        unset($scope->values['stores:1'][Config::XML_PATH_API_KEY]);
        checkKey(\Magento\Framework\App\Config\Value::$deleted===1,'native delete lifecycle preserved');
        checkKey($config->getString(Config::XML_PATH_API_KEY,1)===$websiteKey,'website key inherited');
        checkKey($config->getSourceStorageSecret(binding(1,$websiteKey),directory(1))===$configs[1]['secret'],'inheritance retains source key');
        $writer->writable=false;
        refuseKey(static function() use($config){$config->setValue(Config::XML_PATH_API_KEY,bin2hex(random_bytes(32)),1);});
        checkKey($config->getString(Config::XML_PATH_API_KEY,1)===$websiteKey,'failed database write keeps active key');
        $writer->writable=true;
        $file=glob(directory(1).'/storage-*.key')[0]; file_put_contents($file,'corrupt');
        refuseKey(static function() use($config){$config->setValue(Config::XML_PATH_API_KEY,bin2hex(random_bytes(32)),1);});
        $broken=backend($scope,'stores',1,bin2hex(random_bytes(32)));
        refuseKey(static function() use($broken){$broken->beforeSave();});
        $deleted=\Magento\Framework\App\Config\Value::$deleted;
        refuseKey(static function() use($broken){$broken->beforeDelete();});
        checkKey(\Magento\Framework\App\Config\Value::$deleted===$deleted,'failed migration blocks inheritance change');
        checkKey($config->getString(Config::XML_PATH_API_KEY,1)===$websiteKey,'failed migration never overwrites active API key');
        echo $checks." Magento configuration rotation assertions passed.\n";
    } finally {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());
        }
        rmdir($root);
    }
}
