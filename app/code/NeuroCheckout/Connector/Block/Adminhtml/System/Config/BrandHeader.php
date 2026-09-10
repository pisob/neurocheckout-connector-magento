<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class BrandHeader extends Field
{
    protected function _getElementHtml(AbstractElement $element): string
    {
        $imageUrl = $this->getViewFileUrl('NeuroCheckout_Connector::images/connector-mark.svg');

        return '<div style="display:flex;align-items:center;gap:14px;padding:14px 0 4px;">'
            . '<img src="' . $this->escapeHtmlAttr($imageUrl) . '" alt="NeuroCheckout" style="width:52px;height:52px;border-radius:16px;box-shadow:0 12px 28px rgba(17,49,78,0.14);flex:0 0 auto;">'
            . '<div>'
            . '<div style="display:inline-flex;margin-bottom:6px;font-size:11px;font-weight:700;letter-spacing:0.14em;text-transform:uppercase;color:#2b7498;">NeuroCheckout Connector</div>'
            . '<div style="font-size:24px;font-weight:700;color:#1f2947;line-height:1.1;">NeuroCheckout Connector</div>'
            . '<div style="margin-top:4px;color:#5b6784;">AI agents and connector operations inside your Magento workspace.</div>'
            . '</div>'
            . '</div>';
    }
}
