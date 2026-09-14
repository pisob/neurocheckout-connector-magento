<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use NeuroCheckout\Connector\Model\Adminhtml\ScopeResolver;
use NeuroCheckout\Connector\Model\Monitoring\HealthMonitor;
use NeuroCheckout\Connector\Model\Repository\CronLogRepository;
use NeuroCheckout\Connector\Model\Repository\RequestRateLimitRepository;
use NeuroCheckout\Connector\Model\Repository\RecoveryAuditRepository;
use NeuroCheckout\Connector\Model\Config;

class MonitoringPanel extends Field
{
    private ScopeResolver $scopeResolver;
    private HealthMonitor $healthMonitor;
    private CronLogRepository $cronLogRepository;
    private RecoveryAuditRepository $recoveryAuditRepository;
    private RequestRateLimitRepository $requestRateLimitRepository;
    private Config $connectorConfig;

    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        ScopeResolver $scopeResolver,
        HealthMonitor $healthMonitor,
        CronLogRepository $cronLogRepository,
        RecoveryAuditRepository $recoveryAuditRepository,
        RequestRateLimitRepository $requestRateLimitRepository,
        Config $connectorConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->scopeResolver = $scopeResolver;
        $this->healthMonitor = $healthMonitor;
        $this->cronLogRepository = $cronLogRepository;
        $this->recoveryAuditRepository = $recoveryAuditRepository;
        $this->requestRateLimitRepository = $requestRateLimitRepository;
        $this->connectorConfig = $connectorConfig;
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $storeId = $this->scopeResolver->getEffectiveStoreId();
        $health = $this->healthMonitor->getHealthReport($storeId);
        $logs = $this->cronLogRepository->getLastLogs($storeId, 5);
        $recentCoupons = $this->recoveryAuditRepository->getRecentCoupons($storeId, 5);
        $recentRecoveries = $this->recoveryAuditRepository->getRecentRecoveryTokens($storeId, 5);
        $recentSecurityFailures = $this->requestRateLimitRepository->getRecentFailures($storeId, 5);
        $healthColor = $this->resolveHealthColor((int) ($health['score'] ?? 0));
        $breakerState = (string) ($health['circuit_state'] ?? 'closed');
        $breakerColor = match ($breakerState) {
            'open' => '#b92c28',
            'half_open' => '#b76b00',
            default => '#2d8a3b',
        };

        $html = '<div style="display:flex;flex-direction:column;gap:14px;">';
        $html .= $this->renderConnectorUpdate($storeId);
        $html .= '<div style="padding:14px;border-radius:10px;border:1px solid #dbe4f0;background:#ffffff;">';
        $html .= '<div style="margin-bottom:12px;"><span style="display:inline-block;padding:6px 12px;border-radius:999px;color:#fff;background:' . $healthColor . ';font-weight:700;">'
            . strtoupper($this->escapeHtml($this->translateHealthStatus((string) ($health['status'] ?? 'unknown'))))
            . ' - '
            . (int) ($health['score'] ?? 0)
            . '/100</span></div>';
        $html .= '<div style="width:100%;height:16px;background:#e9edf4;border-radius:999px;overflow:hidden;margin-bottom:14px;"><div style="height:100%;width:' . max(0, min(100, (int) ($health['score'] ?? 0))) . '%;background:' . $healthColor . ';"></div></div>';
        $html .= '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;">';
        $html .= $this->renderMetric((string) __('Queue'), (string) (int) ($health['backlog'] ?? 0));
        $html .= $this->renderMetric((string) __('Taux d erreur'), (string) ($health['error_rate'] ?? 0) . '%');
        $html .= $this->renderMetric((string) __('Latence moyenne'), (string) (int) ($health['avg_latency'] ?? 0) . ' ms');
        $html .= $this->renderMetric((string) __('Circuit breaker'), $this->translateCircuitState($breakerState), $breakerColor);
        $html .= '</div>';
        if ($breakerState === 'open' && (int) ($health['circuit_open_seconds'] ?? 0) > 0) {
            $html .= '<div style="margin-top:10px;color:#6b7280;">'
                . $this->escapeHtml((string) __('Circuit ouvert depuis %1 secondes.', (int) ($health['circuit_open_seconds'] ?? 0)))
                . '</div>';
        }
        $html .= '</div>';

