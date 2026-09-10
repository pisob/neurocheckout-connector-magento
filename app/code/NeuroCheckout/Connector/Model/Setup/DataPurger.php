<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Setup;

use Magento\Framework\App\ResourceConnection;

class DataPurger
{
    private const TABLES = [
        'neurocheckout_event',
        'neurocheckout_order_event',
        'neurocheckout_telemetry_event',
        'neurocheckout_customer_journey_event',
        'neurocheckout_cron_log',
        'neurocheckout_nonce',
        'neurocheckout_circuit_breaker',
        'neurocheckout_coupon',
        'neurocheckout_recovery_token',
        'neurocheckout_payload_alias',
        'neurocheckout_security_rate_limit',
    ];

    private ResourceConnection $resource;

    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    /**
     * Remove every database artifact created by the connector.
     *
     * @return array<string, int>
     */
    public function purge(): array
    {
        $connection = $this->resource->getConnection();
        $result = [
            'tables_dropped' => 0,
            'config_rows_deleted' => 0,
            'setup_module_rows_deleted' => 0,
            'patch_rows_deleted' => 0,
            'cron_rows_deleted' => 0,
        ];

        foreach (self::TABLES as $tableName) {
            $table = $this->resource->getTableName($tableName);
            if (!$connection->isTableExists($table)) {
                continue;
            }
            $connection->dropTable($table);
            $result['tables_dropped']++;
        }

        $result['config_rows_deleted'] = (int) $connection->delete(
            $this->resource->getTableName('core_config_data'),
            implode(' OR ', [
                $connection->quoteInto('path LIKE ?', 'neurocheckoutconnector/%'),
                $connection->quoteInto('path LIKE ?', 'neurocheckout/%'),
            ])
        );

        $result['setup_module_rows_deleted'] = (int) $connection->delete(
            $this->resource->getTableName('setup_module'),
            $connection->quoteInto('module = ?', 'NeuroCheckout_Connector')
        );

        $patchListTable = $this->resource->getTableName('patch_list');
        if ($connection->isTableExists($patchListTable)) {
            $result['patch_rows_deleted'] = (int) $connection->delete(
                $patchListTable,
                $connection->quoteInto('patch_name LIKE ?', 'NeuroCheckout\\\\Connector\\\\%')
            );
        }

        $cronScheduleTable = $this->resource->getTableName('cron_schedule');
        if ($connection->isTableExists($cronScheduleTable)) {
            $result['cron_rows_deleted'] = (int) $connection->delete(
                $cronScheduleTable,
                $connection->quoteInto('job_code = ?', 'neurocheckout_connector_process_queue')
            );
        }

        return $result;
    }
}
