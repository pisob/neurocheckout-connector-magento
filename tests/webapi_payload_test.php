<?php
declare(strict_types=1);
namespace Magento\Framework\Webapi {
    class Exception extends \RuntimeException {
        public int $httpStatus;
        public function __construct($message, $code = 0, $httpStatus = 500) {
            parent::__construct((string)$message, $code); $this->httpStatus=$httpStatus;
        }
    }
}
namespace Magento\Framework\Webapi\Rest {
    class Request {
        public string $body='';
        public string $encoding='';
        public function getContent(): string { return $this->body; }
        public function getHeader($name): string { return $name==='Content-Encoding' ? $this->encoding : ''; }
    }
}
namespace Magento\Store\Model { interface StoreManagerInterface {} }
namespace {
    function __($message) { return $message; }
    require dirname(__DIR__).'/app/code/NeuroCheckout/Connector/Model/Webapi/AbstractEndpoint.php';
    class EndpointHarness extends \NeuroCheckout\Connector\Model\Webapi\AbstractEndpoint {
        public function normalize($payload=[]): array { return $this->normalizePayload($payload); }
        public function raw(): string { return $this->getRawBody(); }
    }
    $request=new \Magento\Framework\Webapi\Rest\Request();
    $endpoint=new EndpointHarness($request,new class implements \Magento\Store\Model\StoreManagerInterface {});
    $expected=['store_id'=>1,'products'=>[['id'=>3,'quantity'=>2]],'customer'=>['email'=>'fixture@example.invalid']];
    foreach ([$expected,['payload'=>$expected]] as $input) {
        $request->body=json_encode($input);
        if ($endpoint->normalize()!==$expected) throw new \RuntimeException('Nested JSON keys or flat protocol changed');
        if ($endpoint->raw()!==json_encode($input)) throw new \RuntimeException('Signed request bytes changed');
    }
    foreach (['{','null','[]','[1]','"text"','{"payload":false}', '{"payload":[],"store_id":2}', '{"payload":[1]}'] as $bad) {
        $request->body=$bad;
        try { $endpoint->normalize(); throw new \RuntimeException('Invalid JSON accepted'); }
        catch (\Magento\Framework\Webapi\Exception $error) {
            if ($error->httpStatus!==422) throw new \RuntimeException('Invalid JSON must be 422');
        }
    }
    $request->body='';
    if ($endpoint->normalize($expected)!==$expected) throw new \RuntimeException('Direct PHP call changed');
    try { $endpoint->normalize('invalid'); throw new \RuntimeException('Scalar accepted'); }
    catch (\Magento\Framework\Webapi\Exception $error) {}
    foreach (glob(dirname(__DIR__).'/app/code/NeuroCheckout/Connector/Api/*ManagementInterface.php') as $file) {
        require_once $file;
        $class='NeuroCheckout\\Connector\\Api\\'.basename($file,'.php');
        $method=new \ReflectionMethod($class,'execute');
        if (!str_contains($method->getDocComment(),'@param mixed $payload') ||
            !str_contains($method->getDocComment(),'@return mixed[]') ||
            $method->getParameters()[0]->hasType()) throw new \RuntimeException('Unsupported Magento Web API contract: '.$class);
    }
    require dirname(__DIR__).'/app/code/NeuroCheckout/Connector/Model/Webapi/OrderHistoryManagement.php';
    $history=(new \ReflectionClass(\NeuroCheckout\Connector\Model\Webapi\OrderHistoryManagement::class))->newInstanceWithoutConstructor();
    $property=new \ReflectionProperty($history,'request'); $property->setAccessible(true); $property->setValue($history,$request);
    $method=new \ReflectionMethod($history,'normalizeHistoryPayload'); $method->setAccessible(true);
    foreach (['','gzip'] as $encoding) {
        $request->encoding=$encoding;
        foreach ([$expected,['payload'=>$expected]] as $input) {
            $request->body=$encoding==='gzip' ? gzencode(json_encode($input)) : json_encode($input);
            if ($method->invoke($history,[])!==$expected) throw new \RuntimeException('Order history JSON/gzip changed');
        }
        $request->body=$encoding==='gzip' ? gzencode('{"payload":"invalid"}') : '{"payload":"invalid"}';
        try { $method->invoke($history,'invalid'); throw new \RuntimeException('Invalid history payload accepted'); }
        catch (\Magento\Framework\Webapi\Exception $error) { if($error->httpStatus!==422) throw $error; }
    }
    echo "Web API flat/wrapped JSON and gzip, signature bytes, invalid payloads and all 6 contracts passed.\n";
}
