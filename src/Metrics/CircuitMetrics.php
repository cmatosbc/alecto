<?php

declare(strict_types=1);

namespace Alecto\Metrics;

class CircuitMetrics
{
    private int $failures = 0;
    private int $successes = 0;
    private int $timeouts = 0;
    private int $rejections = 0;

    public function incrementFailures(): void
    {
        $this->failures++;
    }

    public function incrementSuccesses(): void
    {
        $this->successes++;
    }

    public function incrementTimeouts(): void
    {
        $this->timeouts++;
    }

    public function incrementRejections(): void
    {
        $this->rejections++;
    }

    public function toArray(): array
    {
        return [
            'failures' => $this->failures,
            'successes' => $this->successes,
            'timeouts' => $this->timeouts,
            'rejections' => $this->rejections,
        ];
    }
}
