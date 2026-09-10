<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class AllowDiscountConfirm extends Field
{
    protected function _getElementHtml(AbstractElement $element): string
    {
        $html = $element->getElementHtml();
        $fieldId = (string) $element->getHtmlId();
        $selector = '#' . $fieldId;

        $jsonConfig = json_encode(
            [
                '*' => [
                    'NeuroCheckout_Connector/js/allow-discount-confirm' => [
                        'selector' => $selector
                    ]
                ]
            ],
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
        );

        return $html . '<script type="text/x-magento-init">' . $jsonConfig . '</script>';
    }
}
