<?php
declare(strict_types=1);
// Actual recovery controller; synthetic collaborators, no real store/session.
namespace Magento\Framework\App\Action {
    class Context {}
    class Action {
        public $resultRedirectFactory;
        public function __construct($context) {
            $this->resultRedirectFactory = new class {
                public function create() { return new class { public function setUrl($url) { return $url; } }; }
            };
        }
        public function getRequest() { return new class { public function getParam($name) { return $GLOBALS['params'][$name] ?? null; } }; }
    }
}
namespace Magento\Framework\App { class ResourceConnection {} }
namespace Magento\Checkout\Model {
    class Session {
        public function replaceQuote($quote) { $GLOBALS['effects'][]='replace'; }
        public function setQuoteId($id) { $GLOBALS['effects'][]='quote_id'; }
    }
}
namespace Magento\Customer\Api { interface CustomerRepositoryInterface { public function getById($id); } }
namespace Magento\Customer\Model {
    class Session {
        public function setCustomerDataAsLoggedIn($customer) { $GLOBALS['effects'][]='login'; }
        public function setCustomerData($customer) {}
        public function setCustomerId($id) {}
        public function isLoggedIn() { return false; }
        public function getSessionId() { return ''; }
    }
}
namespace Magento\Quote\Api { interface CartRepositoryInterface {} }
namespace Magento\Quote\Model {
    class QuoteFactory {}
    class Quote {
        public string $email='fixture@example.invalid';
        public bool $active=false;
        public int $items=1;
        public function getId(){return 99;}
        public function getStoreId(){return 1;}
        public function getCustomerId(){return 42;}
        public function getCustomerEmail(){return $this->email;}
        public function getItemsCount(){return $this->items;}
        public function setIsActive($value){$GLOBALS['effects'][]='activate';$this->active=$value;return $this;}
    }
}
namespace NeuroCheckout\Connector\Model\Security {
    class CartFingerprintService { public function fromQuote($quote) { return 'fixture-fingerprint'; } }
    class RecoveryLinkService {
        public bool $claim=true;
        public bool $valid=true;
        public array $payload=[];
        public function buildCartUrl($storeId) { return 'https://fixture.example.invalid/cart/'; }
        public function resolveOpaque($token) { return $this->valid ? $this->payload : null; }
        public function consumeOpaque($token) { $GLOBALS['effects'][]='claim';return $this->claim; }
        public function isValid(...$args) { return $this->valid; }
    }
}
namespace Psr\Log { interface LoggerInterface { public function info($message); public function warning($message); } }
namespace {
    if (PHP_SAPI !== 'cli') { exit(1); }
    require __DIR__ . '/../app/code/NeuroCheckout/Connector/Controller/Recover/Index.php';
    $checks=0;
    function check(bool $ok, string $label): void { global $checks; ++$checks; if (!$ok) throw new \RuntimeException($label); }
    $quote=new \Magento\Quote\Model\Quote();
    $recovery=new \NeuroCheckout\Connector\Model\Security\RecoveryLinkService();
    $customers=new class implements \Magento\Customer\Api\CustomerRepositoryInterface {
        public function getById($id) { return new class { public function getEmail() { return 'fixture@example.invalid'; } }; }
    };
    $quotes=new class($quote) implements \Magento\Quote\Api\CartRepositoryInterface {
        private $quote;
        public function __construct($quote){$this->quote=$quote;}
        public function get($id){return $this->quote;}
        public function save($quote){$GLOBALS['effects'][]='save';}
    };
    $controller=new \NeuroCheckout\Connector\Controller\Recover\Index(
        new \Magento\Framework\App\Action\Context(), $quotes, new \Magento\Quote\Model\QuoteFactory(),
        $customers, new \Magento\Checkout\Model\Session(), new \Magento\Customer\Model\Session(),
        new \NeuroCheckout\Connector\Model\Security\CartFingerprintService(), $recovery,
        new \Magento\Framework\App\ResourceConnection(), new class implements \Psr\Log\LoggerInterface {
            public function info($message) {} public function warning($message) {}
        }
    );
    $params=['rt'=>'fixture-token','store_id'=>1];
    foreach (['customer_session', 'cart'] as $mode) {
        $recovery->payload=['mode'=>$mode,'cart_id'=>99,'store_id'=>1,'customer_id'=>42,'customer_email'=>'fixture@example.invalid'];
        $effects=[]; $recovery->claim=false; $quote->active=false;
        check($controller->execute() === $recovery->buildCartUrl(1), 'failed claim redirect');
        check($effects === ['claim'] && !$quote->active, 'lost claim cannot authenticate, activate/save quote or replace session');
        $effects=[]; $recovery->claim=true;
        check($controller->execute() === $recovery->buildCartUrl(1), 'valid redirect');
        check($effects === ($mode==='cart' ? ['claim','activate','save','replace','quote_id','login'] : ['claim','login']), 'claim precedes all effects');
        $effects=[]; $recovery->valid=false;
        $controller->execute(); check($effects === [], 'expired/invalid token no effects');
        $recovery->valid=true;
    }
    foreach (['email'=>'other@example.invalid','items'=>0] as $field=>$value) {
        $original=$quote->$field; $quote->$field=$value; $effects=[];
        $controller->execute(); check($effects===[], 'invalid quote not claimed or changed'); $quote->$field=$original;
    }
    $recovery->payload['cart_fingerprint']='changed'; $effects=[];
    $controller->execute(); check($effects===[], 'changed cart not claimed');
    $params=['store_id'=>1,'cart_id'=>99,'email'=>'fixture@example.invalid','ts'=>time(),'sig'=>'signed-fixture']; $effects=[];
    $controller->execute(); check($effects===['activate','save','replace','quote_id','login'], 'valid signed legacy flow preserved');
    $recovery->valid=false; $effects=[];
    $controller->execute(); check($effects===[], 'invalid legacy signature rejected');
    echo "$checks Magento recovery safety assertions passed.\n";
}
