<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use NeuroCheckout\Connector\Model\Adminhtml\ScopeResolver;
use NeuroCheckout\Connector\Model\Config;
use NeuroCheckout\Connector\Model\Monitoring\HealthMonitor;
use NeuroCheckout\Connector\Model\Repository\CronLogRepository;

class CronTools extends Field
{
    private Config $config;
    private ScopeResolver $scopeResolver;
    private HealthMonitor $healthMonitor;
    private CronLogRepository $cronLogRepository;
    private StoreManagerInterface $storeManager;

    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        Config $config,
        ScopeResolver $scopeResolver,
        HealthMonitor $healthMonitor,
        CronLogRepository $cronLogRepository,
        StoreManagerInterface $storeManager,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->config = $config;
        $this->scopeResolver = $scopeResolver;
        $this->healthMonitor = $healthMonitor;
        $this->cronLogRepository = $cronLogRepository;
        $this->storeManager = $storeManager;
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $scopeParams = $this->scopeResolver->getScopeParams();
        $storeId = $this->scopeResolver->getEffectiveStoreId($scopeParams);
        $health = $this->healthMonitor->getHealthReport($storeId);
        $healthColor = $this->resolveHealthColor((int) ($health['score'] ?? 0));
        $executionMode = $this->config->getExecutionMode($storeId);
        $lastRun = $this->config->getInt(Config::XML_PATH_LAST_RUN, $storeId);
        $lastRunDisplay = $lastRun > 0
            ? date('Y-m-d H:i:s', $lastRun)
            : (string) __('Jamais execute');
        $token = $this->config->getOrCreateCronToken($storeId);
        $apiKey = $this->config->getNormalizedApiKey($storeId);
        $cronUrl = $apiKey !== '' ? $this->buildSecureCronUrl($storeId, $token, $apiKey) : '';
        $intervalSeconds = max(60, $this->config->getInt(Config::XML_PATH_CRON_INTERVAL_SECONDS, $storeId));
        $intervalMinutes = max(1, (int) ceil($intervalSeconds / 60));
        $scheduleExpression = '*/' . $intervalMinutes . ' * * * *';
        $runnerCommand = 'php bin/magento neurocheckout:connector:cron-run --store-id=' . $storeId;
        $debugMode = $this->config->isDebugModeEnabled($storeId);
        $debugAdvanced = $this->config->isDebugAdvancedEnabled($storeId);
        $cronAllowedIps = trim($this->config->getString(Config::XML_PATH_CRON_ALLOWED_IPS, $storeId));
        $adminUrl = $this->getUrl('neurocheckoutconnector/system_config/runCronJson', $scopeParams);
        $buttonBaseId = 'nc_cron_tools_' . uniqid();
        $serverCronPanelId = $buttonBaseId . '_server_cron_only';
        $testButtonId = $buttonBaseId . '_test';
        $forceButtonId = $buttonBaseId . '_force';
        $resultId = $buttonBaseId . '_result';
        $jsonId = $buttonBaseId . '_json';
        $payloadId = $buttonBaseId . '_payload';
        $messages = json_encode([
            'running' => (string) __('Execution en cours...'),
            'ok' => (string) __('Execution terminee avec succes.'),
            'failed' => (string) __('Execution echouee.'),
            'httpError' => (string) __('Erreur HTTP pendant l execution cron.'),
            'jsonSummary' => (string) __('Retour JSON minimal'),
            'forceConfirm' => (string) __('Executer le cron immediatement avec de vraies donnees ? Cette action doit rester exceptionnelle et reservee au diagnostic.'),
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

        $healthLabel = $this->escapeHtml($this->translateHealthStatus((string) ($health['status'] ?? 'unknown')));
        $healthBadge = $healthLabel . ' - ' . (int) ($health['score'] ?? 0) . '/100';
        $modeLabel = $this->escapeHtml($this->translateExecutionMode($executionMode));
        $allowedIpsLabel = $cronAllowedIps !== ''
            ? $this->escapeHtml($cronAllowedIps)
            : '<span style="color:#6b7280;">' . $this->escapeHtml((string) __('Aucune restriction')) . '</span>';
        $serverCronDisplay = $executionMode === 'cron' ? 'block' : 'none';

        $html = '<div style="display:flex;flex-direction:column;gap:14px;">';
        $html .= '<div style="padding:14px;border-radius:10px;border:1px solid #dbe4f0;background:#ffffff;">';
        $html .= '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px;">';
        $html .= '<span style="display:inline-block;padding:6px 12px;border-radius:999px;color:#fff;background:' . $healthColor . ';font-weight:700;">' . strtoupper($healthBadge) . '</span>';
        $html .= '<span><strong>' . $this->escapeHtml((string) __('Backlog:')) . '</strong> ' . (int) ($health['backlog'] ?? 0) . '</span>';
        $html .= '<span><strong>' . $this->escapeHtml((string) __('Derniere execution:')) . '</strong> ' . $this->escapeHtml($lastRunDisplay) . '</span>';
        $html .= '</div>';
        $html .= '<div><strong>' . $this->escapeHtml((string) __('Mode actif:')) . '</strong> ' . $modeLabel . '</div>';
        $html .= '</div>';

        $html .= '<div id="' . $serverCronPanelId . '" style="display:' . $serverCronDisplay . ';padding:14px;border-radius:10px;border:1px solid #dbe4f0;background:#ffffff;">';
        $html .= '<div style="font-weight:700;margin-bottom:8px;">' . $this->escapeHtml((string) __('Configuration cron serveur')) . '</div>';
        $html .= '<div style="color:#5c6787;margin-bottom:10px;max-width:760px;">'
            . $this->escapeHtml((string) __('Ces informations s utilisent seulement lorsque le mode cron serveur manuel est selectionne.'))
            . '</div>';
        $html .= '<div style="display:flex;flex-direction:column;gap:10px;">';
        $html .= $this->renderInfoBox(
            (string) __('Commande cron recommandee'),
            '<code style="word-break:break-all;">' . $this->escapeHtml($runnerCommand) . '</code>',
            (string) __('Utilisez cette commande pour la planification reguliere cote serveur.')
        );
        $html .= $this->renderInfoBox(
            (string) __('Expression cron indicative'),
            '<code>' . $this->escapeHtml($scheduleExpression) . '</code>',
            (string) __('Avec un intervalle de %1 secondes, vous pouvez viser une execution toutes les %2 minute(s).', $intervalSeconds, $intervalMinutes)
        );
        if ($cronUrl !== '') {
            $html .= $this->renderInfoBox(
                (string) __('URL cron securisee'),
                '<code style="word-break:break-all;">' . $this->escapeHtml($cronUrl) . '</code>',
                (string) __('Cette URL est signee avec un horodatage court. Preferez la commande cron pour une planification reguliere.')
            );
        } else {
            $html .= '<div style="padding:12px;border-radius:8px;border:1px solid #efb0b8;background:#fff0f2;color:#99233a;">'
                . $this->escapeHtml((string) __('Configurez l endpoint API et la cle API pour generer l URL cron securisee.'))
                . '</div>';
        }
        $html .= '</div>';
        $html .= '<div style="margin-top:10px;"><strong>' . $this->escapeHtml((string) __('IPs autorisees:')) . '</strong> ' . $allowedIpsLabel . '</div>';
        $html .= '</div>';

        $html .= '<div style="padding:14px;border-radius:10px;border:1px solid #dbe4f0;background:#ffffff;">';
        $html .= '<div style="font-weight:700;margin-bottom:8px;">' . $this->escapeHtml((string) __('Actions de debug cron')) . '</div>';
        $html .= '<div style="color:#5c6787;margin-bottom:10px;max-width:760px;">'
            . $this->escapeHtml((string) __('Le test manuel est reserve au diagnostic. L execution forcee consomme de vraies donnees et doit rester exceptionnelle.'))
            . '</div>';
        $html .= '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;">';
        $html .= $this->renderStateBadge((string) __('Test manuel'), $debugMode);
        $html .= $this->renderStateBadge((string) __('Execution forcee'), $debugAdvanced);
        $html .= '</div>';

        if (!$debugMode && !$debugAdvanced) {
            $html .= '<div style="padding:12px;border-radius:8px;border:1px solid #d6e4f5;background:#f4f8fd;color:#375a7f;margin-bottom:10px;">'
                . $this->escapeHtml((string) __('Les modes debug sont desactives, ce qui est normal en production. Activez-les seulement le temps d un diagnostic court.'))
                . '</div>';
        } elseif ($debugAdvanced) {
            $html .= '<div style="padding:12px;border-radius:8px;border:1px solid #f1d38a;background:#fff7df;color:#8a6112;margin-bottom:10px;">'
                . $this->escapeHtml((string) __('Le mode debug avance est actif. Pensez a le desactiver apres verification pour eviter toute execution manuelle intempestive.'))
                . '</div>';
        }

        $html .= '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px;">';
        if ($debugMode) {
            $html .= '<button type="button" id="' . $testButtonId . '" class="action-default"><span>' . $this->escapeHtml((string) __('Tester le cron')) . '</span></button>';
        } else {
            $html .= '<span style="color:#6b7280;">' . $this->escapeHtml((string) __('Activez "mode debug cron" pour autoriser un test manuel securise.')) . '</span>';
        }
        if ($debugAdvanced) {
            $html .= '<button type="button" id="' . $forceButtonId . '" class="action-default scalable primary"><span>' . $this->escapeHtml((string) __('Forcer l execution')) . '</span></button>';
        }
        $html .= '</div>';
        $html .= '<div id="' . $resultId . '" style="display:none;padding:12px;border-radius:8px;margin-bottom:10px;"></div>';
        $html .= '<details id="' . $payloadId . '" style="display:none;">';
        $html .= '<summary style="cursor:pointer;">' . $this->escapeHtml((string) __('Retour JSON minimal')) . '</summary>';
        $html .= '<pre id="' . $jsonId . '" style="margin-top:8px;padding:10px;background:#f8f8f8;border:1px solid #d6d6d6;max-height:260px;overflow:auto;white-space:pre-wrap;"></pre>';
        $html .= '</details>';
        $html .= '</div>';

        $html .= '<script>
require(["jquery"], function ($) {
    var requestUrl = ' . json_encode($adminUrl, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ';
    var formKey = window.FORM_KEY || null;
    var messages = ' . $messages . ';
    var $serverCronPanel = $("#' . $serverCronPanelId . '");
    var $executionModeSelect = $("select[name=\"groups[execution][fields][execution_mode][value]\"]");

    function refreshServerCronVisibility() {
        if (!$serverCronPanel.length || !$executionModeSelect.length) {
            return;
        }

        $serverCronPanel.toggle($executionModeSelect.val() === "cron");
    }

    $executionModeSelect.on("change", refreshServerCronVisibility);
    refreshServerCronVisibility();

    function renderResult(payload) {
        var ok = payload && payload.success === true;
        var $box = $("#' . $resultId . '");
        var $payload = $("#' . $payloadId . '");
        var $json = $("#' . $jsonId . '");

        $box.show().css({
            border: ok ? "1px solid #8bcf8b" : "1px solid #e7a7ad",
            background: ok ? "#dff5df" : "#fde7e9",
            color: ok ? "#176b2c" : "#a12622"
        }).text(payload && payload.message ? payload.message : (ok ? messages.ok : messages.failed));

        var minimal = {
            success: ok,
            status: payload && payload.status ? payload.status : null,
            processed_events: payload && payload.processed_events ? payload.processed_events : 0,
            failed_events: payload && payload.failed_events ? payload.failed_events : 0,
            reason: payload && payload.reason ? payload.reason : null,
            duration_ms: payload && payload.duration_ms ? payload.duration_ms : null,
            mode: payload && payload.mode ? payload.mode : null,
            timestamp: payload && payload.timestamp ? payload.timestamp : null
        };

        $payload.show();
        $json.text(JSON.stringify(minimal, null, 2));
    }

    function runCron(mode) {
        renderResult({
            success: false,
            message: messages.running,
            status: null
        });

        $.ajax({
            url: requestUrl,
            type: "POST",
            dataType: "json",
            data: {
                form_key: formKey,
                mode: mode
            }
        }).done(function (response) {
            renderResult(response || {});
        }).fail(function (xhr) {
            renderResult({
                success: false,
                message: messages.httpError,
                status: xhr.status || 0,
                reason: xhr.statusText || ""
            });
        });
    }

    $("#' . $testButtonId . '").on("click", function () {
        runCron("test");
    });

    $("#' . $forceButtonId . '").on("click", function () {
        if (window.confirm(messages.forceConfirm)) {
            runCron("force");
        }
    });
});
</script>';
        $html .= '</div>';

        return $html;
    }

    private function buildSecureCronUrl(int $storeId, string $token, string $apiKey): string
    {
        $store = $this->storeManager->getStore($storeId);
        $timestamp = time();
        $nonce = bin2hex(random_bytes(8));
        $signature = hash_hmac('sha256', $timestamp . '.' . $nonce . '.' . $token, $apiKey);

        return rtrim($store->getBaseUrl(UrlInterface::URL_TYPE_WEB), '/') . '/neurocheckout/cron/run?' . http_build_query([
            'store_id' => $storeId,
            '___store' => $store->getCode(),
            'token' => $token,
            'ts' => $timestamp,
            'nonce' => $nonce,
            'sig' => $signature,
        ]);
    }

    private function resolveHealthColor(int $score): string
    {
        if ($score >= 80) {
            return '#2d8a3b';
        }

        if ($score >= 50) {
            return '#b76b00';
        }

        return '#b92c28';
    }

    private function renderInfoBox(string $title, string $bodyHtml, string $caption): string
    {
        return '<div style="padding:12px;border-radius:8px;background:#f7f9fc;border:1px solid #e2e8f3;">'
            . '<strong>' . $this->escapeHtml($title) . ' :</strong><br>'
            . $bodyHtml
            . '<p style="margin:8px 0 0;color:#5c6787;">' . $this->escapeHtml($caption) . '</p>'
            . '</div>';
    }

    private function renderStateBadge(string $label, bool $enabled): string
    {
        $background = $enabled ? '#edf9f0' : '#f3f4f6';
        $border = $enabled ? '#b7dfc2' : '#d4d8e1';
        $color = $enabled ? '#1d6c37' : '#5f6b85';
        $stateLabel = $enabled ? (string) __('actif') : (string) __('desactive');

        return '<span style="display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;border:1px solid ' . $border . ';background:' . $background . ';color:' . $color . ';font-weight:700;">'
            . $this->escapeHtml($label)
            . ' - '
            . $this->escapeHtml($stateLabel)
            . '</span>';
    }

    private function translateHealthStatus(string $status): string
    {
        return match ($status) {
            'healthy' => (string) __('etat sain'),
            'degraded' => (string) __('degrade'),
            'critical' => (string) __('critique'),
            default => (string) __('inconnu'),
        };
    }

    private function translateExecutionMode(string $mode): string
    {
        return match ($mode) {
            'cron' => (string) __('Mode cron serveur (manuel)'),
            default => (string) __('Mode cron dans le module'),
        };
    }
}
