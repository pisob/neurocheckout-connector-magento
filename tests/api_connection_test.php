<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/code/NeuroCheckout/Connector/Model/Http/SecureHttpClient.php';

class HealthClientFixture extends \NeuroCheckout\Connector\Model\Http\SecureHttpClient
{
    public array $response = [];
    public int $seenStore = -1;
    public function __construct() {}
    public function checkConnectorVersion(int $storeId): array
    {
        $this->seenStore = $storeId;
        return $this->response;
    }
}
$client = new HealthClientFixture();
foreach ([
    ['platform' => 'magento', 'installed_version' => $client::CONNECTOR_VERSION],
    ['platform' => 'woocommerce', 'installed_version' => $client::CONNECTOR_VERSION],
    ['platform' => 'magento', 'installed_version' => 'invalid'],
    [],
] as $index => $body) {
    $client->response = ['success' => true, 'status' => 200, 'body' => json_encode($body)];
    $result = $client->health(['source' => ['store_id' => 3, 'shop_id' => 'external-shop']]);
    if ($result['success'] !== ($index === 0) || $client->seenStore !== 3) {
        throw new RuntimeException('Invalid response or store scope accepted');
    }
}
$client->response = ['success' => true, 'status' => 200, 'body' => '<html>Login</html>'];
if ($client->health()['success']) throw new RuntimeException('HTML accepted');
$client->response = ['success' => false, 'status' => 401, 'error' => 'unauthorized'];
if ($client->health() !== $client->response) throw new RuntimeException('Failure hidden');
echo "API response and store scope checks passed.\n";
