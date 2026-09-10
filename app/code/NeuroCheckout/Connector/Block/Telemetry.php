<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Block;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\StoreManagerInterface;
use NeuroCheckout\Connector\Model\Config;

class Telemetry extends Template
{
    private Config $config;
    private RequestInterface $request;
    private StoreManagerInterface $storeManager;
    private CheckoutSession $checkoutSession;

    public function __construct(
        Context $context,
        Config $config,
        RequestInterface $request,
        StoreManagerInterface $storeManager,
        CheckoutSession $checkoutSession,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->config = $config;
        $this->request = $request;
        $this->storeManager = $storeManager;
        $this->checkoutSession = $checkoutSession;
    }

    public function shouldRender(): bool
    {
        $storeId = $this->getStoreId();
        if ($storeId <= 0 || !$this->config->isTelemetryEnabled($storeId)) {
            return false;
        }

        if (!$this->config->isApiConfigurationReady($storeId)) {
            return false;
        }

        $method = strtoupper((string) $this->request->getMethod());
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return false;
        }

        $requestUri = strtolower((string) $this->request->getRequestUri());
        $fullActionName = strtolower((string) $this->request->getFullActionName());
        $controller = strtolower((string) $this->request->getControllerName());

        return str_contains($requestUri, 'checkout')
            || str_contains($requestUri, 'cart')
            || str_contains($requestUri, 'onestepcheckout')
            || str_contains($fullActionName, 'checkout')
            || str_contains($fullActionName, 'cart')
            || in_array($controller, ['checkout', 'cart'], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function getTelemetryConfig(): array
    {
        $storeId = $this->getStoreId();

        return [
            'endpoint' => $this->getUrl('neurocheckout/telemetry/index', ['_secure' => true]),
            'token' => $this->getPublicToken($storeId),
            'storeId' => $storeId,
            'cartId' => $this->getCurrentCartId(),
            'moduleVersion' => $this->getModuleVersion(),
            'slowRequestMs' => 5000,
            'slowCheckoutMs' => 5000,
            'maxEventsPerPage' => 12,
            'maxIssueEventsPerPage' => 8,
        ];
    }

    public function getCheckoutTelemetryJsUrl(): string
    {
        return $this->getViewFileUrl('NeuroCheckout_Connector::js/checkout-telemetry.js');
    }

    private function getStoreId(): int
    {
        try {
            return (int) $this->storeManager->getStore()->getId();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function getPublicToken(int $storeId): string
    {
        if ($storeId <= 0) {
            return '';
        }

        $secret = $this->config->getOrCreateInternalSecret($storeId);
        if ($secret === '') {
            return '';
        }

        return hash_hmac('sha256', 'telemetry|' . $storeId, $secret);
    }

    private function getCurrentCartId(): ?string
    {
        try {
            $quote = $this->checkoutSession->getQuote();
            $quoteId = $quote ? (int) $quote->getId() : 0;
            return $quoteId > 0 ? (string) $quoteId : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function getModuleVersion(): string
    {
        $module = $this->_scopeConfig->getValue('advanced/modules_disable_output/NeuroCheckout_Connector');
        return $module === '1' ? 'disabled_output' : '1.0.0';
    }
}
