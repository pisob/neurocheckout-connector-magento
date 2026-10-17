<?php
declare(strict_types=1);
namespace NeuroCheckout\Connector\Community;

use RuntimeException;

/** Strict, transient price exchange over the existing authenticated source route. */
final class CustomerPricesProtocol
{
    public static function request(array $input, string $shopId): void
    {
        if (!self::keys($input, ['schema','shopId','operation','storeId','websiteId','customerId','customerEmail','productIds','currency'])
            || $input['schema'] !== 1 || $input['shopId'] !== $shopId || $input['operation'] !== 'customer_prices'
            || !is_string($input['customerEmail']) || strlen($input['customerEmail']) > 254
            || !filter_var($input['customerEmail'], FILTER_VALIDATE_EMAIL)
            || !is_string($input['currency']) || !preg_match('/^[A-Z]{3}$/D', $input['currency'])
            || !is_array($input['productIds']) || count($input['productIds']) < 1 || count($input['productIds']) > 16
            || array_keys($input['productIds']) !== range(0, count($input['productIds']) - 1)) { self::invalid(); }
        foreach (['storeId','websiteId','customerId'] as $key) { self::id($input[$key]); }
        $seen = [];
        foreach ($input['productIds'] as $id) {
            self::id($id);
            if (isset($seen[$id])) { self::invalid(); }
            $seen[$id] = true;
        }
    }

    public static function response($page, array $input, int $scope, string $raw): void
    {
        if (!self::keys($page, ['schema','shopId','operation','requestHash','prices']) || $page['schema'] !== 1
            || $page['shopId'] !== $input['shopId'] || $page['operation'] !== 'customer_prices'
            || $page['requestHash'] !== hash('sha256', $raw) || (string) $scope !== $input['storeId']) { self::invalid(); }
        $p = $page['prices'];
        if (!self::keys($p, ['schema','store_id','website_id','customer_id','customer_email','customer_group_id',
                'customer_tax_class_id','currency','quantity','read_at','offers'])
            || $p['schema'] !== 'magento-customer-prices-v1' || $p['store_id'] !== $scope
            || $p['website_id'] !== (int) $input['websiteId'] || $p['customer_id'] !== (int) $input['customerId']
            || !is_string($p['customer_email']) || strcasecmp($p['customer_email'], $input['customerEmail']) !== 0
            || $p['currency'] !== $input['currency'] || $p['quantity'] !== 1
            || !is_string($p['read_at']) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $p['read_at'])
            || abs(time() - (strtotime($p['read_at']) ?: 0)) > 15
            || !is_array($p['offers']) || count($p['offers']) !== count($input['productIds'])
            || array_keys($p['offers']) !== range(0, count($p['offers']) - 1)) { self::invalid(); }
        foreach (['customer_group_id','customer_tax_class_id'] as $key) {
            if (!is_int($p[$key]) || $p[$key] < 0 || $p[$key] > 9999999999) { self::invalid(); }
        }
        foreach ($p['offers'] as $i => $offer) {
            if (!is_array($offer) || ($offer['product_id'] ?? null) !== $input['productIds'][$i]) { self::invalid(); }
            if (($offer['status'] ?? null) === 'unavailable') {
                if (!self::keys($offer, ['product_id','status'])) { self::invalid(); }
                continue;
            }
            if (!self::keys($offer, ['product_id','status','currency','price_excl_tax','price_incl_tax','display_mode','display_price'])
                || $offer['status'] !== 'available' || $offer['currency'] !== $input['currency']
                || !in_array($offer['display_mode'], ['excluding_tax','including_tax','both'], true)) { self::invalid(); }
            foreach (['price_excl_tax','price_incl_tax'] as $key) { self::money($offer[$key]); }
            if ($offer['price_incl_tax'] < $offer['price_excl_tax']) { self::invalid(); }
            if ($offer['display_mode'] === 'both') {
                if ($offer['display_price'] !== null) { self::invalid(); }
            } else {
                self::money($offer['display_price']);
                $expected = $offer[$offer['display_mode'] === 'including_tax' ? 'price_incl_tax' : 'price_excl_tax'];
                if ((float) $offer['display_price'] !== (float) $expected) { self::invalid(); }
            }
        }
        if (strlen(json_encode($page, JSON_THROW_ON_ERROR)) > 16384) { self::invalid(); }
    }

    private static function id($id): void
    {
        if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,9}$/D', $id)) { self::invalid(); }
    }
    private static function money($value): void
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0 || $value > 1000000000) { self::invalid(); }
    }
    private static function keys($value, array $expected): bool
    {
        if (!is_array($value)) { return false; }
        $keys = array_keys($value); sort($keys); sort($expected); return $keys === $expected;
    }
    private static function invalid(): void { throw new RuntimeException('source_invalid'); }
}
