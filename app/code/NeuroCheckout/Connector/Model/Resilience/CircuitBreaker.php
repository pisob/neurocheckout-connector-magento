<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Resilience;

use NeuroCheckout\Connector\Model\Config;
use NeuroCheckout\Connector\Model\Repository\CircuitBreakerRepository;

class CircuitBreaker
{
    private CircuitBreakerRepository $repository;
    private Config $config;

    public function __construct(
        CircuitBreakerRepository $repository,
        Config $config
    ) {
        $this->repository = $repository;
        $this->config = $config;
    }

    public function isAvailable(int $storeId): bool
    {
        $state = $this->repository->getState($storeId);
        $status = (string)($state['state'] ?? CircuitBreakerRepository::STATE_CLOSED);

        if ($status === CircuitBreakerRepository::STATE_CLOSED) {
            return true;
        }

        if ($status === CircuitBreakerRepository::STATE_OPEN) {
            $updatedAt = strtotime((string)($state['updated_at'] ?? '')) ?: 0;
            $cooldown = $this->getCooldownSeconds($storeId);
            if ($updatedAt > 0 && (time() - $updatedAt) >= $cooldown) {
                $this->repository->setState($storeId, CircuitBreakerRepository::STATE_HALF_OPEN, (int)($state['failure_count'] ?? 0));
                return true;
            }

            return false;
        }

        return true;
    }

    public function recordSuccess(int $storeId): void
    {
        $this->repository->setState($storeId, CircuitBreakerRepository::STATE_CLOSED, 0);
    }

    public function recordFailure(int $storeId): void
    {
        $state = $this->repository->getState($storeId);
        $failures = ((int)($state['failure_count'] ?? 0)) + 1;

        if ($failures >= $this->getFailureThreshold($storeId)) {
            $this->repository->setState($storeId, CircuitBreakerRepository::STATE_OPEN, $failures);
            return;
        }

        $this->repository->setState($storeId, CircuitBreakerRepository::STATE_CLOSED, $failures);
    }

    /**
     * @return array<string, mixed>
     */
    public function getStateDetails(int $storeId): array
    {
        return $this->repository->getState($storeId);
    }

    private function getFailureThreshold(int $storeId): int
    {
        $value = $this->config->getInt(Config::XML_PATH_CB_FAILURE_THRESHOLD, $storeId);
        return $value > 0 ? $value : 5;
    }

    private function getCooldownSeconds(int $storeId): int
    {
        $value = $this->config->getInt(Config::XML_PATH_CB_COOLDOWN_SECONDS, $storeId);
        return $value > 0 ? $value : 60;
    }
}
