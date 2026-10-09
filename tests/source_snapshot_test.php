<?php
declare(strict_types=1);
namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    // Optional installed SDK. No app/bootstrap.php or shop configuration is read.
    $nativeSdk = null;
    foreach (array_slice($argv, 2) as $argument) {
        if (str_starts_with($argument, '--sdk=')) $nativeSdk = substr($argument, 6);
        elseif ($argument !== '--pages-json') exit(2);
    }
    if ($nativeSdk !== null) {
        if (!is_file($nativeSdk . '/vendor/autoload.php')) exit(2);
        $loader = require $nativeSdk . '/vendor/autoload.php';
        $loader->addPsr4('NeuroCheckout\\Connector\\', dirname(__DIR__) . '/app/code/NeuroCheckout/Connector/', true);
    }
}
namespace Magento\Framework\App {
    if (!class_exists(DeploymentConfig::class)) {
    class DeploymentConfig {
        private array $values;
        public function __construct(array $values) { $this->values = $values; }
        public function get($key, $default = null) { return $this->values[$key] ?? $default; }
    }
    }
}
namespace Magento\Framework\App\ResourceConnection {
    if (!interface_exists(ConfigInterface::class)) {
    interface ConfigInterface { public function getConnectionName($name); }
    }
}
namespace {
    use NeuroCheckout\Connector\Community\MagentoSourceSnapshot;
    use NeuroCheckout\Connector\Community\MagentoSourceSnapshotFactory;
    use NeuroCheckout\Connector\Community\ReconciledSourceExporter;
    use NeuroCheckout\Connector\Community\SourcePullGateway;
    use NeuroCheckout\Connector\Community\SourcePullProtocol;
    $base = dirname(__DIR__) . '/app/code/NeuroCheckout/Connector/Community/';
    foreach (['MagentoSourceSnapshot', 'MagentoSourceSnapshotFactory', 'ReconciledSourceExporter', 'SourcePullGateway', 'SourcePullProtocol'] as $class) { require_once $base . $class . '.php'; }
    $socket = $argv[1] ?? '';
    if (!preg_match('#^/tmp/nc-source-mariadb\.[A-Za-z0-9]+/mariadb\.sock$#D', $socket) || !file_exists($socket)) { exit(2); }
    $checks = 0;
    function checkMagento(bool $condition, string $message): void {
        global $checks; $checks++;
        if (!$condition) { throw new \RuntimeException($message); }
    }
    function rejectMagento(callable $call, string $expected): void {
        try { $call(); } catch (\Throwable $error) { checkMagento($error->getMessage() === $expected, 'Precise refusal: ' . $expected); return; }
        throw new \RuntimeException('Unexpected acceptance');
    }
    class MagentoTestPDO extends \PDO {
        public $beforeQuoteRead = null;
        #[\ReturnTypeWillChange]
        public function prepare($query, $options = []) {
            if ($this->beforeQuoteRead !== null && strpos($query, 'FROM `mg_quote` WHERE') !== false) {
                $callback = $this->beforeQuoteRead; $this->beforeQuoteRead = null; $callback($this);
            }
            return parent::prepare($query, $options);
        }
    }
    $dsn = 'mysql:unix_socket=' . $socket . ';charset=utf8mb4';
    $database = 'nc_magento_fixture_' . bin2hex(random_bytes(6));
    $admin = new \PDO($dsn, 'root', '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $admin->exec('CREATE DATABASE `' . $database . '`');
    $directory = sys_get_temp_dir() . '/nc-magento-export-' . bin2hex(random_bytes(8)); mkdir($directory, 0700);
    $oldConfiguration = getenv('NC_COMMUNITY_SOURCE_CONFIG');
    try {
        $writer = new \PDO($dsn . ';dbname=' . $database, 'root', '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $schemas = [
            'store_website' => 'website_id INT PRIMARY KEY',
            'store' => 'store_id INT PRIMARY KEY, website_id INT, is_active INT',
            'catalog_product_entity' => 'entity_id INT PRIMARY KEY, sku VARCHAR(64), type_id VARCHAR(32), attribute_set_id INT, created_at DATETIME, updated_at DATETIME',
            'catalog_product_website' => 'product_id INT, website_id INT, PRIMARY KEY(product_id,website_id)',
            'eav_entity_type' => 'entity_type_id INT PRIMARY KEY, entity_type_code VARCHAR(64)',
            'eav_attribute' => 'attribute_id INT PRIMARY KEY, entity_type_id INT, attribute_code VARCHAR(64), backend_type VARCHAR(16), backend_table VARCHAR(64)',
            'catalog_eav_attribute' => 'attribute_id INT PRIMARY KEY, is_global INT',
            'catalog_product_relation' => 'parent_id INT, child_id INT',
            'catalog_category_product' => 'product_id INT, category_id INT',
            'quote' => 'entity_id INT PRIMARY KEY, store_id INT, is_active INT, customer_id INT NULL, customer_is_guest INT, customer_email VARCHAR(128), customer_firstname VARCHAR(64), customer_lastname VARCHAR(64), created_at DATETIME, updated_at DATETIME, base_currency_code CHAR(3), quote_currency_code CHAR(3), grand_total DECIMAL(20,4), subtotal DECIMAL(20,4), subtotal_with_discount DECIMAL(20,4)',
            'quote_item' => 'item_id INT PRIMARY KEY, quote_id INT, parent_item_id INT NULL, store_id INT, product_id INT, sku VARCHAR(64), name VARCHAR(128), product_type VARCHAR(32), qty DECIMAL(12,4), price DECIMAL(20,4), row_total DECIMAL(20,4), discount_amount DECIMAL(20,4), tax_amount DECIMAL(20,4)',
            'sales_order' => 'entity_id INT PRIMARY KEY, quote_id INT, store_id INT, increment_id VARCHAR(64), state VARCHAR(32), status VARCHAR(32), grand_total DECIMAL(20,4), order_currency_code CHAR(3), created_at DATETIME',
        ];
        foreach (['varchar' => 'VARCHAR(255)', 'int' => 'INT', 'decimal' => 'DECIMAL(20,6)', 'text' => 'TEXT', 'datetime' => 'DATETIME'] as $type => $column) {
            $schemas['catalog_product_entity_' . $type] = 'value_id INT AUTO_INCREMENT PRIMARY KEY, entity_id INT, attribute_id INT, store_id INT, value ' . $column . ', UNIQUE(entity_id,attribute_id,store_id)';
        }
        foreach ($schemas as $table => $schema) { $writer->exec('CREATE TABLE `mg_' . $table . '` (' . $schema . ') ENGINE=InnoDB'); }
        foreach ([
            "INSERT INTO mg_store_website VALUES (1),(2)",
            "INSERT INTO mg_store VALUES (1,1,1),(2,1,1),(3,2,1),(4,1,0)",
            "INSERT INTO mg_catalog_product_entity VALUES (10,'SHARED','simple',4,NOW(),NOW()),(20,'FOREIGN','simple',4,NOW(),NOW())",
            "INSERT INTO mg_catalog_product_website VALUES (10,1),(10,2),(20,2)",
            "INSERT INTO mg_eav_entity_type VALUES (4,'catalog_product'),(1,'customer')",
            "INSERT INTO mg_eav_attribute VALUES (1,4,'name','varchar',NULL),(2,4,'status','int',NULL),(3,4,'visibility','int',NULL),(4,4,'price','decimal',NULL),(5,4,'short_description','text',NULL),(6,1,'name','varchar',NULL)",
            "INSERT INTO mg_catalog_eav_attribute VALUES (1,0),(2,1),(3,0),(4,2),(5,0),(6,0)",
            "INSERT INTO mg_catalog_product_entity_varchar(entity_id,attribute_id,store_id,value) VALUES (10,1,0,'Default product'),(10,1,1,'Nom été'),(10,1,2,'Other view'),(10,1,3,'Foreign website'),(20,1,0,'Foreign product')",
            "INSERT INTO mg_catalog_product_entity_int(entity_id,attribute_id,store_id,value) VALUES (10,2,0,1),(10,2,1,0),(10,3,0,4),(20,2,0,1),(20,3,0,4)",
            "INSERT INTO mg_catalog_product_entity_decimal(entity_id,attribute_id,store_id,value) VALUES (10,4,0,39.9),(10,4,1,45),(10,4,2,999),(20,4,0,100)",
            "INSERT INTO mg_catalog_product_entity_text(entity_id,attribute_id,store_id,value) VALUES (10,5,0,'Description')",
            "INSERT INTO mg_catalog_product_relation VALUES (10,20)",
            "INSERT INTO mg_catalog_category_product VALUES (10,5)",
            "INSERT INTO mg_quote VALUES (1,1,1,NULL,1,'fixture@example.invalid','Test','Guest',NOW(),NOW(),'USD','USD',45,45,45),(2,2,0,2,0,'other@example.invalid','Other','View',NOW(),NOW(),'USD','USD',999,999,999),(3,3,1,3,0,'foreign@example.invalid','Other','Website',NOW(),NOW(),'USD','USD',100,100,100)",
            "INSERT INTO mg_quote_item VALUES (1,1,NULL,1,10,'SHARED','Nom été','simple',1,45,45,0,0),(2,2,NULL,2,10,'SHARED','Other view','simple',1,999,999,0,0)",
        ] as $sql) { $writer->exec($sql); }
        $readerPDO = new MagentoTestPDO($dsn . ';dbname=' . $database, 'root', '');
        $reader = new MagentoSourceSnapshot($readerPDO, 'mg_');
        $snapshot = $reader->capture(1);
        checkMagento(count($snapshot) === 2, 'Selected website product and exact store quote');
        $product = $snapshot[0]['payload']; $cart = $snapshot[1]['payload'];
        checkMagento($product['attributes']['name']['effective'] === 'Nom été', 'Selected view localized name');
        checkMagento((float) $product['attributes']['price']['effective'] === 45.0, 'Selected view website price');
        checkMagento((int) $product['attributes']['status']['effective'] === 1, 'Global status ignores invalid per-view row');
        checkMagento(!$product['children'] && $product['inventory_status'] === 'not_exported', 'Foreign child excluded; no invented MSI quantity');
        checkMagento($cart['status'] === 'active' && (int) $cart['customer_is_guest'] === 1, 'Guest quote retained');
        $writer->exec('UPDATE mg_quote SET customer_id=8, customer_is_guest=0 WHERE entity_id=1');
        $registered = $reader->capture(1)[1]['payload'];
        checkMagento((int) $registered['customer_id'] === 8 && (int) $registered['customer_is_guest'] === 0, 'Registered identity retained');
        $writer->exec('UPDATE mg_quote SET customer_is_guest=1 WHERE entity_id=1');
        checkMagento((int) $reader->capture(1)[1]['payload']['customer_is_guest'] === 1, 'Explicit guest flag survives positive customer ID');
        $writer->exec('UPDATE mg_quote SET customer_id=NULL WHERE entity_id=1');
        checkMagento(strpos(json_encode($snapshot), 'other@example.invalid') === false && strpos(json_encode($snapshot), 'foreign@example.invalid') === false, 'No other view or website contacts');
        checkMagento($reader->capture(2)[1]['payload']['status'] === 'inactive', 'Inactive quote without order is not converted');
        rejectMagento(static function () use ($reader) { $reader->capture(4); }, 'source_shop_unavailable');
        $writer->beginTransaction();
        $writer->exec("INSERT INTO mg_sales_order VALUES (1,1,1,'ORDER-TEST','processing','processing',45,'USD',NOW())");
        $readerPDO->beforeQuoteRead = static function (\PDO $connection) use ($writer): void {
            $blocked = false;
            try { $connection->exec('UPDATE mg_store SET is_active=0 WHERE store_id=1'); } catch (\PDOException $error) { $blocked = true; }
            checkMagento($blocked, 'Native transaction enforces read only'); $writer->commit();
        };
        checkMagento($reader->capture(1)[1]['payload']['status'] === 'active', 'Concurrent order does not mix snapshot versions');
        checkMagento($reader->capture(1)[1]['payload']['status'] === 'converted', 'Next snapshot sees concurrent order');
        $connectionConfig = ['host' => $socket, 'dbname' => $database, 'username' => 'root', 'password' => '', 'model' => 'mysql4', 'active' => '1', 'initStatements' => 'SET NAMES utf8;', 'driver_options' => [PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false]];
        foreach ([true, false] as $verify) {
            checkMagento(MagentoSourceSnapshotFactory::connectionOptions(['driver_options' => [PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => $verify]])[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] === $verify, 'Explicit TLS verification option preserved');
        }
        rejectMagento(static function () { MagentoSourceSnapshotFactory::connectionOptions(['driver_options' => [PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => 'false']]); }, 'source_schema_unavailable');
        if ($nativeSdk !== null) {
            $deployment = new \Magento\Framework\App\DeploymentConfig(
                new \Magento\Framework\App\DeploymentConfig\Reader(
                    new \Magento\Framework\App\Filesystem\DirectoryList($directory),
                    new \Magento\Framework\Filesystem\DriverPool(),
                    new \Magento\Framework\Config\File\ConfigFilePool()
                ),
                ['db' => ['connection' => ['default' => $connectionConfig], 'table_prefix' => 'mg_']]
            );
            $controller = new \ReflectionClass(\NeuroCheckout\Connector\Controller\Community\Pull::class);
            checkMagento($controller->implementsInterface(\Magento\Framework\App\CsrfAwareActionInterface::class), 'Native CSRF interface compatibility');
            checkMagento($controller->implementsInterface(\Magento\Framework\App\Action\HttpPostActionInterface::class), 'Native POST interface compatibility');
            checkMagento(!$controller->getConstructor()->getParameters()[4]->isOptional(), 'Configuration dependency required by native DI');
        } else {
            $deployment = new \Magento\Framework\App\DeploymentConfig(['db/connection/default' => $connectionConfig, 'db/table_prefix' => 'mg_']);
        }
        $resources = new class implements \Magento\Framework\App\ResourceConnection\ConfigInterface { public function getConnectionName($name) { return 'default'; } };
        $factory = new MagentoSourceSnapshotFactory($deployment, $resources);
        checkMagento(count($factory->create()->capture(1)) === 2, 'Factory uses isolated runtime connection');
        $split = new class implements \Magento\Framework\App\ResourceConnection\ConfigInterface { public function getConnectionName($name) { return $name; } };
        rejectMagento(static function () use ($deployment, $split) { (new MagentoSourceSnapshotFactory($deployment, $split))->create(); }, 'source_schema_unavailable');
        foreach ([['driver_options' => [1009 => '/private/test-ca']], ['host' => 'localhost;dbname=other'], ['dbname' => 'bad;database'], ['initStatements' => 'DELETE FROM quote'], ['port' => 3306], ['model' => 'other']] as $change) {
            rejectMagento(static function () use ($connectionConfig, $change) { MagentoSourceSnapshotFactory::connectionParameters(array_replace($connectionConfig, $change)); }, 'source_schema_unavailable');
        }
        $config = ['enabled' => true, 'environment' => 'staging', 'platform' => 'magento', 'nativeScope' => 1, 'shopId' => 'synthetic-shop', 'secret' => str_repeat('ab', 32)];
        $configFile = $directory . '/source.json'; file_put_contents($configFile, json_encode($config)); chmod($configFile, 0600);
        putenv('NC_COMMUNITY_SOURCE_CONFIG=' . $configFile);
        $input = ['schema' => 1, 'shopId' => 'synthetic-shop', 'streamId' => null, 'cursor' => '', 'limit' => 8];
        $wirePages = [];
        $call = static function (array $input) use ($config, $factory, &$wirePages): array {
            $path = '/neurocheckout/community/pull'; $body = json_encode($input); $nonce = bin2hex(random_bytes(16)); $time = (string) (int) floor(microtime(true) * 1000);
            $signature = hash_hmac('sha256', implode("\n", ['nc-source-pull-v1', 'POST', $path, 'synthetic-shop', $time, $nonce, hash('sha256', $body)]), hex2bin($config['secret']));
            [$status, $headers, $response] = SourcePullGateway::handle('magento', 1, __DIR__, 'POST', $path,
                ['content-type' => 'application/json', 'x-nc-source-time' => $time, 'x-nc-source-nonce' => $nonce, 'x-nc-source-signature' => $signature], $body, true,
                static function ($request, $scope, $configuration, $stateDirectory) use ($factory): array {
                    return (new ReconciledSourceExporter($stateDirectory, $configuration, static function () use ($factory, $scope): array {
                        return $factory->create()->capture($scope);
                    }))->page($request);
                });
            checkMagento($status === 200, 'Authenticated native export succeeds');
            checkMagento($headers['X-NC-Source-Response'] === SourcePullProtocol::responseSignature($config['secret'], $nonce, $response), 'Native raw response signed');
            $wirePages[] = $response;
            return json_decode($response, true);
        };
        $page = $call($input);
        checkMagento($page['complete'] && count($page['records']) === 2, 'Native bootstrap page');
        $input['streamId'] = $page['streamId']; $input['cursor'] = $page['nextCursor'];
        $writer->exec('DELETE FROM mg_catalog_product_entity_varchar WHERE entity_id=10 AND attribute_id=1 AND store_id=1');
        $fallback = $call($input);
        checkMagento($fallback['records'][0]['payload']['attributes']['name']['effective'] === 'Default product', 'Removed override falls back and increments revision');
        $input['cursor'] = $fallback['nextCursor'];
        $writer->exec('DELETE FROM mg_catalog_product_website WHERE product_id=10 AND website_id=1');
        $deleted = $call($input);
        checkMagento(count($deleted['records']) === 1 && $deleted['records'][0]['operation'] === 'delete', 'Website removal emits tombstone');
        $writer->exec('UPDATE mg_quote_item SET store_id=2 WHERE quote_id=1');
        rejectMagento(static function () use ($reader) { $reader->capture(1); }, 'source_scope_inconsistent');
        $writer->exec('UPDATE mg_quote_item SET store_id=1 WHERE quote_id=1');
        $writer->exec('UPDATE mg_sales_order SET store_id=2 WHERE quote_id=1');
        rejectMagento(static function () use ($reader) { $reader->capture(1); }, 'source_scope_inconsistent');
        $writer->exec('UPDATE mg_sales_order SET store_id=1 WHERE quote_id=1');
        $writer->exec('INSERT INTO mg_catalog_product_website VALUES (10,1)');
        $writer->exec("UPDATE mg_catalog_product_entity_text SET value=REPEAT('x',17000) WHERE entity_id=10");
        rejectMagento(static function () use ($reader) { $reader->capture(1); }, 'source_snapshot_capacity');
        $writer->exec("UPDATE mg_catalog_product_entity_text SET value='Description' WHERE entity_id=10");
        $writer->exec('ALTER TABLE mg_catalog_product_entity ADD row_id INT');
        rejectMagento(static function () use ($reader) { $reader->capture(1); }, 'source_schema_unavailable');
        $writer->exec('ALTER TABLE mg_catalog_product_entity DROP row_id');
        $writer->exec("UPDATE mg_eav_attribute SET backend_table='custom_private_table' WHERE attribute_id=1");
        rejectMagento(static function () use ($reader) { $reader->capture(1); }, 'source_schema_unavailable');
        $writer->exec('UPDATE mg_eav_attribute SET backend_table=NULL WHERE attribute_id=1');
        $writer->exec('ALTER TABLE mg_catalog_category_product ENGINE=MyISAM');
        rejectMagento(static function () use ($reader) { $reader->capture(1); }, 'source_snapshot_not_transactional');
        $writer->exec('ALTER TABLE mg_catalog_category_product ENGINE=InnoDB');
        for ($id = 10; $id < 270; $id++) { $writer->exec("INSERT INTO mg_quote SELECT $id,store_id,is_active,customer_id,customer_is_guest,customer_email,customer_firstname,customer_lastname,created_at,updated_at,base_currency_code,quote_currency_code,grand_total,subtotal,subtotal_with_discount FROM mg_quote WHERE entity_id=1"); }
        checkMagento(count($reader->capture(1)) === 262, 'More than 256 records exported without truncation');
        for ($id = 100; $id < 2145; $id++) {
            $writer->exec("INSERT INTO mg_catalog_product_entity VALUES ($id,'SKU-$id','simple',4,NOW(),NOW())");
            $writer->exec("INSERT INTO mg_catalog_product_website VALUES ($id,1)");
            foreach (['varchar', 'int', 'decimal', 'text'] as $type) {
                $writer->exec("INSERT INTO mg_catalog_product_entity_$type(entity_id,attribute_id,store_id,value) SELECT $id,attribute_id,store_id,value FROM mg_catalog_product_entity_$type WHERE entity_id=10");
            }
        }
        checkMagento(count($reader->capture(1)) === 2307, 'Large catalogue read in bounded batches');
        $input['cursor'] = $deleted['nextCursor'];
        $large = $call($input);
        checkMagento(count($large['records']) === 8 && !$large['complete'], 'Large snapshot reconciled and paginated, not falsely complete');
        $pageCount = 1; $sawCart = false;
        while (!$large['complete'] && $pageCount < 400) {
            foreach ($large['records'] as $record) { if ($record['kind'] === 'cart') $sawCart = true; }
            $input['cursor'] = $large['nextCursor'];
            // Exercise reconciliation directly here; gateway rate limiting is
            // tested separately and deliberately caps authenticated requests.
            $large = (new ReconciledSourceExporter($directory, $config, static function () use ($factory): array {
                return $factory->create()->capture(1);
            }))->page($input); $pageCount++;
        }
        checkMagento($large['complete'] && $sawCart, 'Large synchronization reaches carts and a fresh complete snapshot');
        $writer->exec('INSERT INTO mg_quote SELECT entity_id+10000,store_id,is_active,customer_id,customer_is_guest,customer_email,customer_firstname,customer_lastname,created_at,updated_at,base_currency_code,quote_currency_code,grand_total,subtotal,subtotal_with_discount FROM mg_quote');
        $writer->exec('INSERT INTO mg_quote SELECT entity_id+20000,store_id,is_active,customer_id,customer_is_guest,customer_email,customer_firstname,customer_lastname,created_at,updated_at,base_currency_code,quote_currency_code,grand_total,subtotal,subtotal_with_discount FROM mg_quote');
        $writer->exec('INSERT INTO mg_quote SELECT entity_id+40000,store_id,is_active,customer_id,customer_is_guest,customer_email,customer_firstname,customer_lastname,created_at,updated_at,base_currency_code,quote_currency_code,grand_total,subtotal,subtotal_with_discount FROM mg_quote');
        $writer->exec('INSERT INTO mg_quote SELECT entity_id+80000,store_id,is_active,customer_id,customer_is_guest,customer_email,customer_firstname,customer_lastname,created_at,updated_at,base_currency_code,quote_currency_code,grand_total,subtotal,subtotal_with_discount FROM mg_quote');
        $writer->exec('INSERT INTO mg_quote SELECT entity_id+160000,store_id,is_active,customer_id,customer_is_guest,customer_email,customer_firstname,customer_lastname,created_at,updated_at,base_currency_code,quote_currency_code,grand_total,subtotal,subtotal_with_discount FROM mg_quote');
        rejectMagento(static function () use ($reader) { $reader->capture(1); }, 'source_snapshot_capacity');
        checkMagento(!$readerPDO->inTransaction(), 'Transactions closed after capture');
        if (in_array('--pages-json', $argv, true)) { echo json_encode(['assertions' => $checks, 'pages' => $wirePages], JSON_THROW_ON_ERROR); }
        else { echo $checks . " Magento native SQL/factory assertions passed (synthetic schema).\n"; }
    } finally {
        putenv($oldConfiguration === false ? 'NC_COMMUNITY_SOURCE_CONFIG' : 'NC_COMMUNITY_SOURCE_CONFIG=' . $oldConfiguration);
        if (isset($writer) && $writer->inTransaction()) { $writer->rollBack(); }
        $admin->exec('DROP DATABASE `' . $database . '`');
        foreach (glob($directory . '/*') as $file) { unlink($file); } rmdir($directory);
    }
}
