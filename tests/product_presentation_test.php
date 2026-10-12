<?php
declare(strict_types=1);
namespace Magento\Store\Model { interface StoreManagerInterface { public function getStore($id); } }
namespace Magento\Framework { interface UrlInterface { public const URL_TYPE_MEDIA='media'; public const URL_TYPE_LINK='link'; } }
namespace {
    require __DIR__.'/../app/code/NeuroCheckout/Connector/Community/ProductPresentation.php';
    $scopes=[];
    $stores=new class($scopes) implements \Magento\Store\Model\StoreManagerInterface {
        public array $scopes=[];
        public string $media='https://cdn.example.invalid/store/media/';
        public function __construct(&$scopes) { $this->scopes=&$scopes; }
        public function getStore($id) {
            $this->scopes[]=$id;$media=$this->media;
            return new class($id,$media) {
                private int $id;private string $media;
                public function __construct($id,$media) {$this->id=$id;$this->media=$media;}
                public function getBaseUrl($type,$secure) {
                    if (!$secure) throw new \RuntimeException('HTTPS config required');
                    return $type==='media'?$this->media:'https://shop.example.invalid/store-'.$this->id.'/';
                }
            };
        }
    };
    function verify($ok) { if(!$ok)throw new \RuntimeException('Product presentation regression'); }
    $service=new \NeuroCheckout\Connector\Community\ProductPresentation($stores);
    $present=$service->forStore(2);
    $product=['entity_id'=>41,'attributes'=>['image'=>['effective'=>'/m/u/mug photo.jpg']]];
    $result=$present($product);
    verify($result['image_url']==='https://cdn.example.invalid/store/media/catalog/product/m/u/mug%20photo.jpg');
    verify($result['product_url']==='https://shop.example.invalid/store-2/catalog/product/view/id/41/');
    verify($scopes===[2]);
    $product['attributes']['image']['effective']='/e/t/été.jpg';
    verify($present($product)['image_url']==='https://cdn.example.invalid/store/media/catalog/product/e/t/%C3%A9t%C3%A9.jpg');
    foreach(['no_selection','', '/../secret.jpg','https://elsewhere.invalid/x.jpg',"/x\n.jpg"] as $invalid) {
        $product['attributes']['image']['effective']=$invalid;
        verify($present($product)['image_url']==='');verify($present($product)['image_status']==='absent');
    }
    $stores->media='https://private:secret@cdn.example.invalid/media/';
    $product['attributes']['image']['effective']='/m/u/photo.jpg';
    verify($service->forStore(1)($product)['image_url']==='');
    echo "PASS: native media base, store scope, exact image path, missing cover and unsafe URLs.\n";
}
