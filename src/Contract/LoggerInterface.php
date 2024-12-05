<?php

declare(strict_types=1);

namespace Circuit\Contract;

interface LoggerInterface
{
    public function log(string $message, array $context = []): void;
}
