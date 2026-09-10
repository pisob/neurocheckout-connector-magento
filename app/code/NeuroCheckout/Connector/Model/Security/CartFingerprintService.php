<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Security;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item as QuoteItem;

class CartFingerprintService
{
    public function fromQuote(Quote $quote): string
    {
        $lines = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            if (!$item instanceof QuoteItem) {
                continue;
            }

            $qty = (float) $item->getQty();
            if ($qty <= 0) {
                continue;
            }

            $productOptions = $item->getProductOptions();
            $optionParts = [];

            if (is_array($productOptions)) {
                $attributesInfo = $productOptions['attributes_info'] ?? [];
                if (is_array($attributesInfo)) {
                    foreach ($attributesInfo as $attribute) {
                        if (!is_array($attribute)) {
                            continue;
                        }

                        $label = trim((string) ($attribute['label'] ?? ''));
                        $value = trim((string) ($attribute['value'] ?? ''));
                        if ($label === '' && $value === '') {
                            continue;
                        }

                        $optionParts[] = strtolower($label) . '=' . strtolower($value);
                    }
                }
            }

            sort($optionParts);

            $lines[] = implode('|', [
                'pid:' . (int) $item->getProductId(),
                'sku:' . strtolower(trim((string) $item->getSku())),
                'qty:' . rtrim(rtrim(sprintf('%.4F', $qty), '0'), '.'),
                'opt:' . implode(',', $optionParts),
            ]);
        }

        sort($lines);

        return hash('sha256', implode("\n", $lines));
    }
}
