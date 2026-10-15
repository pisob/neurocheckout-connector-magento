<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Api;

interface CartRestoreManagementInterface
{
    /**
     * @param mixed $payload JSON object; validated before any operation.
     * @return mixed[]
     */
    public function execute($payload = []): array;
}
