<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class TestApiButton extends Field
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
        $params = [];
        $website = trim((string) $this->getRequest()->getParam('website'));
        $store = trim((string) $this->getRequest()->getParam('store'));

        if ($website !== '') {
            $params['website'] = $website;
        }

        if ($store !== '') {
            $params['store'] = $store;
        }

        $url = $this->getUrl('neurocheckoutconnector/system_config/testApiJson', $params);
        $buttonId = 'nc_api_test_btn_' . uniqid();
        $panelId = 'nc_api_test_panel_' . uniqid();
        $messageId = 'nc_api_test_message_' . uniqid();
        $metaId = 'nc_api_test_meta_' . uniqid();
        $detailsId = 'nc_api_test_details_' . uniqid();
        $jsonId = 'nc_api_test_json_' . uniqid();
        $jsonUrl = json_encode(
            $url,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
        );
        $i18n = json_encode([
            'buttonLabel' => (string) __('Tester l API'),
            'guidanceTitle' => (string) __('Ordre recommande'),
            'guidanceStep1' => (string) __('1. Renseignez et sauvegardez la configuration generale puis les garde-fous IA.'),
            'guidanceStep2' => (string) __('2. Lancez ce test pour verifier la connexion API et debloquer le cron.'),
            'guidanceStep3' => (string) __('3. N utilisez les outils de debug cron qu apres un test API valide.'),
            'detailsSummary' => (string) __('Retour JSON minimal'),
            'fieldApiEndpointLabel' => (string) __('API Endpoint'),
            'fieldApiKeyLabel' => (string) __('API Key'),
            'fieldShopExternalIdLabel' => (string) __('Shop External ID'),
            'fieldMinCartLabel' => (string) __('Montant minimum du panier'),
            'fieldMaxDiscountLabel' => (string) __('Reduction maximale IA (%)'),
            'fieldRequired' => (string) __('Le champ "%1" est obligatoire.'),
            'fieldValidUrl' => (string) __('Le champ "%1" doit etre une URL valide en http ou https.'),
            'fieldGreaterOrEqualZero' => (string) __('Le champ "%1" doit etre un nombre superieur ou egal a 0.'),
            'fieldBetweenZeroAndHundred' => (string) __('Le champ "%1" doit etre un nombre entre 0 et 100.'),
            'testValid' => (string) __('Test API valide.'),
            'testFailed' => (string) __('Test API echoue.'),
            'completeIaFirst' => (string) __('Completez d abord la configuration IA requise.'),
            'completeConfigurationFirst' => (string) __('Completez et sauvegardez d abord la configuration requise.'),
            'testInProgress' => (string) __('Test API en cours...'),
            'httpError' => (string) __('Erreur HTTP pendant le test API.'),
            'lastValidation' => (string) __('Derniere validation: %1'),
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $guidanceTitle = $this->escapeHtml((string) __('Ordre recommande'));
        $guidanceStep1 = $this->escapeHtml((string) __('1. Renseignez et sauvegardez la configuration generale puis les garde-fous IA.'));
        $guidanceStep2 = $this->escapeHtml((string) __('2. Lancez ce test pour verifier la connexion API et debloquer le cron.'));
        $guidanceStep3 = $this->escapeHtml((string) __('3. N utilisez les outils de debug cron qu apres un test API valide.'));
        $buttonLabel = $this->escapeHtml((string) __('Tester l API'));
        $detailsSummary = $this->escapeHtml((string) __('Retour JSON minimal'));

        return <<<HTML
<div style="display:flex;flex-direction:column;gap:10px;">
    <div style="padding:12px 14px;border-radius:8px;border:1px solid #d6e4f5;background:#f4f8fd;color:#375a7f;max-width:760px;">
        <div style="font-weight:700;margin-bottom:6px;">{$guidanceTitle}</div>
        <ol style="margin:0 0 0 18px;padding:0;display:flex;flex-direction:column;gap:4px;">
            <li>{$guidanceStep1}</li>
            <li>{$guidanceStep2}</li>
            <li>{$guidanceStep3}</li>
        </ol>
    </div>
    <button id="{$buttonId}" type="button" class="action-default">
        <span>{$buttonLabel}</span>
    </button>
    <div id="{$panelId}" style="display:none;margin-top:10px;padding:12px 14px;border-radius:4px;border:1px solid #d6d6d6;background:#f8f8f8;">
        <div id="{$messageId}" style="font-weight:600;color:#1f2937;"></div>
        <div id="{$metaId}" style="margin-top:6px;font-size:12px;color:#4b5563;"></div>
    </div>
    <details id="{$detailsId}" style="display:none;margin-top:8px;">
        <summary style="cursor:pointer;color:#1f2937;">{$detailsSummary}</summary>
        <pre id="{$jsonId}" style="margin-top:6px;padding:10px;background:#f8f8f8;border:1px solid #d6d6d6;max-height:280px;overflow:auto;white-space:pre-wrap;word-break:break-word;"></pre>
    </details>
</div>
<script>
require(['jquery'], function ($) {
    var requestUrl = {$jsonUrl};
    var i18n = {$i18n};
    var formKey = window.FORM_KEY || null;
    if (!formKey && $.mage && $.mage.cookies) {
        formKey = $.mage.cookies.get('form_key');
    }

    function translate(template) {
        var args = Array.prototype.slice.call(arguments, 1);
        return String(template || '').replace(/%([0-9]+)/g, function (_, index) {
            var position = Number(index) - 1;
            return typeof args[position] !== 'undefined' ? args[position] : '';
        });
    }

    function findField(candidates) {
        for (var i = 0; i < candidates.length; i++) {
            var \$field = $(candidates[i]).first();
            if (\$field.length) {
                return \$field;
            }
        }
        return $();
    }

    function findIaField(candidates) {
        return findField(candidates);
    }

    function parseNumericField(\$field) {
        if (!\$field.length) {
            return null;
        }
        var raw = $.trim(String(\$field.val() || ''));
        if (raw === '') {
            return null;
        }
        var numeric = Number(raw);
        return Number.isFinite(numeric) ? numeric : null;
    }

    function getTrimmedValue(\$field) {
        if (!\$field.length) {
            return '';
        }
        return $.trim(String(\$field.val() || ''));
    }

    function isValidHttpUrl(raw) {
        try {
            var parsed = new URL(raw);
            return parsed.protocol === 'http:' || parsed.protocol === 'https:';
        } catch (e) {
            return false;
        }
    }

    function getGeneralIssues() {
        var issues = [];
        var endpointField = findField([
            '#neurocheckoutconnector_general_api_endpoint',
            '[name="groups[general][fields][api_endpoint][value]"]'
        ]);
        var apiKeyField = findField([
            '#neurocheckoutconnector_general_api_key',
            '[name="groups[general][fields][api_key][value]"]'
        ]);
        var shopExternalIdField = findField([
            '#neurocheckoutconnector_general_shop_external_id',
            '[name="groups[general][fields][shop_external_id][value]"]'
        ]);

        var endpointValue = getTrimmedValue(endpointField);
        if (endpointValue === '') {
            issues.push(translate(i18n.fieldRequired, i18n.fieldApiEndpointLabel));
        } else if (!isValidHttpUrl(endpointValue)) {
            issues.push(translate(i18n.fieldValidUrl, i18n.fieldApiEndpointLabel));
        }

        if (getTrimmedValue(apiKeyField) === '') {
            issues.push(translate(i18n.fieldRequired, i18n.fieldApiKeyLabel));
        }

        if (getTrimmedValue(shopExternalIdField) === '') {
            issues.push(translate(i18n.fieldRequired, i18n.fieldShopExternalIdLabel));
        }

        return issues;
    }

    function getIaIssues() {
        var issues = [];
        var minCartField = findIaField([
            '#neurocheckoutconnector_ia_min_cart_total',
            '[name="groups[ia][fields][min_cart_total][value]"]'
        ]);
        var maxDiscountField = findIaField([
            '#neurocheckoutconnector_ia_max_discount_percent',
            '[name="groups[ia][fields][max_discount_percent][value]"]'
        ]);

        var minCartValue = parseNumericField(minCartField);
        if (minCartValue === null) {
            issues.push(translate(i18n.fieldRequired, i18n.fieldMinCartLabel));
        } else if (minCartValue < 0) {
            issues.push(translate(i18n.fieldGreaterOrEqualZero, i18n.fieldMinCartLabel));
        }

        var maxDiscountValue = parseNumericField(maxDiscountField);
        if (maxDiscountValue === null) {
            issues.push(translate(i18n.fieldRequired, i18n.fieldMaxDiscountLabel));
        } else if (maxDiscountValue < 0 || maxDiscountValue > 100) {
            issues.push(translate(i18n.fieldBetweenZeroAndHundred, i18n.fieldMaxDiscountLabel));
        }

        return issues;
    }

    function focusRequiredSection() {
        var generalIssues = getGeneralIssues();
        var \$target = generalIssues.length > 0 ? findField([
            '#neurocheckoutconnector_general_api_endpoint',
            '[name="groups[general][fields][api_endpoint][value]"]',
            '#neurocheckoutconnector_general_api_key',
            '[name="groups[general][fields][api_key][value]"]',
            '#neurocheckoutconnector_general_shop_external_id',
            '[name="groups[general][fields][shop_external_id][value]"]'
        ]) : findIaField([
            '#neurocheckoutconnector_ia_min_cart_total',
            '[name="groups[ia][fields][min_cart_total][value]"]',
            '#neurocheckoutconnector_ia_max_discount_percent',
            '[name="groups[ia][fields][max_discount_percent][value]"]'
        ]);
        if (!\$target.length) {
            return;
        }

        var row = \$target.closest('tr');
        var scrollTarget = row.length ? row : \$target;
        $('html, body').animate({
            scrollTop: Math.max(0, scrollTarget.offset().top - 140)
        }, 180);
        \$target.trigger('focus');
    }

    function syncButtonState() {
        var issues = getGeneralIssues().concat(getIaIssues());
        var blocked = issues.length > 0;
        var \$button = $('#{$buttonId}');
        \$button.prop('disabled', blocked);
        \$button.attr('aria-disabled', blocked ? 'true' : 'false');
        if (blocked) {
            \$button.attr('title', issues.join(' '));
        } else {
            \$button.removeAttr('title');
        }
    }

    function renderResult(response) {
        var ok = response && response.success === true;
        var message = response && response.message ? response.message : (ok ? i18n.testValid : i18n.testFailed);
        var timestamp = response && response.timestamp ? response.timestamp : '';
        var healthStatus = response && response.health && typeof response.health.status !== 'undefined' ? response.health.status : null;

        var \$panel = $('#{$panelId}');
        var \$message = $('#{$messageId}');
        var \$meta = $('#{$metaId}');
        var \$details = $('#{$detailsId}');
        var \$json = $('#{$jsonId}');

        \$panel.show();
        if (ok) {
            \$panel.css({
                background: '#dff5df',
                borderColor: '#8bcf8b'
            });
            \$message.css('color', '#176b2c').text(message);
        } else {
            \$panel.css({
                background: '#fde7e9',
                borderColor: '#e7a7ad'
            });
            \$message.css('color', '#a12622').text(message);
        }

        \$meta.text(timestamp ? translate(i18n.lastValidation, timestamp) : '');

        var minimal = {
            success: ok,
            message: message,
            status: healthStatus,
            event_probe_success: response && response.event_probe ? (response.event_probe.success === true) : null,
            event_probe_status: response && response.event_probe && typeof response.event_probe.status !== 'undefined'
                ? response.event_probe.status
                : null,
            event_probe_error: response && response.event_probe && response.event_probe.error
                ? response.event_probe.error
                : null,
            api_ready: response && typeof response.api_ready !== 'undefined' ? response.api_ready : null,
            api_issues: response && $.isArray(response.api_issues) ? response.api_issues : [],
            ia_ready: response && typeof response.ia_ready !== 'undefined' ? response.ia_ready : null,
            ia_issues: response && $.isArray(response.ia_issues) ? response.ia_issues : [],
            timestamp: timestamp
        };

        \$details.show();
        \$json.text(JSON.stringify(minimal, null, 2));
    }

    $('#{$buttonId}').on('click', function () {
        var generalIssues = getGeneralIssues();
        var iaIssues = getIaIssues();
        var issues = generalIssues.concat(iaIssues);
        if (issues.length > 0) {
            renderResult({
                success: false,
                message: i18n.completeConfigurationFirst + ' ' + issues.join(' '),
                status: null,
                api_ready: generalIssues.length === 0,
                api_issues: generalIssues,
                ia_ready: iaIssues.length === 0,
                ia_issues: iaIssues,
                timestamp: ''
            });
            focusRequiredSection();
            return;
        }

        var \$button = $(this);
        \$button.prop('disabled', true);
        renderResult({
            success: false,
            message: i18n.testInProgress,
            status: null,
            ia_ready: null,
            timestamp: ''
        });

        $.ajax({
            url: requestUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                form_key: formKey
            }
        }).done(function (response) {
            renderResult(response || {});
        }).fail(function (xhr) {
            renderResult({
                success: false,
                message: i18n.httpError,
                status: xhr.status || 0,
                status_text: xhr.statusText || '',
                response_excerpt: (xhr.responseText || '').slice(0, 1200)
            });
        }).always(function () {
            syncButtonState();
        });
    });

    $(document).on('input change', [
        '#neurocheckoutconnector_general_api_endpoint',
        '[name="groups[general][fields][api_endpoint][value]"]',
        '#neurocheckoutconnector_general_api_key',
        '[name="groups[general][fields][api_key][value]"]',
        '#neurocheckoutconnector_general_shop_external_id',
        '[name="groups[general][fields][shop_external_id][value]"]',
        '#neurocheckoutconnector_ia_min_cart_total',
        '[name="groups[ia][fields][min_cart_total][value]"]',
        '#neurocheckoutconnector_ia_max_discount_percent',
        '[name="groups[ia][fields][max_discount_percent][value]"]'
    ].join(', '), syncButtonState);

    syncButtonState();
});
</script>
HTML;
    }
}
