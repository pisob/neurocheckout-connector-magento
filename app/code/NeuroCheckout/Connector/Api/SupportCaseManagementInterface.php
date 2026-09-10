<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Api;

interface SupportCaseManagementInterface
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function execute(array $payload = []): array;
}
