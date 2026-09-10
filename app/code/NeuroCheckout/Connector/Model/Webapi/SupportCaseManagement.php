<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Webapi;

use Magento\Framework\Webapi\Exception;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Store\Model\StoreManagerInterface;
use NeuroCheckout\Connector\Api\SupportCaseManagementInterface;
use NeuroCheckout\Connector\Model\Config;
use NeuroCheckout\Connector\Model\Http\SecureHttpClient;
use NeuroCheckout\Connector\Model\Security\RequestSecurityValidator;
use Psr\Log\LoggerInterface;

class SupportCaseManagement extends AbstractEndpoint implements SupportCaseManagementInterface
{
    private RequestSecurityValidator $securityValidator;
    private SecureHttpClient $httpClient;
    private Config $config;
    private LoggerInterface $logger;

    public function __construct(
        Request $request,
        StoreManagerInterface $storeManager,
        RequestSecurityValidator $securityValidator,
        SecureHttpClient $httpClient,
        Config $config,
        LoggerInterface $logger
    ) {
        parent::__construct($request, $storeManager);
        $this->securityValidator = $securityValidator;
        $this->httpClient = $httpClient;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function execute(array $payload = []): array
    {
        try {
            $payload = $this->normalizePayload($payload);
            $storeId = $this->resolveStoreId($payload);
            $security = $this->securityValidator->validate($this->getRawBody(), $storeId, 'support-case', true);
            if (empty($security['success'])) {
                return $this->response(false, (int)($security['status'] ?? 403), (string)($security['error'] ?? 'forbidden'));
            }

            $normalized = $this->normalizeSupportPayload($payload, $storeId);
            if ($normalized === null) {
                return $this->response(false, 422, 'message_text is required');
            }

            $result = $this->httpClient->sendSupportEvent($normalized);
            $status = (int)($result['status'] ?? 0);
            if (!empty($result['success'])) {
                return $this->response(true, $status > 0 ? $status : 202, null, [
                    'forwarded' => true,
                ]);
            }

            return $this->response(false, $status > 0 ? $status : 502, (string)($result['error'] ?? 'support_forward_failed'), [
                'forwarded' => false,
            ]);
        } catch (Exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('[NC] Support case endpoint error: ' . $e->getMessage());
            return $this->response(false, 500, 'Internal error');
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function normalizeSupportPayload(array $payload, int $storeId): ?array
    {
        $support = is_array($payload['support'] ?? null) ? $payload['support'] : [];
        $customer = is_array($payload['customer'] ?? null) ? $payload['customer'] : [];
        $order = is_array($payload['order'] ?? null) ? $payload['order'] : [];
        $source = is_array($payload['source'] ?? null) ? $payload['source'] : [];
        $message = is_array($payload['message'] ?? null) ? $payload['message'] : [];

        $messageText = $this->firstText([
            $support['message_text'] ?? null,
            $payload['message_text'] ?? null,
            $message['text'] ?? null,
            $payload['message'] ?? null,
            $payload['body'] ?? null,
        ]);
        if ($messageText === '') {
            return null;
        }

        $caseId = $this->firstText([
            $support['case_id'] ?? null,
            $payload['case_id'] ?? null,
            $payload['ticket_id'] ?? null,
        ]);
        $eventType = strtolower($this->firstText([
            $payload['event_type'] ?? null,
            $support['event_type'] ?? null,
        ]));
        if ($eventType === '') {
            $eventType = $caseId !== '' ? 'support.case_updated' : 'support.case_opened';
        }
        if (!in_array($eventType, ['support.case_opened', 'support.case_updated'], true)) {
            $eventType = 'support.case_opened';
        }

        $shopExternalId = $this->config->getString(Config::XML_PATH_SHOP_EXTERNAL_ID, $storeId);
        $shopId = $shopExternalId !== '' ? $shopExternalId : (string)$storeId;
        $externalCaseId = $this->firstText([
            $support['external_case_id'] ?? null,
            $payload['external_case_id'] ?? null,
            $payload['ticket_reference'] ?? null,
            $caseId,
        ]);

        $metadata = is_array($support['metadata'] ?? null) ? $support['metadata'] : [];
        $metadata['ingress_source'] = $metadata['ingress_source'] ?? 'magento_support_case_endpoint';

        return [
            'event_id' => $this->firstText([$payload['event_id'] ?? null]) ?: $this->deterministicEventId($shopId, $externalCaseId, $messageText),
            'event_type' => $eventType,
            'occurred_at' => $this->firstText([$payload['occurred_at'] ?? null, $support['occurred_at'] ?? null]) ?: gmdate('c'),
            'source' => [
                'platform' => 'magento',
                'shop_id' => $shopId,
                'shop_name' => $this->firstText([$source['shop_name'] ?? null, $source['shop_domain'] ?? null]),
            ],
            'support' => [
                'case_id' => $caseId !== '' ? $caseId : null,
                'external_case_id' => $externalCaseId !== '' ? $externalCaseId : null,
                'message_id' => $this->firstText([$support['message_id'] ?? null, $payload['message_id'] ?? null]) ?: null,
                'message_text' => $messageText,
                'channel' => strtolower($this->firstText([$support['channel'] ?? null, $payload['channel'] ?? null, $source['channel'] ?? null])) ?: 'form',
                'priority_hint' => strtolower($this->firstText([$support['priority_hint'] ?? null, $payload['priority_hint'] ?? null])) ?: 'normal',
                'external_order_id' => $this->firstText([$support['external_order_id'] ?? null, $payload['external_order_id'] ?? null, $payload['order_id'] ?? null, $order['id'] ?? null, $order['increment_id'] ?? null]) ?: null,
                'external_customer_id' => $this->firstText([$support['external_customer_id'] ?? null, $payload['external_customer_id'] ?? null, $payload['customer_id'] ?? null, $customer['id'] ?? null]) ?: null,
                'customer_email' => $this->firstText([$support['customer_email'] ?? null, $payload['customer_email'] ?? null, $customer['email'] ?? null]) ?: null,
                'metadata' => $metadata,
            ],
            'customer' => [
                'id' => $this->firstText([$customer['id'] ?? null, $payload['customer_id'] ?? null]) ?: null,
                'email' => $this->firstText([$customer['email'] ?? null, $payload['customer_email'] ?? null]) ?: null,
            ],
            'order' => [
                'id' => $this->firstText([$order['id'] ?? null, $payload['order_id'] ?? null]) ?: null,
                'increment_id' => $this->firstText([$order['increment_id'] ?? null]) ?: null,
            ],
        ];
    }

    /**
     * @param array<int, mixed> $values
     */
    private function firstText(array $values): string
    {
        foreach ($values as $value) {
            if ($value === null || is_array($value) || is_object($value)) {
                continue;
            }
            $text = trim((string)$value);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    private function deterministicEventId(string $shopId, string $externalCaseId, string $messageText): string
    {
        $hash = md5('support.case|' . $shopId . '|' . $externalCaseId . '|' . $messageText);
        $timeHi = sprintf('%04x', (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x4000);
        $clockSeq = sprintf('%04x', (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000);

        return substr($hash, 0, 8)
            . '-'
            . substr($hash, 8, 4)
            . '-'
            . $timeHi
            . '-'
            . $clockSeq
            . '-'
            . substr($hash, 20, 12);
    }
}
