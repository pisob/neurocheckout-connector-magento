<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class FixedCronInterval extends Field
{
    private const INTERVAL_SECONDS = 300;

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
        $label = $this->escapeHtml((string) __('5 minutes'));
        $helpText = $this->escapeHtml(
            (string) __('Intervalle fixe recommande pour la synchronisation cron du connecteur.')
        );

        return <<<HTML
<div style="display:flex;flex-direction:column;gap:6px;">
    <div style="display:inline-flex;align-items:center;min-height:32px;">
        <strong>{$label}</strong>
    </div>
    <div style="color:#5c6787;max-width:720px;">{$helpText}</div>
</div>
<input type="hidden" name="{$fieldName}" value="300" />
HTML;
    }
}
