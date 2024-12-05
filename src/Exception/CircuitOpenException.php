<?php

declare(strict_types=1);

namespace Alecto\Exception;

class CircuitOpenException extends \RuntimeException
{
    public function __construct(string $message = "Circuit is open", int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
