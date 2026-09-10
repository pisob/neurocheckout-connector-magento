<?php
declare(strict_types=1);

namespace NeuroCheckout\Connector\Community;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection\ConfigInterface;
use PDO;
use RuntimeException;

/** Does not read configuration or open a DB connection until authenticated use. */
final class MagentoSourceSnapshotFactory
{
    private DeploymentConfig $deployment;
    private ConfigInterface $resources;

    public function __construct(DeploymentConfig $deployment, ConfigInterface $resources)
    {
        $this->deployment = $deployment; $this->resources = $resources;
    }

    public function create(): MagentoSourceSnapshot
    {
        $name = $this->resources->getConnectionName('default');
        if (!is_string($name) || !preg_match('/^[A-Za-z0-9_-]+$/D', $name)
            || $this->resources->getConnectionName('checkout') !== $name
            || $this->resources->getConnectionName('sales') !== $name) {
            throw new RuntimeException('source_schema_unavailable');
        }
        $config = $this->deployment->get('db/connection/' . $name);
        $prefix = $this->deployment->get('db/table_prefix', '');
        if (!is_array($config) || !is_string($prefix) || !preg_match('/^[A-Za-z0-9_]*$/D', $prefix)) {
            throw new RuntimeException('source_schema_unavailable');
        }
        [$dsn, $username, $password] = self::connectionParameters($config);
        $connection = new PDO($dsn, $username, $password, [PDO::ATTR_TIMEOUT => 2,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => false, PDO::ATTR_PERSISTENT => false]);
        return new MagentoSourceSnapshot($connection, $prefix);
    }

    /** Pure validation, no connection. Never log the returned credentials. */
    public static function connectionParameters(array $config): array
    {
        foreach (['host', 'dbname', 'username', 'password'] as $key) {
            if (!isset($config[$key]) || !is_string($config[$key]) || strpos($config[$key], "\0") !== false) {
                throw new RuntimeException('source_schema_unavailable');
            }
        }
        if (!preg_match('/^[A-Za-z0-9_$-]+$/D', $config['dbname']) || $config['username'] === ''
            || !empty($config['driver_options']) || !empty($config['ssl']) || !empty($config['unix_socket'])
            || (isset($config['active']) && (string) $config['active'] !== '1')
            || (isset($config['model']) && $config['model'] !== 'mysql4')
            || (!empty($config['initStatements']) && !preg_match('/^SET NAMES utf8(?:mb4)?;?$/iD', $config['initStatements']))) {
            // In particular, never silently discard TLS/custom driver options.
            throw new RuntimeException('source_schema_unavailable');
        }
        $host = $config['host'];
        if (preg_match('#^/[A-Za-z0-9_./-]+$#D', $host)) {
            if (isset($config['port'])) { throw new RuntimeException('source_schema_unavailable'); }
            $target = 'unix_socket=' . $host;
        } elseif (preg_match('/^([A-Za-z0-9.-]+)(?::([0-9]{1,5}))?$/D', $host, $parts)) {
            if (isset($parts[2]) && isset($config['port'])) { throw new RuntimeException('source_schema_unavailable'); }
            $port = $parts[2] ?? ($config['port'] ?? '3306');
            if (!preg_match('/^[0-9]{1,5}$/D', (string) $port) || (int) $port < 1 || (int) $port > 65535) {
                throw new RuntimeException('source_schema_unavailable');
            }
            $target = 'host=' . $parts[1] . ';port=' . $port;
        } else { throw new RuntimeException('source_schema_unavailable'); }
        return ['mysql:' . $target . ';dbname=' . $config['dbname'] . ';charset=utf8mb4', $config['username'], $config['password']];
    }
}
