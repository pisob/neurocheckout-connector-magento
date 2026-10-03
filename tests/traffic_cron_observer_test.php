<?php
declare(strict_types=1);
namespace Magento\Framework\Event {
    class Observer {}
    interface ObserverInterface { public function execute(Observer $observer); }
}
namespace Magento\Store\Model { interface StoreManagerInterface { public function getStore(); } }
namespace Psr\Log { interface LoggerInterface { public function warning($message); } }
namespace NeuroCheckout\Connector\Model\Application {
    class TrafficCronRunner {
        public array $calls = [];
        public function run($id) { $this->calls[] = $id; }
    }
}
namespace NeuroCheckout\Connector\Observer {
    const PHP_SAPI = 'fpm-fcgi';
    function function_exists($name) { return $GLOBALS['nc_fpm']; }
    function register_shutdown_function($callback) { $GLOBALS['nc_shutdown'][] = $callback; }
    function fastcgi_finish_request() { $GLOBALS['nc_finished'] = true; return true; }
    function error_get_last() { return null; }
}
namespace {
    require dirname(__DIR__) . '/app/code/NeuroCheckout/Connector/Observer/TrafficCronObserver.php';
    $GLOBALS['nc_fpm'] = true; $GLOBALS['nc_shutdown'] = []; $GLOBALS['nc_finished'] = false;
    $stores = new class implements \Magento\Store\Model\StoreManagerInterface {
        public function getStore() { return new class { public function getId() { return 7; } }; }
    };
    $runner = new \NeuroCheckout\Connector\Model\Application\TrafficCronRunner();
    $logger = new class implements \Psr\Log\LoggerInterface { public function warning($message) {} };
    $observer = new \NeuroCheckout\Connector\Observer\TrafficCronObserver($stores,$runner,$logger);
    $event = new \Magento\Framework\Event\Observer();
    $observer->execute($event); $observer->execute($event);
    if (count($GLOBALS['nc_shutdown']) !== 1 || $runner->calls !== []) throw new \RuntimeException('Not deferred or duplicated');
    $GLOBALS['nc_shutdown'][0]();
    if (!$GLOBALS['nc_finished'] || $runner->calls !== [7]) throw new \RuntimeException('Wrong store or unfinished response');
    $GLOBALS['nc_fpm'] = false;
    (new \NeuroCheckout\Connector\Observer\TrafficCronObserver($stores,$runner,$logger))->execute($event);
    if (count($GLOBALS['nc_shutdown']) !== 1) throw new \RuntimeException('Unsafe non-FPM fallback');
    $xml = file_get_contents(dirname(__DIR__) . '/app/code/NeuroCheckout/Connector/etc/events.xml');
    if (!str_contains($xml, 'controller_front_send_response_before') || !str_contains($xml, 'NeuroCheckout\\Connector\\Observer\\TrafficCronObserver')) throw new \RuntimeException('Observer not wired');
    echo "4 deferred observer scenarios passed (framework doubles).\n";
}
