# PHP Circuit Breaker Library

A robust PHP implementation of the Circuit Breaker pattern with type safety, metrics tracking, and configurable behavior.

In the depths of Greek mythology, Alecto, one of the three Erinyes or Furies, emerges as a formidable figure. These divine beings were born from the blood of the castrated Uranus, embodying the relentless pursuit of vengeance. Alecto, in particular, is known for her unwavering determination and relentless pursuit of justice.

Her story offers a powerful metaphor for a circuit breaker pattern in programming. Just as Alecto intervenes to stop a cycle of violence and retribution, a circuit breaker interrupts a process to prevent it from spiraling out of control. In the realm of software, this might mean stopping an infinite loop, halting a runaway process, or preventing a system from crashing.

By drawing inspiration from Alecto, we can design circuit breaker patterns that are:

- Swift and decisive: Like the Erinyes, a circuit breaker must act quickly to prevent further damage.
- Relentless: Once activated, a circuit breaker should remain in effect until the underlying issue is resolved.
- Just: A circuit breaker should be used judiciously, only when necessary to protect the system.

## 🌟 Features

- Type-safe implementation using PHP 8.1+
- Configurable failure and success thresholds
- Operation timeout handling
- Fallback mechanism support
- Metrics tracking
- State transition management
- PSR-3 compatible logging support

## 📦 Installation

Install via Composer:

```bash
composer require cmatosbc/circuit-breaker
```

Requirements:
- PHP 8.1 or higher
- `ext-pcntl` extension
- `ext-posix` extension

## 🚀 Quick Start

```php
use Circuit\CircuitBreaker;
use Circuit\Config\CircuitBreakerConfig;
use Circuit\Service\TimeoutExecutor;

// Create configuration
$config = new CircuitBreakerConfig(
    failureThreshold: 3,    // Open circuit after 3 failures
    successThreshold: 2,    // Close circuit after 2 successes
    resetTimeout: 10,       // Wait 10 seconds before attempting recovery
    operationTimeout: 5     // Timeout operations after 5 seconds
);

// Initialize circuit breaker
$circuitBreaker = new CircuitBreaker(
    config: $config,
    timeoutExecutor: new TimeoutExecutor()
);

// Use the circuit breaker
try {
    $result = $circuitBreaker->call(
        callback: function() {
            return someRiskyOperation();
        },
        fallback: function() {
            return "Fallback response";
        }
    );
} catch (\Exception $e) {
    // Handle exception
}
```

## 🔄 Circuit States

The circuit breaker operates in three states:

### CLOSED (Normal Operation)
- All requests are allowed through
- Failures are counted
- When failures reach `failureThreshold`, transitions to OPEN

### OPEN (Failure Prevention)
- All requests are immediately rejected
- After `resetTimeout` seconds, transitions to HALF-OPEN
- Supports fallback responses during rejection

### HALF-OPEN (Recovery Attempt)
- Limited requests are allowed through
- Successes are counted
- After `successThreshold` successes, transitions to CLOSED
- Any failure returns to OPEN state

## 📊 Metrics Tracking

The circuit breaker tracks key metrics:

```php
$metrics = $circuitBreaker->getMetrics();

// Available metrics:
$metrics['successes'];   // Successful operations count
$metrics['failures'];    // Failed operations count
$metrics['rejections']; // Rejected operations count (when circuit is open)
```

## 🎯 Real-World Examples

### 1. API Client Protection

```php
class ApiClient
{
    public function __construct(
        private CircuitBreaker $circuitBreaker,
        private HttpClient $httpClient
    ) {}

    public function fetchUserData(int $userId): array
    {
        return $this->circuitBreaker->call(
            callback: function() use ($userId) {
                $response = $this->httpClient->get("/users/{$userId}");
                return $response->toArray();
            },
            fallback: function() use ($userId) {
                return $this->getCachedUserData($userId);
            }
        );
    }
}
```

### 2. Database Query Protection

```php
class DatabaseRepository
{
    public function __construct(
        private CircuitBreaker $circuitBreaker,
        private PDO $pdo
    ) {}

    public function executeQuery(string $query, array $params = []): array
    {
        return $this->circuitBreaker->call(
            callback: function() use ($query, $params) {
                $stmt = $this->pdo->prepare($query);
                $stmt->execute($params);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            },
            fallback: function() {
                return $this->getFromCache() ?? [];
            }
        );
    }
}
```

### 3. Microservice Communication

```php
class PaymentService
{
    public function __construct(
        private CircuitBreaker $circuitBreaker,
        private PaymentGateway $gateway
    ) {}

    public function processPayment(Order $order): PaymentResult
    {
        return $this->circuitBreaker->call(
            callback: function() use ($order) {
                return $this->gateway->processPayment($order);
            },
            fallback: function() use ($order) {
                $this->queueForRetry($order);
                return new PaymentResult(status: 'QUEUED');
            }
        );
    }
}
```

