<?php

declare(strict_types=1);

namespace Circuit\Tests;

use Circuit\CircuitBreaker;
use Circuit\Config\CircuitBreakerConfig;
use Circuit\Exception\CircuitOpenException;
use Circuit\Service\TimeoutExecutor;
use Circuit\Enum\CircuitState;
use PHPUnit\Framework\TestCase;

class CircuitBreakerTest extends TestCase
{
    private CircuitBreaker $circuitBreaker;
    private CircuitBreakerConfig $config;
    private TimeoutExecutor $timeoutExecutor;

    protected function setUp(): void
    {
        $this->config = new CircuitBreakerConfig(
            failureThreshold: 3,
            successThreshold: 2,
            resetTimeout: 5,
            operationTimeout: 2
        );
        $this->timeoutExecutor = new TimeoutExecutor();
        $this->circuitBreaker = new CircuitBreaker(
            $this->config,
            $this->timeoutExecutor
        );
    }

    private function displayCircuitInfo(string $context): void
    {
        $metrics = $this->circuitBreaker->getMetrics();
        $state = $this->circuitBreaker->getState();
        
        echo "\n🔄 Circuit Info ($context):\n";
        echo "├─ State: {$state->value}\n";
        echo "├─ Successes: {$metrics['successes']}\n";
        echo "├─ Failures: {$metrics['failures']}\n";
        echo "└─ Rejections: {$metrics['rejections']}\n";
    }

    /**
     * @test
     */
    public function shouldAllowSuccessfulOperations(): void
    {
        echo "\n🧪 Testing successful operations\n";
        
        // Given a simple operation that always succeeds
        $operation = fn() => 'success';

        // When we execute it through the circuit breaker
        $result = $this->circuitBreaker->call($operation);
        $this->displayCircuitInfo("After successful operation");

        // Then it should complete successfully
        $this->assertEquals('success', $result);
        $this->assertEquals(CircuitState::CLOSED, $this->circuitBreaker->getState());
        $metrics = $this->circuitBreaker->getMetrics();
        $this->assertEquals(1, $metrics['successes']);
        $this->assertEquals(0, $metrics['failures']);
        $this->assertEquals(0, $metrics['rejections']);
    }

    /**
     * @test
     */
    public function shouldOpenCircuitAfterFailureThreshold(): void
    {
        echo "\n🧪 Testing failure threshold\n";
        
        // Given an operation that always fails
        $operation = function () {
            throw new \RuntimeException('Service failed');
        };

        // When we execute it multiple times
        echo "\nExecuting failing operations until threshold...\n";
        $failureCount = 0;
        for ($i = 0; $i < $this->config->getFailureThreshold(); $i++) {
            try {
                $this->circuitBreaker->call($operation);
            } catch (\RuntimeException $e) {
                $failureCount++;
                echo "├─ Attempt " . ($i + 1) . ": Failed\n";
                $this->displayCircuitInfo("After failure " . ($i + 1));
                $this->assertEquals('Service failed', $e->getMessage());
            }
        }

        // Verify failures were counted
        $this->assertEquals($this->config->getFailureThreshold(), $failureCount);
        
        // Then the circuit should be open
        $this->assertEquals(CircuitState::OPEN, $this->circuitBreaker->getState());
        
        // And subsequent calls should be rejected
        try {
            $this->circuitBreaker->call($operation);
            $this->fail('Expected CircuitOpenException was not thrown');
        } catch (CircuitOpenException $e) {
            $metrics = $this->circuitBreaker->getMetrics();
            $this->assertEquals($this->config->getFailureThreshold(), $metrics['failures']);
            $this->assertEquals(1, $metrics['rejections']);
        }
    }

    /**
     * @test
     */
    public function shouldUseFailbackWhenCircuitIsOpen(): void
    {
        echo "\n🧪 Testing fallback behavior\n";
        
        // Given an operation that always fails and a fallback
        $operation = function () {
            throw new \RuntimeException('Service failed');
        };
        $fallback = fn() => 'fallback response';

        // When we fail enough times to open the circuit
        echo "\nOpening the circuit...\n";
        $failureCount = 0;
        for ($i = 0; $i < $this->config->getFailureThreshold(); $i++) {
            try {
                $this->circuitBreaker->call($operation);
            } catch (\RuntimeException $e) {
                $failureCount++;
            }
        }
        
        $this->assertEquals($this->config->getFailureThreshold(), $failureCount);
        $this->assertEquals(CircuitState::OPEN, $this->circuitBreaker->getState());
        $this->displayCircuitInfo("Circuit opened");

        // Then the fallback should be used
        echo "\nTrying fallback...\n";
        $result = $this->circuitBreaker->call($operation, $fallback);
        $this->assertEquals('fallback response', $result);
        
        $metrics = $this->circuitBreaker->getMetrics();
        $this->assertEquals($this->config->getFailureThreshold(), $metrics['failures']);
        $this->assertEquals(1, $metrics['rejections']);
        $this->displayCircuitInfo("After fallback");
    }