        $html .= '<div style="padding:14px;border-radius:10px;border:1px solid #dbe4f0;background:#ffffff;">';
        $html .= '<div style="font-weight:700;margin-bottom:10px;">' . $this->escapeHtml((string) __('Derniers runs cron')) . '</div>';
        if ($logs) {
            $html .= '<div style="overflow:auto;"><table style="width:100%;border-collapse:collapse;">';
            $html .= '<thead><tr>';
            foreach ([
                (string) __('Execute a'),
                (string) __('Statut'),
                (string) __('Evenements'),
                (string) __('Latence'),
                (string) __('IP'),
                (string) __('Message'),
            ] as $label) {
                $html .= '<th style="text-align:left;padding:8px;border-bottom:1px solid #e5e7eb;background:#f8fafc;">' . $this->escapeHtml($label) . '</th>';
            }
            $html .= '</tr></thead><tbody>';

            foreach ($logs as $log) {
                $status = (string) ($log['status'] ?? '');
                $statusColor = $status === 'success' ? '#2d8a3b' : ($status === 'error' ? '#b92c28' : '#6b7280');
                $html .= '<tr>';
                $html .= '<td style="padding:8px;border-bottom:1px solid #f0f2f5;">' . $this->escapeHtml((string) ($log['executed_at'] ?? '')) . '</td>';
                $html .= '<td style="padding:8px;border-bottom:1px solid #f0f2f5;color:' . $statusColor . ';font-weight:700;">' . $this->escapeHtml($this->translateCronLogStatus($status)) . '</td>';
                $html .= '<td style="padding:8px;border-bottom:1px solid #f0f2f5;">' . (int) ($log['processed_events'] ?? 0) . '</td>';
                $html .= '<td style="padding:8px;border-bottom:1px solid #f0f2f5;">' . (int) ($log['execution_time_ms'] ?? 0) . ' ms</td>';
                $html .= '<td style="padding:8px;border-bottom:1px solid #f0f2f5;">' . $this->escapeHtml((string) ($log['ip_address'] ?? '-')) . '</td>';
                $html .= '<td style="padding:8px;border-bottom:1px solid #f0f2f5;">' . $this->escapeHtml((string) ($log['error_message'] ?? '-')) . '</td>';
                $html .= '</tr>';
            }

            $html .= '</tbody></table></div>';
        } else {
            $html .= '<div style="padding:12px;border-radius:8px;border:1px solid #d6e4f5;background:#f4f8fd;color:#375a7f;">'
                . $this->escapeHtml((string) __('Aucun run cron enregistre pour le moment.'))
                . '</div>';
        }
        $html .= '</div>';
        $html .= '<div style="padding:14px;border-radius:10px;border:1px solid #dbe4f0;background:#ffffff;">';
        $html .= '<div style="font-weight:700;margin-bottom:10px;">' . $this->escapeHtml((string) __('Derniers coupons NeuroCheckout')) . '</div>';
        $html .= $this->renderAuditTable(
            $recentCoupons,
            [
                'created_at' => (string) __('Cree le'),
                'customer_email' => (string) __('Email'),
                'cart_id' => (string) __('Panier'),
                'coupon_code' => (string) __('Coupon'),
                'discount_percent' => (string) __('Remise'),
                'expires_at' => (string) __('Expire le'),
            ],
            (string) __('Aucun coupon NeuroCheckout recent.')
        );
        $html .= '</div>';

        $html .= '<div style="padding:14px;border-radius:10px;border:1px solid #dbe4f0;background:#ffffff;">';
        $html .= '<div style="font-weight:700;margin-bottom:10px;">' . $this->escapeHtml((string) __('Audit securite recent')) . '</div>';
        $html .= $this->renderAuditTable(
            $recentSecurityFailures,
            [
                'updated_at' => (string) __('Mis a jour le'),
                'endpoint' => (string) __('Endpoint'),
                'client_ip' => (string) __('IP'),
                'attempt_count' => (string) __('Essais'),
                'status' => (string) __('Statut'),
                'last_error' => (string) __('Dernier refus'),
                'blocked_until' => (string) __('Bloque jusqu a'),
            ],
            (string) __('Aucun refus securite recent.')
        );
        $html .= '</div>';

