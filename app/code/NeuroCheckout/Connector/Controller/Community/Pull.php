<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Controller\Community;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Store\Model\StoreManagerInterface;
use NeuroCheckout\Connector\Community\SourcePullGateway;
use NeuroCheckout\Connector\Community\ReconciledSourceExporter;
use NeuroCheckout\Connector\Community\MagentoSourceSnapshotFactory;
use NeuroCheckout\Connector\Community\AutomaticSourceBinding;
use NeuroCheckout\Connector\Community\CustomerProductPrices;
use NeuroCheckout\Connector\Model\Config;

/** Raw JSON preserves the exact bytes covered by the response HMAC. */
class Pull implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private RequestInterface $request;
    private RawFactory $rawFactory;
    private StoreManagerInterface $storeManager;
    private MagentoSourceSnapshotFactory $sourceSnapshots;
    private Config $config;
    private CustomerProductPrices $customerPrices;

    public function __construct(RequestInterface $request, RawFactory $rawFactory, StoreManagerInterface $storeManager,
        MagentoSourceSnapshotFactory $sourceSnapshots, Config $config, CustomerProductPrices $customerPrices)
    {
        $this->request = $request;
        $this->rawFactory = $rawFactory;
        $this->storeManager = $storeManager;
        $this->sourceSnapshots = $sourceSnapshots;
        $this->config = $config;
        $this->customerPrices = $customerPrices;
    }

    public function execute(): Raw
    {
        // Never accept a store ID supplied in the JSON body.
        try { $scope = (int) $this->storeManager->getStore()->getId(); }
        catch (\Throwable $error) { $scope = 0; }
        $automatic = null;
        try {
            $environment = AutomaticSourceBinding::environment($this->config->getString(Config::XML_PATH_API_ENDPOINT, $scope));
            if ($scope > 0
                && $environment !== null
                && $this->config->isApiTestValidationCurrent($scope)) {
                $shopId = $this->config->getString(Config::XML_PATH_SHOP_EXTERNAL_ID, $scope);
                $automatic = ['enabled' => true, 'environment' => $environment, 'nativeScope' => $scope,
                    'platform' => 'magento', 'shopId' => $shopId,
                    'secret' => AutomaticSourceBinding::secret($this->config->getNormalizedApiKey($scope), $shopId)];
            }
        } catch (\Throwable $error) {
            return $this->rawFactory->create()->setHttpResponseCode(503)
                ->setHeader('Content-Type', 'application/json', true)
                ->setHeader('Cache-Control', 'no-store', true)
                ->setContents('{"error":"source_unavailable"}');
        }
        $raw = SourcePullGateway::requestBody();
        [$status, $headers, $body] = SourcePullGateway::handle(
            'magento', $scope, BP, (string) $this->request->getMethod(),
            (string) ($_SERVER['REQUEST_URI'] ?? ''), SourcePullGateway::serverHeaders($_SERVER),
            $raw, (bool) $this->request->isSecure(),
            function (array $input, int $scope, array $configuration, string $directory) use ($raw): array {
                if (($input['operation'] ?? null) === 'customer_history') {
                    if ($input['storeId'] !== (string) $scope) { throw new \RuntimeException('source_invalid'); }
                    $history = $this->sourceSnapshots->customerHistory()->read($scope, (int) $input['websiteId'],
                        (int) $input['customerId'], $input['customerEmail']);
                    if ((int) $this->storeManager->getStore()->getId() !== $scope) { throw new \RuntimeException('source_invalid'); }
                    return ['schema'=>1,'shopId'=>$configuration['shopId'],'operation'=>'customer_history',
                        'requestHash'=>hash('sha256',$raw),'history'=>$history];
                }
                if (($input['operation'] ?? null) === 'customer_prices') {
                    // Scope is selected by Magento's request context, never by JSON.
                    if ($input['storeId'] !== (string) $scope) { throw new \RuntimeException('source_invalid'); }
                    return ['schema' => 1, 'shopId' => $configuration['shopId'], 'operation' => 'customer_prices',
                        'requestHash' => hash('sha256', $raw),
                        'prices' => $this->customerPrices->read($scope, (int) $input['customerId'],
                            $input['customerEmail'], $input['productIds'], $input['currency'])];
                }
                return (new ReconciledSourceExporter($directory, $configuration, function () use ($scope): array {
                    return $this->sourceSnapshots->create()->capture($scope);
                }))->page($input);
            }, $automatic, BP . '/var/neurocheckout-community-source/' . $scope
        );
        $result = $this->rawFactory->create();
        $result->setHttpResponseCode($status);
        foreach ($headers as $name => $value) { $result->setHeader($name, $value, true); }
        return $result->setContents($body);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        // Server-to-server only: SourcePullGateway requires a dedicated HMAC,
        // rejects browser cookies/origin and consumes a persistent nonce.
        return true;
    }
}
