<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use NeuroCheckout\Connector\Model\Adminhtml\ScopeResolver;
use NeuroCheckout\Connector\Model\Config;

class GeneralStatusNotice extends Field
{
    private Config $config;
    private ScopeResolver $scopeResolver;

    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        Config $config,
        ScopeResolver $scopeResolver,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->config = $config;
        $this->scopeResolver = $scopeResolver;
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $scopeParams = $this->scopeResolver->getScopeParams();
        $storeId = $this->scopeResolver->resolveStoreId($scopeParams);
        $apiIssues = $this->config->getApiConfigurationIssues($storeId);
        $iaIssues = $this->config->getIaConfigurationIssues($storeId);
        $apiReady = $apiIssues === [];
        $iaReady = $iaIssues === [];
        $apiGateReady = $this->config->isApiTestValidationCurrent($storeId);
        $validatedAt = $this->config->getApiTestValidatedAt($storeId);
        $validatedAtDisplay = $validatedAt > 0 ? date('Y-m-d H:i:s', $validatedAt) : '';

        $html = '<div style="display:flex;flex-direction:column;gap:10px;">';
        $html .= '<div style="padding:12px 14px;border-radius:8px;border:1px solid ' . ($apiReady ? '#b7dfc2' : '#f1d38a') . ';background:' . ($apiReady ? '#edf9f0' : '#fff7df') . ';color:' . ($apiReady ? '#1d6c37' : '#8a6112') . ';">';
        if ($apiReady) {
            $html .= $this->escapeHtml((string) __('La configuration API obligatoire est complete.'));
        } else {
            $html .= $this->escapeHtml((string) __('Avant de tester l API ou d envoyer des evenements, completez d abord la configuration generale obligatoire.'));
            $html .= '<ul style="margin:8px 0 0 18px;padding:0;">';
            foreach ($apiIssues as $issue) {
                $html .= '<li>' . $this->escapeHtml($issue) . '</li>';
            }
            $html .= '</ul>';
        }
        $html .= '</div>';

        $html .= '<div style="padding:12px 14px;border-radius:8px;border:1px solid ' . ($iaReady ? '#b7dfc2' : '#f1d38a') . ';background:' . ($iaReady ? '#edf9f0' : '#fff7df') . ';color:' . ($iaReady ? '#1d6c37' : '#8a6112') . ';">';
        if ($iaReady) {
            $html .= $this->escapeHtml((string) __('Les parametres IA obligatoires sont complets.'));
        } else {
            $html .= $this->escapeHtml((string) __('Avant de tester l API ou d envoyer des evenements, completez d abord les champs obligatoires dans la section Parametres IA.'));
            $html .= '<ul style="margin:8px 0 0 18px;padding:0;">';
            foreach ($iaIssues as $issue) {
                $html .= '<li>' . $this->escapeHtml($issue) . '</li>';
            }
            $html .= '</ul>';
        }
        $html .= '</div>';

        $html .= '<div style="padding:12px 14px;border-radius:8px;border:1px solid ' . ($apiGateReady ? '#b7dfc2' : '#efb0b8') . ';background:' . ($apiGateReady ? '#edf9f0' : '#fff0f2') . ';color:' . ($apiGateReady ? '#1d6c37' : '#99233a') . ';">';
        $html .= $apiGateReady
            ? $this->escapeHtml((string) __('Test API valide. Le cron et le traitement des evenements sont autorises.'))
            : $this->escapeHtml((string) __('Action requise: sauvegardez la configuration, puis cliquez sur "Tester l API" avec succes pour activer le cron et le traitement des evenements.'));
        if ($apiGateReady && $validatedAtDisplay !== '') {
            $html .= '<br><small>' . $this->escapeHtml((string) __('Derniere validation: %1', $validatedAtDisplay)) . '</small>';
        }
        $html .= '</div>';
        if ($apiGateReady && \NeuroCheckout\Connector\Model\Monitoring\SchedulerHealth::needsAttention(
            $this->config->getInt(Config::XML_PATH_LAST_RUN, $storeId),
            $validatedAt,
            $this->config->getInt(Config::XML_PATH_CRON_INTERVAL_SECONDS, $storeId),
            time()
        )) {
            $html .= '<div class="message message-warning">'
                . $this->escapeHtml((string) __('No recent connector queue run was detected. Check Magento cron or your configured connector server cron. API validation alone does not start background synchronization.'))
                . '</div>';
        }
        $html .= '</div>';

        return $html;
    }
}
