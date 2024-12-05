<?php

declare(strict_types=1);

namespace Alecto\Contract;

interface LoggerInterface
{
    public function log(string $message, array $context = []): void;
}
