<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Api;

interface CartRestoreManagementInterface
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function execute(array $payload = []): array;
}
