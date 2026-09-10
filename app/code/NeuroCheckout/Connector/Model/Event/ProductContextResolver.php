<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Event;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

class ProductContextResolver
{
    private ProductRepositoryInterface $productRepository;
    private CategoryRepositoryInterface $categoryRepository;
    private StockRegistryInterface $stockRegistry;
    private StoreManagerInterface $storeManager;

    /** @var array<string, object|null> */
    private array $productCache = [];

    /** @var array<string, string|null> */
    private array $categoryCache = [];

    /** @var array<string, int> */
    private array $skuToProductIdCache = [];

    public function __construct(
        ProductRepositoryInterface $productRepository,
        CategoryRepositoryInterface $categoryRepository,
        StockRegistryInterface $stockRegistry,
        StoreManagerInterface $storeManager
    ) {
        $this->productRepository = $productRepository;
        $this->categoryRepository = $categoryRepository;
        $this->stockRegistry = $stockRegistry;
        $this->storeManager = $storeManager;
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveForCartItem(
        $item,
        int $storeId,
        ?string $fallbackProductUrl = null,
        ?string $fallbackImageUrl = null
    ): array {
        $productId = (int) ($item && method_exists($item, 'getProductId') ? $item->getProductId() : 0);
        $variantProductId = $this->resolveVariantProductIdFromItem($item, $storeId);
        $parentProduct = $this->resolveProductFromItem($item, $productId, $storeId);
        $variantProduct = $variantProductId > 0 ? $this->loadProductById($variantProductId, $storeId) : null;

        $productUrl = $this->resolveProductUrl($parentProduct ?: $variantProduct, $storeId)
            ?: $this->normalizePublicUrl($fallbackProductUrl, $storeId);
        $imageUrl = $this->resolveProductImageUrl($variantProduct ?: $parentProduct, $storeId)
            ?: $this->normalizePublicUrl($fallbackImageUrl, $storeId);

        $categoryPath = $this->resolveCategoryPath($parentProduct, $storeId, $productUrl)
            ?: $this->resolveCategoryPath($variantProduct, $storeId, $productUrl);
        $brandName = $this->resolveBrandName($parentProduct) ?: $this->resolveBrandName($variantProduct);

        $stockTargetProductId = $variantProductId > 0 ? $variantProductId : $productId;
        $availability = $this->resolveAvailability($stockTargetProductId, $storeId);

        return [
            'attribute_id' => $variantProductId,
            'category_path' => $categoryPath,
            'brand_name' => $brandName,
            'product_url' => $productUrl,
            'image_url' => $imageUrl,
            'availability' => $availability['availability'],
            'in_stock' => $availability['in_stock'],
            'stock' => $availability['stock'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveForOrderItem($item, int $storeId): array
    {
        $productId = (int) ($item && method_exists($item, 'getProductId') ? $item->getProductId() : 0);
        $variantProductId = $this->resolveVariantProductIdFromItem($item, $storeId);
        $parentProduct = $this->loadProductById($productId, $storeId);
        $variantProduct = $variantProductId > 0 ? $this->loadProductById($variantProductId, $storeId) : null;

        $productUrl = $this->resolveProductUrl($parentProduct ?: $variantProduct, $storeId);
        $imageUrl = $this->resolveProductImageUrl($variantProduct ?: $parentProduct, $storeId);
        $categoryPath = $this->resolveCategoryPath($parentProduct, $storeId, $productUrl)
            ?: $this->resolveCategoryPath($variantProduct, $storeId, $productUrl);
        $brandName = $this->resolveBrandName($parentProduct) ?: $this->resolveBrandName($variantProduct);

        return [
            'attribute_id' => $variantProductId,
            'category_path' => $categoryPath,
            'brand_name' => $brandName,
            'product_url' => $productUrl,
            'image_url' => $imageUrl,
        ];
    }

    public function buildVariantLabelFromOptions($options): ?string
    {
        if (!is_array($options)) {
            return null;
        }

        $values = [];
        foreach (['attributes_info', 'options', 'additional_options', 'bundle_options'] as $bucketKey) {
            $bucket = $options[$bucketKey] ?? null;
            if (!is_array($bucket)) {
                continue;
            }

            foreach ($bucket as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $value = $this->normalizeText(
                    $entry['value'] ?? $entry['print_value'] ?? $entry['option_value'] ?? null
                );
                if ($value === null || in_array($value, $values, true)) {
                    continue;
                }
                $values[] = $value;
            }
        }

        if (!$values) {
            return null;
        }

        $label = implode(' | ', $values);
        if (strlen($label) > 255) {
            $label = substr($label, 0, 255);
        }

        return $label !== '' ? $label : null;
    }

    private function resolveProductFromItem($item, int $productId, int $storeId)
    {
        if ($item && method_exists($item, 'getProduct')) {
            $product = $item->getProduct();
            if (is_object($product) && method_exists($product, 'getId') && (int) $product->getId() > 0) {
                return $product;
            }
        }

        return $this->loadProductById($productId, $storeId);
    }

    private function resolveVariantProductIdFromItem($item, int $storeId): int
    {
        foreach (['getChildren', 'getChildrenItems'] as $method) {
            if (!$item || !method_exists($item, $method)) {
                continue;
            }

            $children = $item->{$method}();
            if (!is_iterable($children)) {
                continue;
            }

            foreach ($children as $child) {
                if (!is_object($child) || !method_exists($child, 'getProductId')) {
                    continue;
                }

                $childProductId = (int) $child->getProductId();
                if ($childProductId > 0) {
                    return $childProductId;
                }
            }
        }

        $options = null;
        if ($item && method_exists($item, 'getProductOrderOptions')) {
            $options = $item->getProductOrderOptions();
        }
        if ((!is_array($options) || !$options) && $item && method_exists($item, 'getProductOptions')) {
            $options = $item->getProductOptions();
        }

        $variantIdentity = $this->extractVariantIdentityFromOptions($options);
        if ($variantIdentity === null) {
            return 0;
        }

        if (ctype_digit($variantIdentity)) {
            return (int) $variantIdentity;
        }

        return $this->resolveProductIdBySku($variantIdentity, $storeId);
    }

    private function extractVariantIdentityFromOptions($options): ?string
    {
        if (!is_array($options)) {
            return null;
        }

        $candidates = [
            $options['simple_sku'] ?? null,
            $options['info_buyRequest']['simple_sku'] ?? null,
            $options['info_buyRequest']['selected_configurable_option'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeText($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private function resolveProductIdBySku(string $sku, int $storeId): int
    {
        $cacheKey = $storeId . ':' . $sku;
        if (array_key_exists($cacheKey, $this->skuToProductIdCache)) {
            return $this->skuToProductIdCache[$cacheKey];
        }

        try {
            $product = $this->productRepository->get($sku, false, $storeId, true);
            $resolvedId = (int) $product->getId();
        } catch (\Throwable $e) {
            $resolvedId = 0;
        }

        $this->skuToProductIdCache[$cacheKey] = $resolvedId;
        return $resolvedId;
    }

    private function loadProductById(int $productId, int $storeId)
    {
        if ($productId <= 0) {
            return null;
        }

        $cacheKey = $storeId . ':' . $productId;
        if (array_key_exists($cacheKey, $this->productCache)) {
            return $this->productCache[$cacheKey];
        }

        try {
            $product = $this->productRepository->getById($productId, false, $storeId, true);
        } catch (\Throwable $e) {
            $product = null;
        }

        $this->productCache[$cacheKey] = $product;
        return $product;
    }

    public function normalizePublicUrl(?string $url, int $storeId): ?string
    {
        $candidate = trim((string) $url);
        if ($candidate === '' || strtolower($candidate) === 'null') {
            return null;
        }

        if (strpos($candidate, 'data:') === 0) {
            return $candidate;
        }

        $baseUrl = $this->resolveStoreBaseUrl($storeId, UrlInterface::URL_TYPE_LINK);
        if ($baseUrl === '') {
            $baseUrl = $this->resolveStoreBaseUrl($storeId, UrlInterface::URL_TYPE_WEB);
        }
        $origin = $this->extractUrlOrigin($baseUrl);

        if (strpos($candidate, '//') === 0) {
            $baseScheme = parse_url($baseUrl, PHP_URL_SCHEME);
            $scheme = is_string($baseScheme) && $baseScheme !== '' ? $baseScheme : 'https';
            $candidate = $scheme . ':' . $candidate;
        }

        if (preg_match('#^https?://#i', $candidate) === 1) {
            $currentOrigin = $this->extractUrlOrigin($candidate);
            $host = parse_url($candidate, PHP_URL_HOST);
            if ($origin !== '' && $currentOrigin !== $origin && $this->shouldRewriteUrlHost($host)) {
                $rewritten = $this->rewriteUrlOrigin($candidate, $origin);
                if ($rewritten !== null) {
                    return $rewritten;
                }
            }

            return $candidate;
        }

        if ($baseUrl === '') {
            return $candidate;
        }

        if (strpos($candidate, '/') === 0) {
            if ($origin === '') {
                return $candidate;
            }

            return $origin . $candidate;
        }

        return rtrim($baseUrl, '/') . '/' . ltrim($candidate, '/');
    }

    private function resolveProductUrl($product, int $storeId): ?string
    {
        if (!is_object($product) || !method_exists($product, 'getProductUrl')) {
            return null;
        }

        try {
            return $this->normalizePublicUrl($this->normalizeText($product->getProductUrl()), $storeId);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function resolveProductImageUrl($product, int $storeId): ?string
    {
        if (!is_object($product) || !method_exists($product, 'getData')) {
            return null;
        }

        $image = null;
        foreach (['image', 'small_image', 'thumbnail'] as $attributeCode) {
            $candidate = $this->normalizeText($product->getData($attributeCode));
            if ($candidate !== null && $candidate !== 'no_selection') {
                $image = $candidate;
                break;
            }
        }

        if ($image === null || $image === 'no_selection') {
            return null;
        }

        try {
            $store = $this->storeManager->getStore($storeId);
            $baseMediaUrl = rtrim(
                $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA),
                '/'
            );
        } catch (\Throwable $e) {
            return null;
        }

        if ($baseMediaUrl === '') {
            return null;
        }

        return $this->normalizePublicUrl(
            $baseMediaUrl . '/catalog/product/' . ltrim($image, '/'),
            $storeId
        );
    }

    private function resolveCategoryPath($product, int $storeId, ?string $fallbackProductUrl): ?string
    {
        $categoryIds = [];
        if (is_object($product) && method_exists($product, 'getCategoryIds')) {
            try {
                $rawCategoryIds = $product->getCategoryIds();
                if (is_array($rawCategoryIds)) {
                    foreach ($rawCategoryIds as $rawCategoryId) {
                        $categoryId = (int) $rawCategoryId;
                        if ($categoryId > 0) {
                            $categoryIds[] = $categoryId;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $categoryIds = [];
            }
        }

        $categoryIds = array_values(array_unique($categoryIds));
        sort($categoryIds);

        foreach ($categoryIds as $categoryId) {
            $categoryPath = $this->loadCategoryPath($categoryId, $storeId);
            if ($categoryPath !== null) {
                return $categoryPath;
            }
            return 'category_' . $categoryId;
        }

        return null;
    }

    private function loadCategoryPath(int $categoryId, int $storeId): ?string
    {
        if ($categoryId <= 0) {
            return null;
        }

        $cacheKey = $storeId . ':' . $categoryId;
        if (array_key_exists($cacheKey, $this->categoryCache)) {
            return $this->categoryCache[$cacheKey];
        }

        try {
            $category = $this->categoryRepository->get($categoryId, $storeId);
            $resolved = $this->normalizeText($category->getName());
        } catch (\Throwable $e) {
            $resolved = null;
        }

        $this->categoryCache[$cacheKey] = $resolved;
        return $resolved;
    }

    private function resolveBrandName($product): ?string
    {
        if (!is_object($product)) {
            return null;
        }

        foreach (['brand_name', 'manufacturer', 'brand'] as $attributeCode) {
            $resolved = $this->resolveAttributeText($product, $attributeCode);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    private function resolveAttributeText($product, string $attributeCode): ?string
    {
        if (!is_object($product)) {
            return null;
        }

        try {
            if (method_exists($product, 'getAttributeText')) {
                $resolved = $this->normalizeText($product->getAttributeText($attributeCode));
                if ($resolved !== null) {
                    return $resolved;
                }
            }
        } catch (\Throwable $e) {
        }

        try {
            if (method_exists($product, 'getData')) {
                return $this->normalizeText($product->getData($attributeCode));
            }
        } catch (\Throwable $e) {
        }

        return null;
    }

    /**
     * @return array{availability: string, in_stock: ?bool, stock: ?int}
     */
    private function resolveAvailability(int $productId, int $storeId): array
    {
        if ($productId <= 0) {
            return [
                'availability' => 'unknown',
                'in_stock' => null,
                'stock' => null,
            ];
        }

        try {
            $store = $this->storeManager->getStore($storeId);
            $websiteId = (int) $store->getWebsiteId();
            $stockItem = $this->stockRegistry->getStockItem($productId, $websiteId);

            if ($stockItem) {
                $qty = $stockItem->getQty();
                $stock = $qty !== null ? (int) round((float) $qty) : null;
                $inStock = (bool) $stockItem->getIsInStock();

                return [
                    'availability' => $inStock ? 'in_stock' : 'out_of_stock',
                    'in_stock' => $inStock,
                    'stock' => $stock,
                ];
            }
        } catch (\Throwable $e) {
        }

        return [
            'availability' => 'unknown',
            'in_stock' => null,
            'stock' => null,
        ];
    }

    private function normalizeText($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            $parts = [];
            foreach ($value as $entry) {
                $normalized = $this->normalizeText($entry);
                if ($normalized === null || in_array($normalized, $parts, true)) {
                    continue;
                }
                $parts[] = $normalized;
            }

            if (!$parts) {
                return null;
            }

            return implode(' | ', $parts);
        }

        $normalized = trim((string) $value);
        return $normalized !== '' ? $normalized : null;
    }

    private function nullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);
        return $normalized !== '' ? $normalized : null;
    }

    private function resolveStoreBaseUrl(int $storeId, string $urlType): string
    {
        try {
            $store = $this->storeManager->getStore($storeId);
            $resolved = trim((string) $store->getBaseUrl($urlType));
            return $resolved;
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function extractUrlOrigin(?string $url): string
    {
        $candidate = trim((string) $url);
        if ($candidate === '') {
            return '';
        }

        $parts = parse_url($candidate);
        if (!is_array($parts)) {
            return '';
        }

        $scheme = trim((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''));
        if ($scheme === '' || $host === '') {
            return '';
        }

        $origin = $scheme . '://' . $host;
        if (isset($parts['port']) && (int) $parts['port'] > 0) {
            $origin .= ':' . (int) $parts['port'];
        }

        return $origin;
    }

    private function shouldRewriteUrlHost($host): bool
    {
        $normalizedHost = strtolower(trim((string) $host));
        if ($normalizedHost === '') {
            return true;
        }

        if (in_array($normalizedHost, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }

        if (
            strpos($normalizedHost, '.') === false
            || str_ends_with($normalizedHost, '.local')
            || str_ends_with($normalizedHost, '.test')
            || str_ends_with($normalizedHost, '.internal')
        ) {
            return true;
        }

        return false;
    }

    private function rewriteUrlOrigin(string $url, string $origin): ?string
    {
        $parts = parse_url($url);
        $originParts = parse_url($origin);
        if (!is_array($parts) || !is_array($originParts)) {
            return null;
        }

        $scheme = trim((string) ($originParts['scheme'] ?? ''));
        $host = trim((string) ($originParts['host'] ?? ''));
        if ($scheme === '' || $host === '') {
            return null;
        }

        $rebuilt = $scheme . '://' . $host;
        if (isset($originParts['port']) && (int) $originParts['port'] > 0) {
            $rebuilt .= ':' . (int) $originParts['port'];
        }

        $path = (string) ($parts['path'] ?? '');
        if ($path !== '') {
            $rebuilt .= $path;
        }

        if (isset($parts['query']) && $parts['query'] !== '') {
            $rebuilt .= '?' . $parts['query'];
        }

        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $rebuilt .= '#' . $parts['fragment'];
        }

        return $rebuilt;
    }
}
