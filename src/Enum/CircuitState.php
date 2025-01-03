<?php

declare(strict_types=1);

namespace Alecto\Enum;

enum CircuitState: string
{
    case CLOSED = 'CLOSED';
    case OPEN = 'OPEN';
    case HALF_OPEN = 'HALF_OPEN';
}
