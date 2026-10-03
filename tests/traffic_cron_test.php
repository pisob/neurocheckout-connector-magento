<?php
declare(strict_types=1);

namespace Magento\Framework\App {
    interface CacheInterface { public function load($key); public function save($value, $key, $tags, $ttl); }
}
namespace Magento\Framework\Lock {
    interface LockManagerInterface { public function lock(string $name, int $timeout = -1): bool; public function unlock(string $name): bool; }
}
namespace Psr\Log { interface LoggerInterface { public function warning($message); } }
namespace NeuroCheckout\Connector\Model {
    class Config {
        public const XML_PATH_CRON_INTERVAL_SECONDS = 'interval';
        public const XML_PATH_LAST_RUN = 'last';
        public string $mode = 'cron_module';
        public bool $api = true, $ia = true, $validated = true;
        public int $last = 0;
        public function getExecutionMode($id) { return $this->mode; }
        public function isApiConfigurationReady($id) { return $this->api; }
        public function isIaConfigurationReady($id) { return $this->ia; }
        public function isApiTestValidationCurrent($id) { return $this->validated; }
        public function getInt($path, $id) { return $path === 'last' ? $this->last : 60; }
    }
}
namespace NeuroCheckout\Connector\Model\Application {
    class CronExecutor {
        public array $calls = [];
        public bool $throws = false;
        public function executeStore(...$args) {
            $this->calls[] = $args;
            if ($this->throws) throw new \RuntimeException('test failure');
            return ['success' => true];
        }
    }
}
namespace {
    require dirname(__DIR__) . '/app/code/NeuroCheckout/Connector/Model/Application/TrafficCronRunner.php';
    function fixture(): array {
        $config = new \NeuroCheckout\Connector\Model\Config();
        $cache = new class implements \Magento\Framework\App\CacheInterface {
            public array $values = [];
            public bool $writable = true;
            public function load($key) { return $this->values[$key] ?? false; }
            public function save($value, $key, $tags, $ttl) {
                if (!$this->writable) return false;
                $this->values[$key] = $value; return true;
            }
        };
        $locks = new class implements \Magento\Framework\Lock\LockManagerInterface {
            public bool $available = true;
            public int $released = 0;
            public function lock(string $name, int $timeout = -1): bool {
                if ($timeout !== 0) throw new \RuntimeException('Must not block');
                return $this->available;
            }
            public function unlock(string $name): bool { ++$this->released; return true; }
        };
        $executor = new \NeuroCheckout\Connector\Model\Application\CronExecutor();
        $logger = new class implements \Psr\Log\LoggerInterface { public function warning($message) {} };
        $runner = new \NeuroCheckout\Connector\Model\Application\TrafficCronRunner($config, $cache, $locks, $executor, $logger);
        return [$runner, $config, $cache, $locks, $executor];
    }
    function check($ok): void { if (!$ok) throw new \RuntimeException('Traffic cron regression'); }
    [$r,$c,$cache,$l,$e] = fixture();
    $r->run(1); $r->run(1); $r->run(2);
    check($e->calls === [[1,false,false,1],[2,false,false,1]]);
    foreach (['api','ia','validated'] as $gate) {
        [$r,$c,$cache,$l,$e] = fixture(); $c->$gate = false; $r->run(1); check($e->calls === []);
    }
    [$r,$c,$cache,$l,$e] = fixture(); $c->mode='cron'; $r->run(1); check($e->calls === []);
    [$r,$c,$cache,$l,$e] = fixture(); $r->run(0); check($e->calls === []);
    [$r,$c,$cache,$l,$e] = fixture(); $l->available=false; $r->run(1); check($e->calls === []);
    [$r,$c,$cache,$l,$e] = fixture(); $cache->writable=false; $r->run(1); check($e->calls === [] && $l->released === 1);
    [$r,$c,$cache,$l,$e] = fixture(); $c->last=time(); $r->run(1); check($e->calls === []);
    [$r,$c,$cache,$l,$e] = fixture(); $e->throws=true;
    try { $r->run(1); } catch (\RuntimeException $error) {}
    $r->run(1); check(count($e->calls) === 1 && $l->released === 2);
    [$r,$c,$cache,$l,$e] = fixture(); $cache->values['nc_traffic_cron_1'] = time()-61; $r->run(1); check(count($e->calls) === 1);
    echo "11 traffic cron scenarios passed.\n";
}