        $html .= '<div style="padding:14px;border-radius:10px;border:1px solid #dbe4f0;background:#ffffff;">';
        $html .= '<div style="font-weight:700;margin-bottom:10px;">' . $this->escapeHtml((string) __('Derniers recovery links')) . '</div>';
        $html .= $this->renderAuditTable(
            $recentRecoveries,
            [
                'created_at' => (string) __('Cree le'),
                'customer_email' => (string) __('Email'),
                'cart_id' => (string) __('Panier'),
                'coupon_code' => (string) __('Coupon'),
                'status' => (string) __('Statut'),
                'expires_at' => (string) __('Expire le'),
            ],
            (string) __('Aucun recovery link recent.')
        );
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    private function renderConnectorUpdate(int $storeId): string
    {
        $status = $this->connectorConfig->getString(Config::XML_PATH_UPDATE_STATUS, $storeId);
        if (!in_array($status, ['available', 'required', 'blocked'], true)) {
            return '';
        }
        $latest = $this->escapeHtml($this->connectorConfig->getString(Config::XML_PATH_UPDATE_LATEST_VERSION, $storeId));
        $url = $this->connectorConfig->getString(Config::XML_PATH_UPDATE_RELEASE_URL, $storeId);
        $color = $status === 'available' ? '#b76b00' : '#b92c28';
        return '<div style="padding:14px;border:1px solid ' . $color . ';background:#fff;border-radius:8px;">'
            . '<strong>NeuroCheckout Connector ' . $latest . ' is available.</strong> '
            . 'Back up the store, download the official release and update the existing Composer package. Do not remove the module; configuration and data are preserved. '
            . '<a href="' . $this->escapeUrl($url) . '" target="_blank" rel="noopener noreferrer">Download official update</a></div>';
    }

    private function renderMetric(string $label, string $value, string $valueColor = '#1f2947'): string
    {
        return '<div style="padding:12px;border-radius:8px;border:1px solid #e3e9f3;background:#fcfdff;">'
            . '<div style="font-size:12px;text-transform:uppercase;letter-spacing:0.05em;color:#67749a;font-weight:700;margin-bottom:6px;">' . $this->escapeHtml($label) . '</div>'
            . '<div style="font-size:18px;font-weight:700;color:' . $valueColor . ';">' . $this->escapeHtml($value) . '</div>'
            . '</div>';
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

    private function translateHealthStatus(string $status): string
    {
        return match ($status) {
            'healthy' => (string) __('etat sain'),
            'degraded' => (string) __('degrade'),
            'critical' => (string) __('critique'),
            default => (string) __('inconnu'),
        };
    }

    private function translateCircuitState(string $state): string
    {
        return match ($state) {
            'open' => (string) __('ouvert'),
            'half_open' => (string) __('semi-ouvert'),
            default => (string) __('ferme'),
        };
    }

    private function translateCronLogStatus(string $status): string
    {
        return match ($status) {
            'success' => (string) __('succes'),
            'error' => (string) __('erreur'),
            default => $status !== '' ? $status : (string) __('inconnu'),
        };
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, string> $columns
     */
    private function renderAuditTable(array $rows, array $columns, string $emptyMessage): string
    {
        if (!$rows) {
            return '<div style="padding:12px;border-radius:8px;border:1px solid #d6e4f5;background:#f4f8fd;color:#375a7f;">'
                . $this->escapeHtml($emptyMessage)
                . '</div>';
        }

        $html = '<div style="overflow:auto;"><table style="width:100%;border-collapse:collapse;">';
        $html .= '<thead><tr>';
        foreach ($columns as $label) {
            $html .= '<th style="text-align:left;padding:8px;border-bottom:1px solid #e5e7eb;background:#f8fafc;">'
                . $this->escapeHtml($label)
                . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach (array_keys($columns) as $column) {
                $value = (string) ($row[$column] ?? '-');
                if ($column === 'discount_percent' && $value !== '-') {
                    $value .= '%';
                }
                if ($column === 'status') {
                    $value = $this->translateStatus($value);
                }

                $html .= '<td style="padding:8px;border-bottom:1px solid #f0f2f5;">' . $this->escapeHtml($value !== '' ? $value : '-') . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div>';

        return $html;
    }

    private function translateStatus(string $status): string
    {
        return match ($status) {
            'used' => (string) __('utilise'),
            'expired' => (string) __('expire'),
            'blocked' => (string) __('bloque'),
            'watch' => (string) __('surveille'),
            default => (string) __('pret'),
        };
    }
}
