<?php

declare(strict_types=1);

namespace Circuit\Config;

class CircuitBreakerConfig
{
    public function __construct(
        private readonly int $failureThreshold = 5,
        private readonly int $successThreshold = 2,
        private readonly int $resetTimeout = 30,
        private readonly int $operationTimeout = 5
    ) {}

    public function getFailureThreshold(): int
    {
        return $this->failureThreshold;
    }

    public function getSuccessThreshold(): int
    {
        return $this->successThreshold;
    }

    public function getResetTimeout(): int
    {
        return $this->resetTimeout;
    }

    public function getOperationTimeout(): int
    {
        return $this->operationTimeout;
    }
}