    /**
     * @test
     */
    public function shouldTimeoutLongRunningOperations(): void
    {
        echo "\n🧪 Testing timeout behavior\n";
        
        // Given an operation that takes longer than the timeout
        $operation = function () {
            sleep(3); // Operation takes 3 seconds
            return 'too late';
        };

        // When we execute it
        // Then it should throw a timeout exception
        echo "\nExecuting long operation...\n";
        
        $startTime = time();
        try {
            $this->circuitBreaker->call($operation);
            $this->fail('Expected timeout exception was not thrown');
        } catch (\RuntimeException $e) {
            $endTime = time();
            $this->assertLessThanOrEqual($this->config->getOperationTimeout() + 1, $endTime - $startTime);
            $this->assertStringContainsString('Operation timed out', $e->getMessage());
            
            $metrics = $this->circuitBreaker->getMetrics();
            $this->assertEquals(1, $metrics['failures']);
            $this->assertEquals(0, $metrics['successes']);
            
            $this->displayCircuitInfo("After timeout");
        }
    }

    /**
     * @test
     */
    public function shouldAllowRecoveryAfterResetTimeout(): void
    {
        echo "\n🧪 Testing circuit recovery\n";
        
        // Given an operation that initially fails
        $failingOperation = function () {
            throw new \RuntimeException('Service failed');
        };

        // When we fail enough times to open the circuit
        echo "\nOpening the circuit...\n";
        $failureCount = 0;
        for ($i = 0; $i < $this->config->getFailureThreshold(); $i++) {
            try {
                $this->circuitBreaker->call($failingOperation);
            } catch (\RuntimeException $e) {
                $failureCount++;
            }
        }
        
        $this->assertEquals($this->config->getFailureThreshold(), $failureCount);
        $this->assertEquals(CircuitState::OPEN, $this->circuitBreaker->getState());
        $this->displayCircuitInfo("Circuit opened");

        // And we wait for the reset timeout
        echo "\nWaiting for reset timeout ({$this->config->getResetTimeout()} seconds)...\n";
        sleep($this->config->getResetTimeout());

        // And then we have a successful operation
        $successfulOperation = fn() => 'recovered';

        // Then the circuit should allow the successful operation
        $result = $this->circuitBreaker->call($successfulOperation);
        $this->assertEquals('recovered', $result);
        $this->assertEquals(CircuitState::HALF_OPEN, $this->circuitBreaker->getState());
        
        $metrics = $this->circuitBreaker->getMetrics();
        $this->assertEquals(1, $metrics['successes']);
        $this->displayCircuitInfo("After recovery");
    }

    /**
     * @test
     */
    public function shouldCloseCircuitAfterSuccessThreshold(): void
    {
        echo "\n🧪 Testing success threshold\n";
        
        // Given an initially failing operation
        $failingOperation = function () {
            throw new \RuntimeException('Service failed');
        };

        // When we fail enough times to open the circuit
        echo "\nOpening the circuit...\n";
        $failureCount = 0;
        for ($i = 0; $i < $this->config->getFailureThreshold(); $i++) {
            try {
                $this->circuitBreaker->call($failingOperation);
            } catch (\RuntimeException $e) {
                $failureCount++;
            }
        }
        
        $this->assertEquals($this->config->getFailureThreshold(), $failureCount);
        $this->assertEquals(CircuitState::OPEN, $this->circuitBreaker->getState());
        $this->displayCircuitInfo("Circuit opened");

        // And we wait for the reset timeout
        echo "\nWaiting for reset timeout ({$this->config->getResetTimeout()} seconds)...\n";
        sleep($this->config->getResetTimeout());

        // First call after timeout should transition to HALF_OPEN
        $successfulOperation = fn() => 'success';
        $result = $this->circuitBreaker->call($successfulOperation);
        $this->assertEquals('success', $result);
        $this->assertEquals(CircuitState::HALF_OPEN, $this->circuitBreaker->getState());
        $this->displayCircuitInfo("First success - Circuit half-open");

        // Each success in HALF_OPEN state should increment the success counter
        echo "\nExecuting remaining successful operations until threshold...\n";
        $successCount = 1; // Count the first success above
        for ($i = 1; $i < $this->config->getSuccessThreshold() - 1; $i++) {
            $result = $this->circuitBreaker->call($successfulOperation);
            $successCount++;
            echo "├─ Success " . ($i + 1) . "\n";
            $this->assertEquals('success', $result);
            // Should still be HALF_OPEN until we reach the threshold
            $this->assertEquals(CircuitState::HALF_OPEN, $this->circuitBreaker->getState());
            $this->displayCircuitInfo("After success " . ($i + 1));
        }

        // The final success that reaches the threshold should transition to CLOSED
        $result = $this->circuitBreaker->call($successfulOperation);
        $successCount++;
        $this->assertEquals('success', $result);
        $this->assertEquals(CircuitState::CLOSED, $this->circuitBreaker->getState());
        $this->displayCircuitInfo("After reaching success threshold - Circuit closed");

        // Additional calls should maintain CLOSED state
        $result = $this->circuitBreaker->call($successfulOperation);
        $this->assertEquals('success', $result);
        $this->assertEquals(CircuitState::CLOSED, $this->circuitBreaker->getState());
        
        $metrics = $this->circuitBreaker->getMetrics();
        $this->assertEquals($successCount + 1, $metrics['successes']);
        $this->assertEquals($failureCount, $metrics['failures']);
        
        $this->displayCircuitInfo("Final state - Circuit closed");
    }
}
