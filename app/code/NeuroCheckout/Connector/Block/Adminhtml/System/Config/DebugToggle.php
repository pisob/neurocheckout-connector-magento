<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class DebugToggle extends Field
{
    private const DEBUG_MODE_ID = 'nc_debug_mode_checkbox';
    private const DEBUG_ADVANCED_ID = 'nc_debug_advanced_checkbox';
    private const WARNING_ID = 'nc_debug_exclusive_warning';

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
        $fieldKey = $this->resolveFieldKey((string) $element->getName());
        $checkboxId = $fieldKey === 'debug_advanced' ? self::DEBUG_ADVANCED_ID : self::DEBUG_MODE_ID;
        $checked = ((string) $element->getValue() === '1') ? ' checked="checked"' : '';
        $label = $this->escapeHtml((string) __('Active'));
        $helpText = $fieldKey === 'debug_advanced'
            ? (string) __('Autorise une execution immediate avec de vraies donnees. Reserve a un diagnostic court.')
            : (string) __('Autorise un test manuel securise. A desactiver apres diagnostic.');

        $warning = '';
        if ($fieldKey === 'debug_advanced') {
            $warningText = $this->escapeHtml((string) __('Un seul mode debug peut etre actif a la fois.'));
            $warning = '<div id="' . self::WARNING_ID . '" style="display:none;margin-top:8px;padding:10px 12px;border:1px solid #f1d38a;background:#fff7df;color:#8a6112;border-radius:6px;max-width:720px;">' . $warningText . '</div>';
        }

        $script = $this->renderScript();
        $help = $this->escapeHtml($helpText);

        return <<<HTML
<div style="display:flex;flex-direction:column;gap:6px;">
    <input type="hidden" name="{$fieldName}" value="0" />
    <label for="{$checkboxId}" style="display:inline-flex;align-items:center;gap:8px;min-height:32px;">
        <input type="checkbox" id="{$checkboxId}" name="{$fieldName}" value="1"{$checked} />
        <span>{$label}</span>
    </label>
    <div style="color:#5c6787;max-width:720px;">{$help}</div>
    {$warning}
</div>
{$script}
HTML;
    }

    private function resolveFieldKey(string $fieldName): string
    {
        return strpos($fieldName, '[debug_advanced]') !== false ? 'debug_advanced' : 'debug_mode';
    }

    private function renderScript(): string
    {
        $debugId = self::DEBUG_MODE_ID;
        $advancedId = self::DEBUG_ADVANCED_ID;
        $warningId = self::WARNING_ID;
        $forceBlocksTest = $this->escapeJs(
            (string) __('Le mode debug avance est actif. Desactivez-le d abord pour activer le test cron.')
        );
        $testBlocksForce = $this->escapeJs(
            (string) __('Le mode debug cron est actif. Desactivez-le d abord pour activer le mode debug avance.')
        );

        return <<<HTML
<script>
require(["jquery"], function ($) {
    $(function () {
        if (window.neuroCheckoutDebugTogglesBound) {
            return;
        }

        var debug = $("#{$debugId}");
        var advanced = $("#{$advancedId}");
        var warning = $("#{$warningId}");

        if (!debug.length || !advanced.length) {
            return;
        }

        window.neuroCheckoutDebugTogglesBound = true;

        function warn(message) {
            if (!warning.length) {
                return;
            }
            warning.text(message || "");
            warning.toggle(!!message);
            if (message) {
                window.setTimeout(function () {
                    warning.hide();
                }, 3500);
            }
        }

        if (debug.prop("checked") && advanced.prop("checked")) {
            advanced.prop("checked", false);
        }

        debug.on("change", function () {
            if (debug.prop("checked") && advanced.prop("checked")) {
                debug.prop("checked", false);
                warn("{$forceBlocksTest}");
            }
        });

        advanced.on("change", function () {
            if (advanced.prop("checked") && debug.prop("checked")) {
                advanced.prop("checked", false);
                warn("{$testBlocksForce}");
            }
        });
    });
});
</script>
HTML;
    }
}
