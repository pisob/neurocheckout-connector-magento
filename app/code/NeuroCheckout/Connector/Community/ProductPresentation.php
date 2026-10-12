<?php
declare(strict_types=1);
namespace NeuroCheckout\Connector\Community;

use Magento\Store\Model\StoreManagerInterface;

/** Store-scoped media configuration, never product-ID/image-ID guessing. */
class ProductPresentation
{
    private StoreManagerInterface $stores;
    public function __construct(StoreManagerInterface $stores) { $this->stores = $stores; }

    public function forStore(int $scope): callable
    {
        $store = $this->stores->getStore($scope);
        $media = self::safeUrl((string) $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA, true));
        $base = self::safeUrl((string) $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_LINK, true));
        if (strpbrk($media, '?#') !== false) { $media = ''; }
        if (strpbrk($base, '?#') !== false) { $base = ''; }
        return static function (array $product) use ($media, $base): array {
            $path = (string) ($product['attributes']['image']['effective'] ?? '');
            $image = '';
            if ($media !== '' && $path !== '' && $path !== 'no_selection'
                && !preg_match('/[\x00-\x1f\x7f\\\\:?#]/', $path)
                && !preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $path) && strpos($path, '//') === false) {
                $image = rtrim($media, '/') . '/catalog/product/'
                    . implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
            }
            $image = self::safeUrl($image);
            return ['image_url' => $image, 'image_status' => $image !== '' ? 'available' : 'absent',
                'product_url' => self::safeUrl(rtrim($base, '/') . '/catalog/product/view/id/' . (int) $product['entity_id'] . '/')];
        };
    }

    private static function safeUrl(string $url): string
    {
        $parts = parse_url($url);
        return strlen($url) <= 2048 && !preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            && is_array($parts) && in_array($parts['scheme'] ?? '', ['https', 'http'], true)
            && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass']) ? $url : '';
    }
}
