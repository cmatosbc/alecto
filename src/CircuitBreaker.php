<?php

declare(strict_types=1);

namespace Circuit;

use Circuit\Config\CircuitBreakerConfig;
use Circuit\Contract\LoggerInterface;
use Circuit\Enum\CircuitState;
use Circuit\Exception\CircuitOpenException;
use Circuit\Metrics\CircuitMetrics;
use Circuit\Service\TimeoutExecutor;

class CircuitBreaker
{
    private CircuitState $state;
    private int $failureCount = 0;
    private int $successCount = 0;
    private ?int $lastFailureTime = null;
    private int $lastStateChange;
    private CircuitMetrics $metrics;

    public function __construct(
        private readonly CircuitBreakerConfig $config,
        private readonly TimeoutExecutor $timeoutExecutor,
        private readonly ?LoggerInterface $logger = null
    ) {
        $this->state = CircuitState::CLOSED;
        $this->lastStateChange = time();
        $this->metrics = new CircuitMetrics();
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @param ?callable(): T $fallback
     * @return T
     * @throws \Throwable
     */
    public function call(callable $callback, ?callable $fallback = null): mixed
    {
        $this->updateState();

        if ($this->isOpen()) {
            $this->metrics->incrementRejections();
            $this->log('Circuit is open, request rejected');
            
            if ($fallback !== null) {
                return $fallback();
            }
            throw new CircuitOpenException();
        }

        try {
            $result = $this->timeoutExecutor->executeWithTimeout(
                $callback,
                $this->config->getOperationTimeout()
            );
            $this->onSuccess();
            return $result;
        } catch (\Throwable $e) {
            $this->onFailure($e);
            
            if ($fallback !== null) {
                return $fallback();
            }
            throw $e;
        }
    }

    private function onSuccess(): void
    {
        $this->failureCount = 0;
        $this->metrics->incrementSuccesses();
        
        if ($this->state === CircuitState::HALF_OPEN) {
            $this->successCount++;
            if ($this->successCount >= $this->config->getSuccessThreshold()) {
                $this->transitionTo(CircuitState::CLOSED);
            }
        }
    }

    private function onFailure(\Throwable $e): void
    {
        $this->failureCount++;
        $this->successCount = 0;
        $this->lastFailureTime = time();
        $this->metrics->incrementFailures();
        
        if ($this->failureCount >= $this->config->getFailureThreshold()) {
            $this->transitionTo(CircuitState::OPEN);
        }
        
        $this->log("Circuit breaker failure: " . $e->getMessage());
    }

    private function updateState(): void
    {
        if ($this->state === CircuitState::OPEN && $this->lastFailureTime !== null) {
            if ((time() - $this->lastFailureTime) >= $this->config->getResetTimeout()) {
                $this->transitionTo(CircuitState::HALF_OPEN);
                $this->successCount = 0;
                $this->failureCount = 0;
            }
        }
    }

    private function transitionTo(CircuitState $newState): void
    {
        $oldState = $this->state;
        $this->state = $newState;
        $this->lastStateChange = time();
        
        if ($newState === CircuitState::CLOSED) {
            $this->failureCount = 0;
            $this->successCount = 0;
        } elseif ($newState === CircuitState::HALF_OPEN) {
            $this->successCount = 0;
        }
        
        $this->log("Circuit breaker state changed from {$oldState->value} to {$newState->value}");
    }

    private function isOpen(): bool
    {
        return $this->state === CircuitState::OPEN;
    }

    public function getState(): CircuitState
    {
        return $this->state;
    }

    public function getMetrics(): array
    {
        return $this->metrics->toArray();
    }

    private function log(string $message): void
    {
        if ($this->logger) {
            $this->logger->log($message);
        }
    }
}
