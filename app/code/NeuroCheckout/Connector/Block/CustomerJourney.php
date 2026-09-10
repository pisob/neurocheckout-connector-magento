<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Block;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\StoreManagerInterface;
use NeuroCheckout\Connector\Model\Config;

class CustomerJourney extends Template
{
    private Config $config;
    private RequestInterface $request;
    private StoreManagerInterface $storeManager;
    private CheckoutSession $checkoutSession;
    private Registry $registry;

    public function __construct(
        Context $context,
        Config $config,
        RequestInterface $request,
        StoreManagerInterface $storeManager,
        CheckoutSession $checkoutSession,
        Registry $registry,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->config = $config;
        $this->request = $request;
        $this->storeManager = $storeManager;
        $this->checkoutSession = $checkoutSession;
        $this->registry = $registry;
    }

    public function shouldRender(): bool
    {
        $storeId = $this->getStoreId();
        if ($storeId <= 0 || !$this->config->isCustomerJourneyEnabled($storeId)) {
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

        foreach (['/customer/account', '/neurocheckout/journey', '/neurocheckout/telemetry'] as $blockedPath) {
            if (str_contains($requestUri, $blockedPath) || str_contains($fullActionName, trim($blockedPath, '/'))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCustomerJourneyConfig(): array
    {
        $storeId = $this->getStoreId();

        return [
            'endpoint' => $this->getUrl('neurocheckout/journey/index', ['_secure' => true]),
            'token' => $this->getPublicToken($storeId),
            'eventPrefix' => 'magento.customer_journey.',
            'storeId' => $storeId,
            'cartId' => $this->getCurrentCartId(),
            'moduleVersion' => $this->getModuleVersion(),
            'slowPageMs' => 5000,
            'maxEventsPerPage' => 18,
            'context' => $this->buildPageContext(),
        ];
    }

    public function getCustomerJourneyJsUrl(): string
    {
        return $this->getViewFileUrl('NeuroCheckout_Connector::js/customer-journey-tracker.js');
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

        return hash_hmac('sha256', 'journey|' . $storeId, $secret);
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

    /**
     * @return array<string, mixed>
     */
    private function buildPageContext(): array
    {
        $pageType = $this->resolvePageType();

        return [
            'page_type' => $pageType,
            'page_path' => $this->safeText((string) $this->request->getPathInfo(), 200),
            'full_action_name' => $this->safeText((string) $this->request->getFullActionName(), 120),
            'product' => $this->resolveCurrentProduct(),
            'category' => $this->resolveCurrentCategory(),
            'is_checkout_like_page' => in_array($pageType, ['cart', 'checkout'], true),
        ];
    }

    private function resolvePageType(): string
    {
        $requestUri = strtolower((string) $this->request->getRequestUri());
        $fullActionName = strtolower((string) $this->request->getFullActionName());

        if (str_contains($fullActionName, 'catalog_product') || $this->registry->registry('current_product') instanceof Product) {
            return 'product';
        }
        if (str_contains($fullActionName, 'catalog_category') || $this->registry->registry('current_category') instanceof Category) {
            return 'category';
        }
        if (str_contains($requestUri, 'checkout') || str_contains($fullActionName, 'checkout')) {
            return 'checkout';
        }
        if (str_contains($requestUri, 'cart') || str_contains($fullActionName, 'cart')) {
            return 'cart';
        }
        if ($requestUri === '/' || $requestUri === '') {
            return 'home';
        }

        return 'page';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveCurrentProduct(): ?array
    {
        $product = $this->registry->registry('current_product');
        if (!$product instanceof Product || (int) $product->getId() <= 0) {
            return null;
        }

        return [
            'id' => (string) (int) $product->getId(),
            'sku' => $this->safeText((string) $product->getSku(), 120),
            'name' => $this->safeText((string) $product->getName(), 180),
            'price' => round((float) $product->getFinalPrice(), 2),
            'url' => $this->safeText((string) $product->getProductUrl(), 300),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveCurrentCategory(): ?array
    {
        $category = $this->registry->registry('current_category');
        if (!$category instanceof Category || (int) $category->getId() <= 0) {
            return null;
        }

        return [
            'id' => (string) (int) $category->getId(),
            'name' => $this->safeText((string) $category->getName(), 180),
            'path' => $this->safeText((string) $category->getPath(), 200),
        ];
    }

    private function safeText(string $text, int $limit): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $text = preg_replace('/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/', '[email]', $text) ?: $text;
        $text = preg_replace('/https?:\/\/[^\s"\'<>]+/i', '[url]', $text) ?: $text;

        return substr($text, 0, $limit);
    }
}