## 🔌 Framework Integration

### Symfony Integration

```php
// config/services.yaml
services:
    Circuit\Config\CircuitBreakerConfig:
        arguments:
            $failureThreshold: '%env(int:CIRCUIT_FAILURE_THRESHOLD)%'
            $successThreshold: '%env(int:CIRCUIT_SUCCESS_THRESHOLD)%'
            $resetTimeout: '%env(int:CIRCUIT_RESET_TIMEOUT)%'
            $operationTimeout: '%env(int:CIRCUIT_OPERATION_TIMEOUT)%'

    Circuit\Service\TimeoutExecutor: ~

    Circuit\CircuitBreaker:
        arguments:
            $config: '@Circuit\Config\CircuitBreakerConfig'
            $timeoutExecutor: '@Circuit\Service\TimeoutExecutor'
            $logger: '@logger'

    App\Service\ExternalApiClient:
        arguments:
            $circuitBreaker: '@Circuit\CircuitBreaker'
```

```php
// src/Service/ExternalApiClient.php
namespace App\Service;

use Circuit\CircuitBreaker;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ExternalApiClient
{
    public function __construct(
        private readonly CircuitBreaker $circuitBreaker,
        private readonly HttpClientInterface $client
    ) {}

    public function fetchData(): array
    {
        return $this->circuitBreaker->call(
            callback: function() {
                $response = $this->client->request('GET', 'https://api.example.com/data');
                return $response->toArray();
            },
            fallback: function() {
                return $this->getCachedData();
            }
        );
    }
}
```

### Laravel Integration

```php
// app/Providers/CircuitBreakerServiceProvider.php
namespace App\Providers;

use Circuit\CircuitBreaker;
use Circuit\Config\CircuitBreakerConfig;
use Circuit\Service\TimeoutExecutor;
use Illuminate\Support\ServiceProvider;

class CircuitBreakerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CircuitBreakerConfig::class, function ($app) {
            return new CircuitBreakerConfig(
                failureThreshold: config('services.circuit.failure_threshold', 3),
                successThreshold: config('services.circuit.success_threshold', 2),
                resetTimeout: config('services.circuit.reset_timeout', 30),
                operationTimeout: config('services.circuit.operation_timeout', 5)
            );
        });

        $this->app->singleton(TimeoutExecutor::class);

        $this->app->singleton(CircuitBreaker::class, function ($app) {
            return new CircuitBreaker(
                config: $app->make(CircuitBreakerConfig::class),
                timeoutExecutor: $app->make(TimeoutExecutor::class),
                logger: $app->make('log')
            );
        });
    }
}
```

```php
// config/services.php
return [
    'circuit' => [
        'failure_threshold' => env('CIRCUIT_FAILURE_THRESHOLD', 3),
        'success_threshold' => env('CIRCUIT_SUCCESS_THRESHOLD', 2),
        'reset_timeout' => env('CIRCUIT_RESET_TIMEOUT', 30),
        'operation_timeout' => env('CIRCUIT_OPERATION_TIMEOUT', 5),
    ],
];
```

```php
// app/Services/PaymentGateway.php
namespace App\Services;

use Circuit\CircuitBreaker;

class PaymentGateway
{
    public function __construct(
        private readonly CircuitBreaker $circuitBreaker
    ) {}

    public function processPayment(array $paymentData): array
    {
        return $this->circuitBreaker->call(
            callback: function() use ($paymentData) {
                return $this->gateway->charge($paymentData);
            },
            fallback: function() use ($paymentData) {
                $this->queuePaymentForRetry($paymentData);
                return ['status' => 'queued'];
            }
        );
    }
}
```

## ⚙️ Advanced Configuration

### Custom Timeout Handling

```php
$config = new CircuitBreakerConfig(
    failureThreshold: 5,
    successThreshold: 3,
    resetTimeout: 30,
    operationTimeout: 2  // Short timeout for time-sensitive operations
);
```

### Adding Logging

```php
use Psr\Log\LoggerInterface;

$circuitBreaker = new CircuitBreaker(
    config: $config,
    timeoutExecutor: new TimeoutExecutor(),
    logger: $psrLogger
);
```

## 🧪 Testing

Run the test suite:

```bash
composer test
```

The tests demonstrate:
- State transition behavior
- Failure counting
- Success threshold management
- Timeout handling
- Metrics accuracy

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch
3. Write tests for new features
4. Submit a pull request

## 📄 License

This library is licensed under the GNU General Public License v3.0 - see the LICENSE file for details.
