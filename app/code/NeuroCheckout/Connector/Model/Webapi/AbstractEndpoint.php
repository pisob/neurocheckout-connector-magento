<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Webapi;

use Magento\Framework\Webapi\Exception;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Store\Model\StoreManagerInterface;

abstract class AbstractEndpoint
{
    protected Request $request;
    protected StoreManagerInterface $storeManager;

    public function __construct(
        Request $request,
        StoreManagerInterface $storeManager
    ) {
        $this->request = $request;
        $this->storeManager = $storeManager;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    protected function normalizePayload(array $payload): array
    {
        $rawBody = (string) $this->request->getContent();
        if ($rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            throw new Exception(
                __('Invalid JSON payload'),
                0,
                Exception::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return $payload;
    }

    protected function getRawBody(): string
    {
        return (string) $this->request->getContent();
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function resolveStoreId(array $payload): int
    {
        $candidates = [
            $payload['store_id'] ?? null,
            $payload['shop_id'] ?? null,
            is_array($payload['source'] ?? null) ? ($payload['source']['shop_id'] ?? null) : null,
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string)($candidate ?? ''));
            if ($value !== '' && ctype_digit($value)) {
                return (int) $value;
            }
        }

        return (int) $this->storeManager->getDefaultStoreView()->getId();
    }

    /**
     * @param array<string, mixed>|null $data
     * @return array<string, mixed>
     */
    protected function response(bool $success, int $status, ?string $error = null, ?array $data = null): array
    {
        $payload = [
            'success' => $success,
            'status' => $status,
            'error' => $error,
            'timestamp' => gmdate('Y-m-d H:i:s'),
        ];

        if ($data !== null) {
            $payload['data'] = $data;
        }

        return $payload;
    }
}
