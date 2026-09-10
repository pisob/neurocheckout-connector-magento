<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class ReadonlyRecoveryEnabled extends Field
{
    public function render(AbstractElement $element): string
    {
        $element->unsScope();
        $element->unsCanUseWebsiteValue();
        $element->unsCanUseDefaultValue();
        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $fieldName = htmlspecialchars((string) $element->getName(), ENT_QUOTES, 'UTF-8');
        $statusLabel = $this->escapeHtml((string) __('Toujours active'));
        $helpText = $this->escapeHtml((string) __('Cette securite reste forcee afin de garantir les liens de recovery, le restore du panier et les garde-fous IA.'));

        return <<<HTML
<div style="display:flex;flex-direction:column;gap:8px;">
    <div style="display:flex;align-items:center;gap:8px;">
        <input type="checkbox" checked="checked" disabled="disabled" />
        <span><strong>{$statusLabel}</strong></span>
    </div>
    <div style="color:#5c6787;max-width:720px;">{$helpText}</div>
</div>
<input type="hidden" name="{$fieldName}" value="1" />
HTML;
    }
}
